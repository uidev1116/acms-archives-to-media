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
    public function エントリーが更新中でロックを取れなければ_戻さず変換済みのまま残し_ロールバック中のまま後で再ロールバックできる(): void
    {
        $this->seed();
        $original = $this->dump();
        $jobId = $this->convertedJob();
        $converted = $this->dump();
        $busy = new \Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer(static function (int $eid): never {
            throw new \Acms\Services\Unit\Exceptions\UnitLockTimeoutException($eid);
        });

        $res = $this->rollbacker(null, $busy)->rollbackChunk($this->job($jobId), false);

        $this->assertSame(JobStatus::RollingBack, $res->status);
        $this->assertTrue($res->done, '同じ位置で空回りしないよう、このチャンクで区切りをつける');
        $this->assertSame([0, 0], [$res->converted, $res->error]);
        $this->assertGreaterThan(0, $res->skipped);
        $this->assertSame('ENTRY_LOCKED', $res->log[0]['reason']);
        $this->assertSame($converted, $this->dump(), '戻していない');
        $this->assertSame(5, $this->items()->countByStatus($jobId)[ItemStatus::Converted->value] ?? 0, '変換済みのまま残る（エラーにしない）');
        $job = $this->job($jobId);
        $this->assertSame(JobStatus::RollingBack, $job->status, 'ロールバック済みにも「一時停止」（変換を再開できる状態）にもしない');
        $this->assertFalse($job->status->canRun(), '変換の再実行（run）が通ってしまわない');
        $this->assertStringContainsString('もう一度ロールバック', (string) $job->message);

        // ロックが取れるようになってから、もう一度ロールバックすれば最後まで戻る
        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);
        $this->assertTrue($res->done);
        $this->assertSame(5, $res->converted);
        $this->assertSame(JobStatus::RolledBack, $this->job($jobId)->status);
        $this->assertEquals($original, $this->dump());
    }

    #[Test]
    public function 作成メディアの削除は件数が多くても_チャンクに区切って終わらせる(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, \Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions::defaults());
        $this->jobs()->setStatus($jobId, JobStatus::Completed);
        $pathMap = new PathMapRepository($this->db);
        $ids = [];
        for ($i = 0; $i < 250; $i++) {
            $mid = \Acms\TestingFramework\Seeder\MediaSeeder::seed($this->blogId);
            $pathMap->save($this->blogId, "m/{$i}.jpg", $mid, $jobId);
            $ids[] = $mid;
        }
        $keep = min([...$ids, PHP_INT_MAX]); // 1 回目のチャンク（media_id の昇順で 200 件）に必ず入るもの
        $this->insertColumn('keep', 'media', ['column_field_1' => (string) $keep]);

        $first = $this->rollbacker()->rollbackChunk($this->job($jobId), true);
        $this->assertFalse($first->done, '200 件ずつ');
        $this->assertSame(JobStatus::RollingBack, $first->status);
        $this->assertCount(200 - 1, $this->media->deleted, '参照されている 1 件は残す');

        $second = $this->rollbacker()->rollbackChunk($this->job($jobId), true);
        $this->assertTrue($second->done);
        $this->assertSame(JobStatus::RolledBack, $second->status);
        $deleted = $this->media->deleted;
        sort($deleted);
        $expected = array_values(array_diff($ids, [$keep]));
        sort($expected);
        $this->assertSame($expected, $deleted);
        $this->assertSame([$keep], $pathMap->mediaIdsCreatedByJob($jobId), '参照されているメディアは対応表にも残る');
    }

    #[Test]
    public function メディアフェーズのカーソルが残っていても_変換済みが残っていれば先に戻す(): void
    {
        $this->seed();
        $original = $this->dump();
        $jobId = $this->convertedJob();
        // 前のロールバックが media フェーズの例外で止まり、そのあと変換済みが増えた状態を再現する
        $this->jobs()->setCursor($jobId, '{"p":"media","after":0,"failed":[],"extra":0}');
        $this->jobs()->setStatus($jobId, JobStatus::RollingBack);

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertTrue($res->done);
        $this->assertSame(JobStatus::RolledBack, $res->status);
        $this->assertSame(5, $res->converted);
        $this->assertEquals($original, $this->dump());
    }

    #[Test]
    public function ロールバック中に例外が起きても_Failedにせずロールバック中のまま理由を残す(): void
    {
        $this->seed();
        $jobId = $this->convertedJob();
        $broken = new class ($this->db) extends \Acms\Plugins\ArchivesToMedia\Services\Media\MediaReferenceChecker {
            public function referencedAmong(array $mediaIds): array
            {
                throw new \RuntimeException('SQLSTATE secret detail');
            }
        };
        $rollbacker = new \Acms\Plugins\ArchivesToMedia\Services\Job\Rollbacker(
            [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()],
            $this->items(),
            $this->jobs(),
            new PathMapRepository($this->db),
            $broken,
            $this->media,
            new \Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator($this->db),
            $this->lockFactory(),
            new \Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore($this->db),
        );

        try {
            $rollbacker->rollbackChunk($this->job($jobId), true);
            $this->fail('例外が必要');
        } catch (\RuntimeException) {
            $job = $this->job($jobId);
            $this->assertSame(JobStatus::RollingBack, $job->status, 'Failed は「変換の実行が失敗」だけの意味にする');
            $this->assertStringNotContainsString('secret', (string) $job->message);
            $this->assertNotSame('', (string) $job->message);
        }
    }

    #[Test]
    public function 作成メディアの削除は_時間の予算が尽きたら途中で区切り_続きから進む(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, \Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions::defaults());
        $this->jobs()->setStatus($jobId, JobStatus::Completed);
        $pathMap = new PathMapRepository($this->db);
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $mid = \Acms\TestingFramework\Seeder\MediaSeeder::seed($this->blogId);
            $pathMap->save($this->blogId, "t/{$i}.jpg", $mid, $jobId);
            $ids[] = $mid;
        }

        $first = $this->rollbacker()->rollbackChunk($this->job($jobId), true, 0.0);
        $this->assertFalse($first->done, '予算が尽きたら 200 件を待たずに区切る');
        $this->assertGreaterThanOrEqual(1, count($this->media->deleted));
        $this->assertLessThan(30, count($this->media->deleted));

        $res = $first;
        for ($i = 0; $i < 40 && !$res->done; $i++) {
            $res = $this->rollbacker()->rollbackChunk($this->job($jobId), true, 0.0);
        }
        $this->assertTrue($res->done);
        $deleted = $this->media->deleted;
        sort($deleted);
        sort($ids);
        $this->assertSame($ids, $deleted);
    }

    #[Test]
    public function 削除できないメディアが多くても_カーソルは肥大化せず_件数を最後に知らせる(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, \Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions::defaults());
        $this->jobs()->setStatus($jobId, JobStatus::Completed);
        $pathMap = new PathMapRepository($this->db);
        for ($i = 0; $i < 650; $i++) {
            $mid = \Acms\TestingFramework\Seeder\MediaSeeder::seed($this->blogId);
            $pathMap->save($this->blogId, "f/{$i}.jpg", $mid, $jobId);
            $this->media->failDelete[] = $mid;
        }

        $res = null;
        for ($i = 0; $i < 6 && ($res === null || !$res->done); $i++) {
            $res = $this->rollbacker()->rollbackChunk($this->job($jobId), true);
            $cursor = $this->job($jobId)->scanCursor;
            $this->assertLessThan(10000, strlen((string) $cursor), 'カーソルは text（64KB）に収まる');
        }

        $this->assertTrue($res?->done);
        $job = $this->job($jobId);
        $this->assertSame(JobStatus::RolledBack, $job->status);
        $this->assertStringContainsString('650 件', (string) $job->message);
    }

    #[Test]
    public function 同じフィールドの別のsortが戻せずに残るあいだは_グループ定義への追記を消さない(): void
    {
        $this->archives->put('004/g1.jpg', 'G1');
        $this->archives->put('004/g2.jpg', 'G2');
        $this->insertImageField('gallery', '004/g1.jpg', 1);
        $this->insertImageField('gallery', '004/g2.jpg', 2);
        $this->insertField('@set', 'gallery', 1);
        $jobId = $this->convertedJob(['targets' => ['field_image']]);
        $groupBefore = $this->fieldRows('@set');
        $this->assertCount(2, $groupBefore, '変換で gallery_media が追記される');

        // sort 2 の変換結果を、変換後に編集する（戻せなくなる）
        $this->db->exec('UPDATE ' . $this->db->t('field') . ' SET field_value = ? WHERE field_eid = ? AND field_key = ? AND field_sort = 2', ['999', $this->entryId, 'gallery_media']);

        $res = $this->rollbacker()->rollbackChunk($this->job($jobId), false);

        $this->assertSame([1, 1], [$res->converted, $res->error]);
        $groupAfter = array_map(static fn (array $r): string => Cast::str($r['field_value']), $this->fieldRows('@set'));
        sort($groupAfter);
        $this->assertSame(['gallery', 'gallery_media'], $groupAfter, 'sort 2 が変換されたまま残っているので、グループの追記は消さない');
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
