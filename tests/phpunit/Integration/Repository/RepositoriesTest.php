<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Repository;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\TestingFramework\Seeder\MediaSeeder;
use PHPUnit\Framework\Attributes\Test;

final class RepositoriesTest extends A2mTestCase
{
    private function candidate(string $columnId = 'c1', string $path = '001/a.jpg'): Candidate
    {
        return new Candidate(TargetType::UnitImage, new ColumnLocator($columnId), 'entry', $this->entryId, $this->blogId, [$path]);
    }

    #[Test]
    public function ジョブを作って取得できる(): void
    {
        $id = $this->jobs()->create($this->blogId, 3, JobOptions::defaults());
        $job = $this->jobs()->find($id);

        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Draft, $job->status);
        $this->assertSame($this->blogId, $job->blogId);
        $this->assertSame(3, $job->userId);
        $this->assertSame('auto', $job->options->enlarged);
        $this->assertNull($job->startedAt);
        $this->assertSame($id, $job->toArray()['id']);
        $this->assertNull($this->jobs()->find(999999999));
    }

    #[Test]
    public function 状態遷移は期待した状態のときだけ成功する(): void
    {
        $id = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());

        $this->assertFalse($this->jobs()->transition($id, [JobStatus::Scanned], JobStatus::Running));
        $this->assertTrue($this->jobs()->transition($id, [JobStatus::Draft, JobStatus::Scanned], JobStatus::Scanning));
        $this->assertSame(JobStatus::Scanning, $this->jobs()->find($id)?->status);
    }

    #[Test]
    public function 状態の更新で開始_終了時刻とメッセージが入る(): void
    {
        $id = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->jobs()->setStatus($id, JobStatus::Running);
        $started = $this->jobs()->find($id)?->startedAt;
        $this->assertNotNull($started);

        $this->jobs()->setStatus($id, JobStatus::Running);
        $this->assertSame($started, $this->jobs()->find($id)?->startedAt);

        $this->jobs()->setStatus($id, JobStatus::Failed, 'boom');
        $job = $this->jobs()->find($id);
        $this->assertSame('boom', $job?->message);
        $this->assertNotNull($job->finishedAt);
    }

    #[Test]
    public function オプションとカーソルを更新できる(): void
    {
        $id = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->jobs()->setOptions($id, JobOptions::fromArray(['chunkSize' => 7]));
        $this->jobs()->setCursor($id, '{"x":1}');
        $job = $this->jobs()->find($id);
        $this->assertSame(7, $job?->options->chunkSize);
        $this->assertSame('{"x":1}', $job->scanCursor);
    }

    #[Test]
    public function 一覧はブログごとに新しい順でページングできる(): void
    {
        $a = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $b = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->jobs()->create($this->blogId + 1, 1, JobOptions::defaults());

        $page = $this->jobs()->paginate($this->blogId, 1, 1);
        $this->assertSame(2, $page['total']);
        $this->assertSame($b, $page['jobs'][0]->id);
        $page2 = $this->jobs()->paginate($this->blogId, 2, 1);
        $this->assertSame($a, $page2['jobs'][0]->id);
    }

    #[Test]
    public function アイテムの登録は同じ所在なら重複しない(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $first = $this->items()->insert($jobId, $this->candidate(), 'new', ['row' => ['a' => '1']]);
        $second = $this->items()->insert($jobId, $this->candidate(), 'new', ['row' => ['a' => '1']]);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $item = $this->items()->find($first);
        $this->assertSame(ItemStatus::Pending, $item?->status);
        $this->assertSame(['001/a.jpg'], $item->sourcePaths);
        $this->assertSame(['row' => ['a' => '1']], $item->before);
        $this->assertNull($item->after);
        $this->assertSame([], $item->mediaIds);
        $this->assertNull($this->items()->find(999999999));
    }

    #[Test]
    public function 変換済みの記録とスキップの記録(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $a = (int) $this->items()->insert($jobId, $this->candidate('c1'), 'new', []);
        $b = (int) $this->items()->insert($jobId, $this->candidate('c2'), 'skip', [], ItemStatus::Skipped, ReasonCode::SourceNotFound, 'nf');

        $this->items()->markConverted($a, [5, 6], ['row' => ['x' => 'y']], ReasonCode::AltMismatch, 'warn');
        $done = $this->items()->find($a);
        $this->assertSame(ItemStatus::Converted, $done?->status);
        $this->assertSame([5, 6], $done->mediaIds);
        $this->assertSame('ALT_MISMATCH', $done->reason);
        $this->assertSame(['row' => ['x' => 'y']], $done->after);

        $skipped = $this->items()->find($b);
        $this->assertSame(ItemStatus::Skipped, $skipped?->status);
        $this->assertSame('SOURCE_NOT_FOUND', $skipped->reason);
        $this->assertSame('nf', $skipped->message);

        $this->items()->markStatus($a, ItemStatus::RolledBack);
        $this->assertSame(ItemStatus::RolledBack, $this->items()->find($a)?->status);
    }

    #[Test]
    public function 次のpendingとconvertedの取り出し順(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $ids = [];
        foreach (['c1', 'c2', 'c3'] as $c) {
            $ids[] = (int) $this->items()->insert($jobId, $this->candidate($c), 'new', []);
        }
        $pending = $this->items()->nextPending($jobId, 2);
        $this->assertSame([$ids[0], $ids[1]], array_map(static fn ($i) => $i->id, $pending));

        foreach ($ids as $id) {
            $this->items()->markConverted($id, [1], []);
        }
        $converted = $this->items()->nextConverted($jobId, 10);
        $this->assertSame([$ids[2], $ids[1], $ids[0]], array_map(static fn ($i) => $i->id, $converted));
    }

    #[Test]
    public function 除外の切り替えはpendingとexcludedの間だけ(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $a = (int) $this->items()->insert($jobId, $this->candidate('c1'), 'new', []);
        $b = (int) $this->items()->insert($jobId, $this->candidate('c2'), 'new', []);
        $this->items()->markConverted($b, [1], []);

        $this->assertSame(0, $this->items()->setExcluded($jobId, [], true));
        $this->assertSame(1, $this->items()->setExcluded($jobId, [$a, $b], true));
        $this->assertSame(ItemStatus::Excluded, $this->items()->find($a)?->status);
        $this->assertSame(ItemStatus::Converted, $this->items()->find($b)?->status);
        $this->assertSame([], $this->items()->nextPending($jobId, 10));

        $this->assertSame(1, $this->items()->setExcluded($jobId, [$a], false));
        $this->assertSame(ItemStatus::Pending, $this->items()->find($a)?->status);
    }

    #[Test]
    public function 絞り込み条件での一括除外と検索(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->items()->insert($jobId, $this->candidate('c1', '001/a.jpg'), 'new', []);
        $this->items()->insert($jobId, $this->candidate('c2', '002/日本語.jpg'), 'reuse', []);
        $field = new Candidate(TargetType::FieldFile, new FieldLocator('doc', 1, 'eid', $this->entryId, null, $this->blogId), 'entry', $this->entryId, $this->blogId, ['f/doc.pdf']);
        $this->items()->insert($jobId, $field, 'new', []);

        $this->assertSame(1, $this->items()->search($jobId, ['target' => 'field_file'], 'item_id', false, 1, 10)['total']);
        $this->assertSame(1, $this->items()->search($jobId, ['preview' => 'reuse'], 'item_id', false, 1, 10)['total']);
        $this->assertSame(1, $this->items()->search($jobId, ['q' => '日本語'], 'item_id', false, 1, 10)['total']);
        $this->assertSame(1, $this->items()->search($jobId, ['q' => '002/'], 'item_id', false, 1, 10)['total']);
        $res = $this->items()->search($jobId, ['status' => 'pending'], 'item_target', true, 1, 2);
        $this->assertSame(3, $res['total']);
        $this->assertCount(2, $res['items']);
        $this->assertSame('unit_image', $res['items'][1]['item_target']);

        $changed = $this->items()->setExcludedByFilter($jobId, ['target' => 'unit_image'], true);
        $this->assertSame(2, $changed);
        $this->assertSame(['excluded' => 2, 'pending' => 1], $this->items()->countByStatus($jobId));
        $this->assertSame(2, $this->items()->setExcludedByFilter($jobId, ['target' => 'unit_image'], false));
    }

    #[Test]
    public function 不正な並び替え列は無視される(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->items()->insert($jobId, $this->candidate(), 'new', []);
        $res = $this->items()->search($jobId, [], 'item_id; DROP TABLE x', false, 1, 10);
        $this->assertSame(1, $res['total']);
    }

    #[Test]
    public function 再実行用にスキップとエラーをpendingへ戻す(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $a = (int) $this->items()->insert($jobId, $this->candidate('c1'), 'skip', [], ItemStatus::Skipped, ReasonCode::EntryLocked);
        $b = (int) $this->items()->insert($jobId, $this->candidate('c2'), 'new', []);
        $this->items()->markConverted($b, [1], []);

        $this->items()->requeue($a, ['row' => ['fresh' => '1']]);
        $this->items()->requeue($b, ['row' => ['nope' => '1']]);

        $this->assertSame(ItemStatus::Pending, $this->items()->find($a)?->status);
        $this->assertSame('', $this->items()->find($a)->reason);
        $this->assertSame(['row' => ['fresh' => '1']], $this->items()->find($a)?->before);
        $this->assertSame(ItemStatus::Converted, $this->items()->find($b)?->status);
    }

    #[Test]
    public function 集計とサマリーとジョブの再計算(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $a = (int) $this->items()->insert($jobId, $this->candidate('c1'), 'new', []);
        $this->items()->insert($jobId, $this->candidate('c2'), 'skip', [], ItemStatus::Skipped, ReasonCode::SourceNotFound);
        $this->items()->insert($jobId, $this->candidate('c3'), 'new', [], ItemStatus::Error, ReasonCode::UnknownError);
        $this->items()->markConverted($a, [1], []);

        $this->jobs()->refreshCounts($jobId);
        $job = $this->jobs()->find($jobId);
        $this->assertSame([3, 1, 1, 1], [$job?->total, $job?->converted, $job?->skipped, $job?->error]);

        $summary = $this->items()->summary($jobId);
        $this->assertCount(3, $summary);

        $byStatus = $this->items()->byStatuses($jobId, [ItemStatus::Skipped, ItemStatus::Error], 0, 10);
        $this->assertCount(2, $byStatus);
        $this->assertCount(1, $this->items()->byStatuses($jobId, [ItemStatus::Skipped, ItemStatus::Error], $byStatus[0]->id, 10));
    }

    #[Test]
    public function ジョブを削除するとアイテムも消える(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, JobOptions::defaults());
        $this->items()->insert($jobId, $this->candidate(), 'new', []);
        $this->jobs()->delete($jobId);
        $this->assertNull($this->jobs()->find($jobId));
        $this->assertSame([], $this->items()->countByStatus($jobId));
    }

    #[Test]
    public function パス対応表は削除済みメディアを無視し_上書きできる(): void
    {
        $map = new PathMapRepository($this->db);
        $mid = MediaSeeder::seed($this->blogId);

        $this->assertNull($map->find($this->blogId, '001/a.jpg'));
        $map->save($this->blogId, '001/a.jpg', $mid, 12);
        $this->assertSame($mid, $map->find($this->blogId, '001/a.jpg'));
        $this->assertSame([$mid], $map->mediaIdsCreatedByJob(12));
        $this->assertNull($map->find($this->blogId + 1, '001/a.jpg'));

        $other = MediaSeeder::seed($this->blogId);
        $map->save($this->blogId, '001/a.jpg', $other, null);
        $this->assertSame($other, $map->find($this->blogId, '001/a.jpg'));
        $this->assertSame([], $map->mediaIdsCreatedByJob(12));

        $this->db->exec('DELETE FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$other]);
        $this->assertNull($map->find($this->blogId, '001/a.jpg'));

        $map->save($this->blogId, '002/b.jpg', $mid, 12);
        $map->deleteByMedia($mid);
        $this->assertSame([], $map->mediaIdsCreatedByJob(12));
    }

    #[Test]
    public function パス対応表は大文字小文字を区別する(): void
    {
        $map = new PathMapRepository($this->db);
        $a = MediaSeeder::seed($this->blogId);
        $b = MediaSeeder::seed($this->blogId);
        $map->save($this->blogId, '001/Photo.JPG', $a, 1);
        $map->save($this->blogId, '001/photo.jpg', $b, 1);

        $this->assertSame($a, $map->find($this->blogId, '001/Photo.JPG'));
        $this->assertSame($b, $map->find($this->blogId, '001/photo.jpg'));
    }
}
