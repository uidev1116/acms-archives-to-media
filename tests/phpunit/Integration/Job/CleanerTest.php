<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Services\Common\Lock;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 旧データのクリーンアップ（設計書 §10.4）。別名モードで残した旧キー（{fd}@path 系）を、テンプレート移行後に消す。
 */
final class CleanerTest extends A2mTestCase
{
    private function job(int $id): Job
    {
        $job = $this->jobs()->find($id);
        $this->assertNotNull($job);
        return $job;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldDump(): array
    {
        return $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort, field_value', [$this->entryId]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function convertedFieldJob(array $options = []): int
    {
        $this->archives->put('003/h.jpg', 'H');
        $this->archives->put('004/g.jpg', 'G');
        $this->archives->put('005/d.pdf', 'D');
        $this->insertImageField('hero', '003/h.jpg', 1, 'ヒーロー');
        $this->insertImageField('gallery', '004/g.jpg', 1, 'g');
        $this->insertFileField('doc', '005/d.pdf', 'd.pdf');
        $this->insertField('@profile', 'hero', 1);
        $jobId = $this->scannedJob($options + ['targets' => ['field_image', 'field_file']]);
        for ($i = 0; $i < 10; $i++) {
            if ($this->runner()->runChunk($this->job($jobId))->done) {
                return $jobId;
            }
        }
        $this->fail('変換が終わらない');
    }

    #[Test]
    public function 別名モードの旧キーだけを消し_新しいフィールドとグループ定義は残す(): void
    {
        $jobId = $this->convertedFieldJob();

        $res = $this->cleaner()->cleanupChunk($this->job($jobId));

        $this->assertTrue($res->done);
        $this->assertSame([3, 0, 0], [$res->converted, $res->skipped, $res->error]);
        $keys = array_map(static fn (array $r): string => Cast::str($r['field_key']), $this->fieldDump());
        foreach (['hero@path', 'hero@x', 'gallery@alt', 'doc@baseName', 'doc@downloadName'] as $legacy) {
            $this->assertNotContains($legacy, $keys, $legacy);
        }
        foreach (['hero_media', 'hero_media@media', 'gallery_media@media', 'doc_media@media', '@profile'] as $kept) {
            $this->assertContains($kept, $keys, $kept);
        }
        $this->assertSame(['cleanable' => 0, 'cleaned' => 3], $this->items()->cleanupCounts($jobId));
        $this->assertSame(JobStatus::Completed, $this->job($jobId)->status, 'ジョブの状態は変わらない');
    }

    #[Test]
    public function チャンクごとに進み_最後で完了する(): void
    {
        $jobId = $this->convertedFieldJob(['chunkSize' => 2]);
        $cleaner = $this->cleaner();

        $first = $cleaner->cleanupChunk($this->job($jobId));
        $this->assertFalse($first->done);
        $this->assertSame(2, $first->processed);

        $second = $cleaner->cleanupChunk($this->job($jobId));
        $this->assertTrue($second->done);
        $this->assertSame(1, $second->processed);
        $this->assertSame(['cleanable' => 0, 'cleaned' => 3], $this->items()->cleanupCounts($jobId));
    }

    #[Test]
    public function クリーンアップ後でもロールバックで変換前の全行に戻る(): void
    {
        $this->archives->put('003/h.jpg', 'H');
        $this->insertImageField('hero', '003/h.jpg', 1, 'ヒーロー');
        $this->insertField('@profile', 'hero', 1);
        $original = $this->fieldDump();
        $jobId = $this->scannedJob(['targets' => ['field_image']]);
        $this->runner()->runChunk($this->job($jobId));
        $this->cleaner()->cleanupChunk($this->job($jobId));
        $this->assertSame([], $this->fieldRows('hero@%'));

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertSame(0, $res->error);
        $this->assertEquals($original, $this->fieldDump());
        $this->assertSame([ItemStatus::RolledBack->value => 1], $this->items()->countByStatus($jobId));
    }

    #[Test]
    public function 同名モードのジョブはクリーンアップの対象外(): void
    {
        $jobId = $this->convertedFieldJob(['field' => ['mode' => 'same']]);
        $before = $this->fieldDump();

        $res = $this->cleaner()->cleanupChunk($this->job($jobId));

        $this->assertTrue($res->done);
        $this->assertSame(0, $res->processed);
        $this->assertEquals($before, $this->fieldDump());
    }

    #[Test]
    public function 新しいフィールドが無い_編集ロック中のアイテムは消さずに残す(): void
    {
        $jobId = $this->convertedFieldJob();
        // gallery は新しいフィールドが消えている（旧キーしか無い）→ 消すと全データを失うので触らない
        $this->db->exec('DELETE FROM ' . $this->db->t('field') . ' WHERE field_key LIKE \'gallery\\_media%\' AND field_eid = ?', [$this->entryId]);
        // エントリーが編集ロック中
        $this->db->exec('UPDATE ' . $this->db->t('entry') . ' SET entry_lock_uid = 2, entry_lock_datetime = ? WHERE entry_id = ?', [date('Y-m-d H:i:s'), $this->entryId]);

        $res = $this->cleaner()->cleanupChunk($this->job($jobId));

        $this->assertTrue($res->done);
        $this->assertSame([0, 3], [$res->converted, $res->skipped]);
        $this->assertNotSame([], $this->fieldRows('gallery@path'), '旧データは残る');
        $this->assertSame(['cleanable' => 3, 'cleaned' => 0], $this->items()->cleanupCounts($jobId));
        $reasons = array_map(static fn (array $l): string => $l['reason'], $res->log);
        sort($reasons);
        $this->assertSame(['CLEANUP_BLOCKED', 'ENTRY_LOCKED', 'ENTRY_LOCKED'], $reasons);
    }

    #[Test]
    public function 二重に実行しても何も起きない(): void
    {
        $jobId = $this->convertedFieldJob();
        $this->cleaner()->cleanupChunk($this->job($jobId));
        $dump = $this->fieldDump();

        $res = $this->cleaner()->cleanupChunk($this->job($jobId));

        $this->assertSame(0, $res->processed);
        $this->assertEquals($dump, $this->fieldDump());
    }

    #[Test]
    public function 完了していないジョブには実行できない(): void
    {
        $this->archives->put('003/h.jpg', 'H');
        $this->insertImageField('hero', '003/h.jpg');
        $jobId = $this->scannedJob(['targets' => ['field_image']]);
        $this->expectException(\LogicException::class);
        $this->cleaner()->cleanupChunk($this->job($jobId));
    }

    #[Test]
    public function 別の処理が実行中なら拒否する(): void
    {
        $jobId = $this->convertedFieldJob();
        $held = ($this->lockFactory())();
        $this->assertInstanceOf(Lock::class, $held);
        $this->assertTrue($held->tryLock());
        try {
            $this->cleaner()->cleanupChunk($this->job($jobId));
            $this->fail('例外が必要');
        } catch (JobBusyException) {
            $this->assertSame(['cleanable' => 3, 'cleaned' => 0], $this->items()->cleanupCounts($jobId));
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function ロールバック済みのジョブには実行できない(): void
    {
        $jobId = $this->convertedFieldJob();
        $this->rollbacker()->rollbackChunk($this->job($jobId), false);
        $this->expectException(\LogicException::class);
        $this->cleaner()->cleanupChunk($this->job($jobId));
    }
}
