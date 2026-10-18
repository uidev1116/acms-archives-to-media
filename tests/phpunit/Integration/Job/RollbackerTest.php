<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Services\Common\Lock;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Item;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RollbackerTest extends A2mTestCase
{
    private function job(int $id): Job
    {
        $job = $this->jobs()->find($id);
        $this->assertNotNull($job);
        return $job;
    }

    /**
     * @return array{columns: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    private function dump(): array
    {
        return [
            'columns' => $this->db->all('SELECT * FROM ' . $this->db->t('column') . ' WHERE column_entry_id = ? ORDER BY column_id', [$this->entryId]),
            'fields' => $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort, field_value', [$this->entryId]),
        ];
    }

    private function seed(): void
    {
        $d = ':acms_unit_delimiter:';
        $this->archives->put('001/a.jpg', 'A');
        $this->archives->put('001/large-a.jpg', 'ALARGE');
        $this->archives->put('001/ja.jpg', 'JA');
        $this->archives->put('001/en.jpg', 'EN');
        $this->archives->put('002/doc.pdf', 'PDF');
        $this->archives->put('003/h.jpg', 'H');
        $this->insertColumn('u1', 'image', ['column_field_1' => '図1', 'column_field_2' => '001/a.jpg', 'column_field_3' => '/x', 'column_field_4' => '代替', 'column_field_5' => '60', 'column_field_6' => 'exif', 'column_size' => '400']);
        $this->insertColumn('ml', 'image', ['column_field_2' => "001/ja.jpg{$d}001/en.jpg"]);
        $this->insertColumn('u2', 'file', ['column_field_1' => '資料', 'column_field_2' => '002/doc.pdf']);
        $this->insertImageField('hero', '003/h.jpg', 1, 'ヒーロー');
        $this->insertField('@group', 'hero', 1);
        $this->insertFileField('doc', '002/doc.pdf', 'doc.pdf', '請求書.pdf');
    }

    /**
     * 変換まで終えたジョブ ID
     *
     * @param array<string, mixed> $options
     */
    private function convertedJob(array $options = []): int
    {
        $jobId = $this->scannedJob($options);
        for ($i = 0; $i < 20; $i++) {
            if ($this->runner()->runChunk($this->job($jobId))->done) {
                return $jobId;
            }
        }
        $this->fail('変換が終わらない');
    }

    #[Test]
    public function ロールバックすると全行が変換前と完全に一致する(): void
    {
        $this->seed();
        $original = $this->dump();
        $jobId = $this->convertedJob();
        $this->assertNotEquals($original, $this->dump());

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertTrue($res->done);
        $this->assertSame(JobStatus::RolledBack, $res->status);
        $this->assertSame(5, $res->converted);
        $this->assertEquals($original, $this->dump());
        $this->assertSame([ItemStatus::RolledBack->value => 5], $this->items()->countByStatus($jobId));
        $this->assertSame(JobStatus::RolledBack, $this->job($jobId)->status);
    }

    #[Test]
    public function 同名モードでもロールバックで完全に戻る(): void
    {
        $this->seed();
        $original = $this->dump();
        $jobId = $this->convertedJob(['field' => ['mode' => 'same']]);
        $this->assertSame([], $this->fieldRows('hero@path'));

        $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertEquals($original, $this->dump());
    }

    #[Test]
    public function チャンクごとに戻し_最後で完了する(): void
    {
        $this->seed();
        $jobId = $this->convertedJob(['chunkSize' => 2]);
        $rb = $this->rollbacker();

        $first = $rb->rollbackChunk($this->job($jobId), false);
        $this->assertFalse($first->done);
        $this->assertSame(JobStatus::RollingBack, $first->status);
        $this->assertSame(2, $first->processed);

        $last = null;
        for ($i = 0; $i < 5; $i++) {
            $last = $rb->rollbackChunk($this->job($jobId), false);
            if ($last->done) {
                break;
            }
        }
        $this->assertNotNull($last);
        $this->assertTrue($last->done);
        $this->assertSame(JobStatus::RolledBack, $this->job($jobId)->status);
    }

    #[Test]
    public function 変換後に編集された行は戻さずROLLBACK_CONFLICTにする(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $this->db->exec('UPDATE ' . $this->db->t('column') . ' SET column_field_2 = ? WHERE column_id = ?', ['編集後', 'u1']);

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertTrue($res->done);
        $this->assertSame([4, 1], [$res->converted, $res->error]);
        $this->assertSame('media', $this->columnRow('u1')['column_type']);
        $this->assertSame('編集後', $this->columnRow('u1')['column_field_2']);
        $this->assertSame('file', $this->columnRow('u2')['column_type']);
        $err = $this->items()->byStatuses($jobId, [ItemStatus::Error], 0, 5);
        $this->assertCount(1, $err);
        $this->assertSame('ROLLBACK_CONFLICT', $err[0]->reason);
        $this->assertSame(JobStatus::RolledBack, $this->job($jobId)->status);
    }

    #[Test]
    public function このジョブが作ったメディアのうち参照が無いものだけ削除できる(): void
    {
        $this->seed();
        $preexisting = \Acms\TestingFramework\Seeder\MediaSeeder::seed($this->blogId, 1, ['media_hash' => hash('sha256', 'H')]);
        $jobId = $this->convertedJob();
        $created = (new PathMapRepository($this->db))->mediaIdsCreatedByJob($jobId);
        $this->assertNotContains($preexisting, $created);
        $this->assertNotSame([], $created);

        // 1 つだけ競合させ、その参照先メディアは消えないようにする
        $conflictMedia = Cast::int($this->columnRow('u1')['column_field_1']);
        $this->db->exec('UPDATE ' . $this->db->t('column') . ' SET column_field_2 = ? WHERE column_id = ?', ['編集後', 'u1']);

        $this->rollbacker()->rollbackChunk($this->job($jobId), true);

        $this->assertNotContains($conflictMedia, $this->media->deleted);
        $this->assertContains($conflictMedia, $created);
        $deleted = $this->media->deleted;
        sort($deleted);
        $expected = array_values(array_diff($created, [$conflictMedia]));
        sort($expected);
        $this->assertSame($expected, $deleted);
        $this->assertNotNull($this->db->row('SELECT 1 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$preexisting]));
        $this->assertNull((new PathMapRepository($this->db))->find($this->blogId, '002/doc.pdf'));
        $this->assertNotNull((new PathMapRepository($this->db))->find($this->blogId, '001/a.jpg'));
    }

    #[Test]
    public function メディアの削除に失敗しても他は削除し_ジョブは完了して失敗を記録する(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $created = (new PathMapRepository($this->db))->mediaIdsCreatedByJob($jobId);
        sort($created);
        $stuck = $created[0];
        $this->media->failDelete = [$stuck];

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), true);

        $this->assertTrue($res->done);
        $job = $this->job($jobId);
        $this->assertSame(JobStatus::RolledBack, $job->status);
        $this->assertStringContainsString((string) $stuck, (string) $job->message);
        $expected = array_values(array_diff($created, [$stuck]));
        $deleted = $this->media->deleted;
        sort($deleted);
        $this->assertSame($expected, $deleted);
        // 失敗した分は対応表に残る（次回の再利用・手動での整理のため）
        $this->assertNotNull($this->db->row('SELECT 1 FROM ' . $this->db->t('a2m_path_map') . ' WHERE map_media_id = ?', [$stuck]));
    }

    #[Test]
    public function 削除オプションがオフならメディアは残る(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $this->rollbacker()->rollbackChunk($this->job($jobId), false);
        $this->assertSame([], $this->media->deleted);
    }

    #[Test]
    public function 途中までの変換_一時停止中のジョブもロールバックできる(): void
    {
        $this->seed();
        $jobId = $this->scannedJob(['chunkSize' => 2]);
        $original = $this->dump();
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $runner->pause($this->job($jobId));

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertTrue($res->done);
        $this->assertSame(2, $res->converted);
        $this->assertEquals($original, $this->dump());
        $this->assertSame(JobStatus::RolledBack, $this->job($jobId)->status);
    }

    #[Test]
    public function ロールバックできない状態は拒否する(): void
    {
        $this->seed();
        $jobId = $this->scannedJob();
        $this->expectException(\LogicException::class);
        $this->rollbacker()->rollbackChunk($this->job($jobId), false);
    }

    #[Test]
    public function ロールバック済みを再度ロールバックしようとしたら拒否する(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $this->rollbacker()->rollbackChunk($this->job($jobId), false);
        $this->expectException(\LogicException::class);
        $this->rollbacker()->rollbackChunk($this->job($jobId), false);
    }

    #[Test]
    public function 別の処理が実行中なら拒否する(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $held = ($this->lockFactory())();
        $this->assertInstanceOf(Lock::class, $held);
        $this->assertTrue($held->tryLock());
        try {
            $this->rollbacker()->rollbackChunk($this->job($jobId), false);
            $this->fail('例外が必要');
        } catch (JobBusyException) {
            $this->assertSame(JobStatus::Completed, $this->job($jobId)->status);
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function ロールバック後に再スキャンすると同じ候補が見つかる_往復(): void
    {
        $this->seed();
        $firstJob = $this->convertedJob();
        $this->assertSame(0, $this->job($this->scannedJob())->total);

        $this->rollbacker()->rollbackChunk($this->job($firstJob), false);
        $second = $this->scannedJob();
        $this->assertSame(5, $this->job($second)->total);
        $this->runner()->runChunk($this->job($second));
        $this->assertSame(5, $this->job($second)->converted);
        // パス対応表のおかげで同じメディアが再利用され、重複登録されない
        $this->assertCount(5, $this->media->registered);
    }

    #[Test]
    public function 変換済みが無いジョブのロールバックは即完了(): void
    {
        $this->seed();
        $jobId = $this->scannedJob();
        $this->jobs()->setStatus($jobId, JobStatus::Paused);
        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);
        $this->assertTrue($res->done);
        $this->assertSame(0, $res->processed);
    }

    #[Test]
    public function 不明な変換器のアイテムはエラーにして先へ進む(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $item = $this->items()->nextConverted($jobId, 1)[0];
        $this->assertInstanceOf(Item::class, $item);
        $res = $this->rollbacker([$this->unitFileConverter()])->rollbackChunk($this->job($jobId), false);
        $this->assertTrue($res->done);
        $this->assertGreaterThan(0, $res->error);
    }

    #[Test]
    public function 復元済みなのにステータスが残ったアイテムも_冪等にロールバック済みにする(): void
    {
        $this->seed();
        $original = $this->dump();
        $jobId = $this->convertedJob();
        $this->rollbacker()->rollbackChunk($this->job($jobId), false);
        // コミット後・ステータス更新前に落ちた状況: データは復元済みだが item は converted のまま
        $this->db->exec('UPDATE ' . $this->db->t('a2m_item') . ' SET item_status = ? WHERE item_job_id = ?', ['converted', $jobId]);
        $this->jobs()->setStatus($jobId, JobStatus::Failed, 'crash');

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertTrue($res->done);
        $this->assertSame(0, $res->error);
        $this->assertSame(5, $res->converted);
        $this->assertEquals($original, $this->dump());
        $this->assertSame([ItemStatus::RolledBack->value => 5], $this->items()->countByStatus($jobId));
    }
}
