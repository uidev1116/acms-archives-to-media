<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\Services\Common\Lock;
use Acms\Plugins\ArchivesToMedia\Tests\Support\RecordingConverter;
use PHPUnit\Framework\Attributes\Test;

final class JobRunnerTest extends A2mTestCase
{
    private function job(int $id): \Acms\Plugins\ArchivesToMedia\Services\Domain\Job
    {
        $job = $this->jobs()->find($id);
        $this->assertNotNull($job);
        return $job;
    }

    private function seedBasic(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->archives->put('001/large-a.jpg', 'ALARGE');
        $this->archives->put('002/doc.pdf', 'PDF');
        $this->archives->put('003/h.jpg', 'H');
        $this->insertColumn('u1', 'image', ['column_field_1' => '図1', 'column_field_2' => '001/a.jpg', 'column_field_4' => '代替']);
        $this->insertColumn('u2', 'file', ['column_field_1' => '資料', 'column_field_2' => '002/doc.pdf']);
        $this->insertImageField('hero', '003/h.jpg', 1, 'ヒーロー');
    }

    #[Test]
    public function 全アイテムを変換してジョブが完了する(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertTrue($res->done);
        $this->assertSame(JobStatus::Completed, $res->status);
        $this->assertSame([3, 3, 0, 0], [$res->processed, $res->converted, $res->skipped, $res->error]);
        $this->assertCount(3, $res->log);
        $this->assertSame('media', $this->columnRow('u1')['column_type']);
        $this->assertSame('media', $this->columnRow('u2')['column_type']);
        $this->assertNotSame([], $this->fieldRows('hero\\_media@media'));
        $job = $this->job($jobId);
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(3, $job->converted);
        $this->assertNotNull($job->finishedAt);
        // 新規メディアの alt が入る
        $alt = $this->db->one('SELECT media_field_3 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [Cast::int($this->columnRow('u1')['column_field_1'])]);
        $this->assertSame('代替', $alt);
        // 元のファイルは消えない
        $this->assertFileExists($this->archives->dir . '001/a.jpg');
        $item = $this->items()->byStatuses($jobId, [ItemStatus::Converted], 0, 1)[0];
        $this->assertNotNull($item->after);
        $this->assertNotSame([], $item->mediaIds);
    }

    #[Test]
    public function 書き込みはエントリー単位のロックの中で行う(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $locked = [];
        $entries = new \Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer(function (int $eid, callable $fn) use (&$locked) {
            $locked[] = $eid;
            return $fn();
        });

        $res = $this->runner(null, null, $entries)->runChunk($this->job($jobId));

        $this->assertSame(3, $res->converted);
        $this->assertSame([$this->entryId, $this->entryId, $this->entryId], $locked, 'ユニット 2 件とエントリーのフィールド 1 件');
    }

    #[Test]
    public function エントリーのロックを取れなければENTRY_LOCKEDでスキップし_何も書き換えない(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $jobId = $this->scannedJob();
        $before = $this->columnRow('u1');
        $entries = new \Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer(static function (int $eid): never {
            throw new \Acms\Services\Unit\Exceptions\UnitLockTimeoutException($eid);
        });

        $res = $this->runner(null, null, $entries)->runChunk($this->job($jobId));

        $this->assertSame([0, 1, 0], [$res->converted, $res->skipped, $res->error]);
        $this->assertSame('ENTRY_LOCKED', $res->log[0]['reason']);
        $this->assertSame($before, $this->columnRow('u1'));
        $item = $this->items()->byStatuses($jobId, [ItemStatus::Skipped], 0, 1)[0];
        $this->assertSame(ItemStatus::Skipped, $item->status);
    }

    #[Test]
    public function 読み込んだあとに別の操作で状態が変わっていたら_実行を始めずに拒否する(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $stale = $this->job($jobId);
        $this->assertSame(JobStatus::Scanned, $stale->status);
        // 別のタブで、先にロールバック済みにされた（状態は DB が正）
        $this->db->exec('UPDATE ' . $this->db->t('a2m_job') . ' SET job_status = ? WHERE job_id = ?', ['rolled_back', $jobId]);

        try {
            $this->runner()->runChunk($stale);
            $this->fail('拒否されるはず');
        } catch (\Acms\Plugins\ArchivesToMedia\Services\Support\StateException) {
            $this->assertSame(JobStatus::RolledBack, $this->job($jobId)->status, '状態を上書きしない');
            $this->assertSame('image', $this->columnRow('u1')['column_type'], '何も書き換えない');
        }
    }

    #[Test]
    public function チャンクごとに処理し_最後のチャンクでだけ完了する(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['chunkSize' => 2]);

        $first = $this->runner()->runChunk($this->job($jobId));
        $this->assertFalse($first->done);
        $this->assertSame(JobStatus::Running, $first->status);
        $this->assertSame([2, 1], [$first->processed, $first->remaining]);

        $second = $this->runner()->runChunk($this->job($jobId));
        $this->assertTrue($second->done);
        $this->assertSame(1, $second->processed);
    }

    #[Test]
    public function 一時停止して再開できる(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['chunkSize' => 1]);
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));

        $runner->pause($this->job($jobId));
        $this->assertSame(JobStatus::Paused, $this->job($jobId)->status);

        $runner->runChunk($this->job($jobId));
        $res = $runner->runChunk($this->job($jobId));
        $this->assertTrue($res->done);
        $this->assertSame(3, $this->job($jobId)->converted);
    }

    #[Test]
    public function 実行できない状態のジョブは拒否する(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->expectException(\LogicException::class);
        $this->runner()->runChunk($this->job($jobId));
    }

    #[Test]
    public function 完了したジョブを再実行しても何も起きない_拒否される(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $this->runner()->runChunk($this->job($jobId));
        $this->expectException(\LogicException::class);
        $this->runner()->runChunk($this->job($jobId));
    }

    #[Test]
    public function 除外したアイテムは触らない(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $target = $this->items()->nextPending($jobId, 1)[0];
        $this->items()->setExcluded($jobId, [$target->id], true);

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertSame(2, $res->converted);
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
        $this->assertSame(ItemStatus::Excluded, $this->items()->find($target->id)?->status);
    }

    #[Test]
    public function スキャン後にデータが変わっていたらSTALE_SNAPSHOTでスキップ(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $this->db->exec('UPDATE ' . $this->db->t('column') . ' SET column_field_1 = ? WHERE column_id = ?', ['編集された', 'u1']);

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertSame([2, 1], [$res->converted, $res->skipped]);
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
        $this->assertSame('編集された', $this->columnRow('u1')['column_field_1']);
        $this->assertSame('STALE_SNAPSHOT', $this->items()->byStatuses($jobId, [ItemStatus::Skipped], 0, 1)[0]->reason);
    }

    #[Test]
    public function スキャン後にフィールドが削除されていてもSTALE_SNAPSHOT(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['field_image']]);
        $this->db->exec('DELETE FROM ' . $this->db->t('field') . ' WHERE field_key LIKE \'hero%\'');
        $res = $this->runner()->runChunk($this->job($jobId));
        $this->assertSame(1, $res->skipped);
        $this->assertSame('STALE_SNAPSHOT', $res->log[0]['reason']);
    }

    #[Test]
    public function 編集ロック中のエントリーはENTRY_LOCKEDでスキップし_後で再実行できる(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->db->exec('UPDATE ' . $this->db->t('entry') . ' SET entry_lock_uid = 2, entry_lock_datetime = ? WHERE entry_id = ?', [date('Y-m-d H:i:s'), $this->entryId]);

        $res = $this->runner()->runChunk($this->job($jobId));
        $this->assertSame(1, $res->skipped);
        $this->assertSame('ENTRY_LOCKED', $res->log[0]['reason']);
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
        $this->assertSame([], $this->media->registered);

        // ロック解除後、スキップ分を再実行
        $this->db->exec('UPDATE ' . $this->db->t('entry') . ' SET entry_lock_uid = 0, entry_lock_datetime = \'1000-01-01 00:00:00\' WHERE entry_id = ?', [$this->entryId]);
        $runner = $this->runner();
        $this->assertSame(1, $runner->requeueChunk($this->job($jobId), 600.0, true)['requeued']);
        $this->assertSame(JobStatus::Paused, $this->job($jobId)->status);
        $res = $runner->runChunk($this->job($jobId));
        $this->assertSame(1, $res->converted);
        $this->assertSame('media', $this->columnRow('u1')['column_type']);
    }

    #[Test]
    public function 期限切れのロックは無視する(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->db->exec('UPDATE ' . $this->db->t('entry') . ' SET entry_lock_uid = 2, entry_lock_datetime = ? WHERE entry_id = ?', [date('Y-m-d H:i:s', time() - 49 * 3600), $this->entryId]);
        $this->assertSame(1, $this->runner()->runChunk($this->job($jobId))->converted);
    }

    #[Test]
    public function スキャン後にファイルが消えたらSOURCE_NOT_FOUNDでスキップ_データは変わらない(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_file']]);
        unlink($this->archives->dir . '002/doc.pdf');

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertSame('SOURCE_NOT_FOUND', $res->log[0]['reason']);
        $this->assertSame('file', $this->columnRow('u2')['column_type']);
    }

    #[Test]
    public function 多言語の一部だけ失敗したらユニット全体をスキップ(): void
    {
        $d = ':acms_unit_delimiter:';
        $this->archives->put('001/ja.jpg', 'JA');
        $this->archives->put('001/en.jpg', 'EN');
        $this->insertColumn('ml', 'image', ['column_field_2' => "001/ja.jpg{$d}001/en.jpg"]);
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        unlink($this->archives->dir . '001/en.jpg');

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertSame('PARTIAL_SOURCE', $res->log[0]['reason']);
        $this->assertSame('image', $this->columnRow('ml')['column_type']);
        // 取り込めた側のメディアは対応表に残り、再実行で重複しない
        $this->assertNotNull((new PathMapRepository($this->db))->find($this->blogId, '001/ja.jpg'));
    }

    #[Test]
    public function 多言語ユニットは全言語を変換する(): void
    {
        $d = ':acms_unit_delimiter:';
        $this->archives->put('001/ja.jpg', 'JA');
        $this->archives->put('001/en.jpg', 'EN');
        $this->insertColumn('ml', 'image', ['column_field_2' => "001/ja.jpg{$d}001/en.jpg"]);
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->runner()->runChunk($this->job($jobId));
        $ids = explode($d, Cast::str($this->columnRow('ml')['column_field_1']));
        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
    }

    #[Test]
    public function 同じファイルを使う複数のユニットは1つのメディアを共有する(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('u2', 'image', ['column_field_2' => '001/a.jpg']);
        $jobId = $this->scannedJob();
        $this->runner()->runChunk($this->job($jobId));
        $this->assertSame($this->columnRow('u1')['column_field_1'], $this->columnRow('u2')['column_field_1']);
        $this->assertCount(1, $this->media->registered);
    }

    #[Test]
    public function 再利用したメディアのaltが違うとALT_MISMATCHの警告を付けるが値は書き換えない(): void
    {
        $this->archives->put('003/h.jpg', 'H');
        $this->archives->put('004/h2.jpg', 'H');
        $this->insertImageField('first', '003/h.jpg', 1, 'ひとつめ');
        $this->insertImageField('second', '004/h2.jpg', 1, 'ふたつめ');
        $jobId = $this->scannedJob(['targets' => ['field_image']]);

        $this->runner()->runChunk($this->job($jobId));

        $items = $this->items()->byStatuses($jobId, [ItemStatus::Converted], 0, 10);
        $this->assertCount(2, $items);
        $this->assertSame('', $items[0]->reason);
        $this->assertSame('ALT_MISMATCH', $items[1]->reason);
        $this->assertSame('ひとつめ', $this->db->one('SELECT media_field_3 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$items[0]->mediaIds[0]]));
    }

    #[Test]
    public function 別のブログのメディアを再利用したらMEDIA_OTHER_BLOGの警告を付ける(): void
    {
        $other = $this->createTestBlog();
        $this->archives->put('003/h.jpg', 'H');
        $foreign = \Acms\TestingFramework\Seeder\MediaSeeder::seed($other, 1, ['media_hash' => hash('sha256', 'H')]);
        $this->insertImageField('hero', '003/h.jpg', 1, '');
        $jobId = $this->scannedJob(['targets' => ['field_image']]);

        $this->runner()->runChunk($this->job($jobId));

        $items = $this->items()->byStatuses($jobId, [ItemStatus::Converted], 0, 10);
        $this->assertCount(1, $items);
        $this->assertSame([$foreign], $items[0]->mediaIds);
        $this->assertSame('MEDIA_OTHER_BLOG', $items[0]->reason);
    }

    #[Test]
    public function メディア登録の失敗はスキップ扱いでジョブは止まらない(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_image', 'unit_file']]);
        $this->media->failRegister = true;

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertSame([0, 2], [$res->converted, $res->skipped]);
        $this->assertTrue($res->done);
        $this->assertSame(JobStatus::Completed, $this->job($jobId)->status);
    }

    #[Test]
    public function 予期しない例外はそのアイテムだけエラーにしてDBを巻き戻し_他は続ける(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_image', 'unit_file']]);
        $real = $this->unitImageConverter();
        $exploding = new class ($real) implements ConverterInterface {
            public function __construct(private readonly ConverterInterface $inner)
            {
            }

            public function supports(TargetType $target): bool
            {
                return $this->inner->supports($target);
            }

            public function snapshot(LocatorInterface $locator, JobOptions $options): ?array
            {
                return $this->inner->snapshot($locator, $options);
            }

            public function metas(Candidate $candidate, JobOptions $options): array
            {
                return $this->inner->metas($candidate, $options);
            }

            public function currentSourcePaths(LocatorInterface $locator): ?array
            {
                return $this->inner->currentSourcePaths($locator);
            }

            public function lock(LocatorInterface $locator, JobOptions $options): void
            {
                $this->inner->lock($locator, $options);
            }

            public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array
            {
                $this->inner->apply($candidate, $mediaIds, $options);
                throw new \RuntimeException('boom');
            }

            public function restore(LocatorInterface $locator, array $before, array $after, JobOptions $options): void
            {
                $this->inner->restore($locator, $before, $after, $options);
            }
        };

        $res = $this->runner([$exploding, $this->unitFileConverter()])->runChunk($this->job($jobId));

        $this->assertSame([1, 0, 1], [$res->converted, $res->skipped, $res->error]);
        // 書きかけの変更は巻き戻される
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
        $this->assertSame('media', $this->columnRow('u2')['column_type']);
        $err = $this->items()->byStatuses($jobId, [ItemStatus::Error], 0, 1)[0];
        $this->assertSame('UNKNOWN_ERROR', $err->reason);
        $this->assertStringNotContainsString('boom', (string) $err->message, '例外の生の文言は記録しない');
        $this->assertSame(\Acms\Plugins\ArchivesToMedia\Services\Support\ErrorMessage::GENERIC, $err->message);
        // 登録済みのメディアは対応表に残る（次回再利用）
        $this->assertNotNull((new PathMapRepository($this->db))->find($this->blogId, '001/a.jpg'));
        $this->assertSame(JobStatus::Completed, $this->job($jobId)->status);
    }

    #[Test]
    public function エラー分を再実行できる(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_file']]);
        $this->media->failRegister = true;
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $this->assertSame(1, $this->job($jobId)->skipped);

        $this->media->failRegister = false;
        $this->assertSame(1, $runner->requeueChunk($this->job($jobId), 600.0, true)['requeued']);
        $res = $runner->runChunk($this->job($jobId));
        $this->assertSame(1, $res->converted);
        $this->assertSame(0, $this->job($jobId)->skipped);
    }

    #[Test]
    public function 再実行は時間の予算で区切って返し_続きから全件戻せる(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertColumn('q' . $i, 'image', ['column_field_2' => "001/q{$i}.jpg"]);
            $this->archives->put("001/q{$i}.jpg", 'Q' . $i);
        }
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->media->failRegister = true;
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $this->assertSame(5, $this->job($jobId)->skipped);

        $this->media->failRegister = false;
        $first = $runner->requeueChunk($this->job($jobId), 0.0);
        $this->assertFalse($first['done']);
        $this->assertGreaterThanOrEqual(1, $first['requeued']);
        $this->assertLessThan(5, $first['requeued'], '予算が尽きたら、全件を待たずに返す');
        $this->assertSame(JobStatus::Paused, $this->job($jobId)->status, '途中で止まっても、実行できる状態にしておく');

        $total = $first['requeued'];
        $res = $first;
        for ($i = 0; $i < 10 && !$res['done']; $i++) {
            $res = $runner->requeueChunk($this->job($jobId), 0.0);
            $total += $res['requeued'];
        }
        $this->assertTrue($res['done']);
        $this->assertSame(5, $total);
        $this->assertSame(5, $this->runner()->runChunk($this->job($jobId))->converted);
    }

    #[Test]
    public function 再実行を最初からやり直す指定では_前回途中のカーソルを捨てて取りこぼさない(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertColumn('r' . $i, 'image', ['column_field_2' => "001/r{$i}.jpg"]);
            $this->archives->put("001/r{$i}.jpg", 'R' . $i);
        }
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->media->failRegister = true;
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $this->media->failRegister = false;
        $first = $runner->requeueChunk($this->job($jobId), 0.0);
        $this->assertFalse($first['done']);
        // 途中で止まったあと、先頭側のアイテムがまた失敗した（カーソルより前）
        $items = $this->items()->byStatuses($jobId, [ItemStatus::Pending], 0, 10);
        $this->assertNotEmpty($items);
        $this->items()->markStatus($items[0]->id, ItemStatus::Skipped, ReasonCode::EntryLocked);

        $res = $runner->requeueChunk($this->job($jobId), 600.0, true);

        $this->assertTrue($res['done']);
        $this->assertSame(0, $this->items()->countByStatus($jobId)[ItemStatus::Skipped->value] ?? 0, '最初から読み直すので、先頭側の失敗分も戻る');
    }

    #[Test]
    public function 戻せないアイテムばかりでも_再実行は時間の予算を守って区切る(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->insertColumn('v' . $i, 'image', ['column_field_2' => "001/v{$i}.jpg"]);
            $this->archives->put("001/v{$i}.jpg", 'V' . $i);
        }
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->media->failRegister = true;
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $this->db->exec('DELETE FROM ' . $this->db->t('column') . ' WHERE column_id LIKE ?', ['v%']);

        $first = $runner->requeueChunk($this->job($jobId), 0.0, true);

        $this->assertSame(0, $first['requeued']);
        $this->assertFalse($first['done'], '1 件も戻せなくても、予算が尽きたら区切って返す');
        $res = $first;
        for ($i = 0; $i < 10 && !$res['done']; $i++) {
            $res = $runner->requeueChunk($this->job($jobId), 0.0);
        }
        $this->assertTrue($res['done'], '続けて呼べば最後まで進む');
    }

    #[Test]
    public function 再実行は_読み込んだあとに別のタブで確定されたジョブを一時停止に戻さない(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->media->failRegister = true;
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $stale = $this->job($jobId);
        $this->assertSame(JobStatus::Completed, $stale->status);
        $this->media->failRegister = false;
        $this->db->exec('UPDATE ' . $this->db->t('a2m_job') . ' SET job_status = ? WHERE job_id = ?', ['finalized', $jobId]);

        $runner->requeueChunk($stale, 600.0, true);

        $this->assertSame(JobStatus::Finalized, $this->job($jobId)->status);
    }

    #[Test]
    public function 行が無くなって戻せないアイテムがあっても_再実行は終わる(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->insertColumn('gone', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('stay', 'image', ['column_field_2' => '001/b.jpg']);
        $this->archives->put('001/b.jpg', 'B');
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->media->failRegister = true;
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $this->db->exec('DELETE FROM ' . $this->db->t('column') . ' WHERE column_id = ?', ['gone']);

        $this->media->failRegister = false;
        $total = 0;
        $res = ['requeued' => 0, 'done' => false];
        for ($i = 0; $i < 5; $i++) {
            $res = $runner->requeueChunk($this->job($jobId), 0.0);
            $total += $res['requeued'];
            if ($res['done']) {
                break;
            }
        }
        $this->assertTrue($res['done']);
        $this->assertSame(1, $total);
    }

    #[Test]
    public function 再実行するものが無ければrequeueは0(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $this->runner()->runChunk($this->job($jobId));
        $this->assertSame(0, $this->runner()->requeueChunk($this->job($jobId), 600.0, true)['requeued']);
        $this->assertSame(JobStatus::Completed, $this->job($jobId)->status);
    }

    #[Test]
    public function 別の処理が実行中なら拒否する(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $factory = $this->lockFactory();
        $held = $factory();
        $this->assertInstanceOf(Lock::class, $held);
        $this->assertTrue($held->tryLock());

        try {
            $this->runner(null, $factory)->runChunk($this->job($jobId));
            $this->fail('例外が必要');
        } catch (JobBusyException) {
            $this->assertSame(JobStatus::Scanned, $this->job($jobId)->status);
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function 処理後はロックが解放される(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['chunkSize' => 1]);
        $runner = $this->runner();
        $runner->runChunk($this->job($jobId));
        $probe = ($this->lockFactory())();
        $this->assertInstanceOf(Lock::class, $probe);
        $this->assertFalse($probe->isLocked());
    }

    #[Test]
    public function 時間切れでも最低1件は進める(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob();
        $res = $this->runner()->runChunk($this->job($jobId), -1.0);
        $this->assertSame(1, $res->processed);
        $this->assertFalse($res->done);
    }

    #[Test]
    public function 子ブログのデータはそのブログ所有のメディアになる(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $child = $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $childEntry = $this->createTestEntry($child, null);
        $this->archives->put('001/a.jpg', 'A');
        $this->insertColumn('cu', 'image', ['column_field_2' => '001/a.jpg'], $childEntry, $child);
        $id = $this->jobs()->create($parent, 1, JobOptions::fromArray(['includeChildBlogs' => true]));
        $store = new \Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore($this->db);
        $coordinator = new \Acms\Plugins\ArchivesToMedia\Services\Job\ScanCoordinator(
            [new \Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitImageScanner($this->db, $this->blogScope())],
            [$this->unitImageConverter()],
            $this->resolver(),
            $this->items(),
            $this->jobs(),
        );
        unset($store);
        $coordinator->scanChunk($this->job($id));
        $this->runner()->runChunk($this->job($id));

        $mid = Cast::int($this->columnRow('cu')['column_field_1']);
        $this->assertSame((string) $child, Cast::str($this->db->one('SELECT media_blog_id FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$mid])));
    }

    #[Test]
    public function スキャン後に差し替えられたユニットは_再実行で新しいパスを変換する(): void
    {
        $this->archives->put('001/a.jpg', 'OLD');
        $this->archives->put('002/new.jpg', 'NEW');
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->db->exec('UPDATE ' . $this->db->t('column') . ' SET column_field_2 = ? WHERE column_id = ?', ['002/new.jpg', 'u1']);

        $res = $this->runner()->runChunk($this->job($jobId));
        $this->assertSame('STALE_SNAPSHOT', $res->log[0]['reason']);

        $this->assertSame(1, $this->runner()->requeueChunk($this->job($jobId), 600.0, true)['requeued']);
        $item = $this->items()->byStatuses($jobId, [ItemStatus::Pending], 0, 1)[0];
        $this->assertSame(['002/new.jpg'], $item->sourcePaths, '再実行で現在のパスに更新される');

        $this->runner()->runChunk($this->job($jobId));
        $mid = Cast::int($this->columnRow('u1')['column_field_1']);
        $this->assertSame(hash('sha256', 'NEW'), $this->db->one('SELECT media_hash FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$mid]));
    }

    #[Test]
    public function 既に変換済みのユニットは_二重に変換しない(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->insertColumn('u1', 'image', ['column_field_1' => 'cap', 'column_field_2' => '001/a.jpg']);
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $this->runner()->runChunk($this->job($jobId));
        $converted = $this->columnRow('u1');
        $item = $this->items()->byStatuses($jobId, [ItemStatus::Converted], 0, 1)[0];
        $this->items()->markStatus($item->id, ItemStatus::Error, \Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode::UnknownError);
        $this->items()->requeue($item->id, (array) $this->unitImageConverter()->snapshot($item->locator, JobOptions::defaults()), $item->sourcePaths);
        $this->jobs()->setStatus($jobId, JobStatus::Paused);

        $res = $this->runner()->runChunk($this->job($jobId));

        $this->assertSame(1, $res->skipped);
        $this->assertEquals($converted, $this->columnRow('u1'), '変換済みの行は書き換わらない');
    }

    #[Test]
    public function トランザクション内でも編集ロックを再確認する(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $guard = new class ($this->db) extends \Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard {
            private int $calls = 0;

            public function __construct(\Acms\Plugins\ArchivesToMedia\Services\Support\Db $db)
            {
                parent::__construct($db, new \Acms\Services\Entry\Lock('on', 'off', 48));
            }

            public function isLocked(\Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface $locator): bool
            {
                // 1 回目（書き込み前）は未ロック、2 回目（トランザクション内）でロックされた状況を作る
                return ++$this->calls >= 2;
            }
        };
        $runner = new \Acms\Plugins\ArchivesToMedia\Services\Job\JobRunner(
            [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()],
            $this->resolver(),
            $this->items(),
            $this->jobs(),
            $guard,
            new \Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator($this->db),
            $this->db,
            $this->lockFactory(),
        );

        $res = $runner->runChunk($this->job($jobId));

        $this->assertSame('ENTRY_LOCKED', $res->log[0]['reason']);
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
    }

    #[Test]
    public function 書き込む前に対象行をロックしてから再照合する(): void
    {
        $this->seedBasic();
        $jobId = $this->scannedJob(['targets' => ['unit_image']]);
        $rec = new RecordingConverter($this->unitImageConverter());

        $res = $this->runner([$rec, $this->unitFileConverter()])->runChunk($this->job($jobId));

        $this->assertSame(1, $res->converted);
        // 事前確認 → ロック → トランザクション内の再照合 → 書き込み
        $this->assertSame(['snapshot', 'currentSourcePaths', 'lock', 'snapshot', 'apply'], $rec->calls);
    }
}
