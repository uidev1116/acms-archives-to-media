<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminApi;
use Acms\Plugins\ArchivesToMedia\Services\Admin\DefinitionProvider;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobNotFoundException;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobService;
use Acms\Plugins\ArchivesToMedia\Services\Job\ScanCoordinator;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class JobServiceTest extends A2mTestCase
{
    private function service(): JobService
    {
        $store = new FieldStore($this->db);
        $names = new FieldNameResolver();
        $converters = [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()];
        $coordinator = new ScanCoordinator(
            [
                new UnitImageScanner($this->db, $this->blogScope()),
                new UnitFileScanner($this->db, $this->blogScope()),
                new FieldImageScanner($this->db, $this->blogScope(), $store, $names),
                new FieldFileScanner($this->db, $this->blogScope(), $store, $names),
            ],
            $converters,
            $this->resolver(),
            $this->items(),
            $this->jobs(),
        );
        return new JobService(
            $this->jobs(),
            $this->items(),
            $coordinator,
            $this->runner($converters),
            $this->rollbacker($converters),
            new AuditLog(),
        );
    }

    private function api(): AdminApi
    {
        return new AdminApi($this->service(), new DefinitionProvider($this->db, $this->blogScope(), static fn (): array => ['media', 'media_2']));
    }

    private function seed(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->archives->put('002/doc.pdf', 'PDF');
        $this->archives->put('003/h.jpg', 'H');
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('u2', 'file', ['column_field_2' => '002/doc.pdf']);
        $this->insertColumn('u3', 'image', ['column_field_2' => '001/gone.jpg']);
        $this->insertImageField('hero', '003/h.jpg');
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(int $jobId): array
    {
        for ($i = 0; $i < 50; $i++) {
            $res = $this->service()->scan($jobId, $this->blogId);
            if ($res['done']) {
                return $res;
            }
        }
        $this->fail('scan');
    }

    #[Test]
    public function ジョブを作成するとドラフトになり_不正なオプションは拒否される(): void
    {
        $job = $this->service()->create($this->blogId, 7, ['targets' => ['unit_image']]);
        $this->assertSame(JobStatus::Draft, $job->status);
        $this->assertSame(7, $job->userId);
        $this->assertSame($this->blogId, $job->blogId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create($this->blogId, 7, ['targets' => ['nope']]);
    }

    #[Test]
    public function 他のブログのジョブには触れない(): void
    {
        $job = $this->service()->create($this->blogId, 1, []);
        $other = $this->createTestBlog();
        $calls = [
            fn () => $this->service()->get($job->id, $other),
            fn () => $this->service()->scan($job->id, $other),
            fn () => $this->service()->run($job->id, $other),
            fn () => $this->service()->rollback($job->id, $other, false),
            fn () => $this->service()->delete($job->id, $other),
            fn () => $this->service()->exclude($job->id, $other, [1], null, true),
            fn () => $this->service()->items($job->id, $other, [], 'item_id', false, 1, 10),
            fn () => $this->service()->exportCsv($job->id, $other),
            fn () => $this->service()->get(99999999, $this->blogId),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('JobNotFoundException が必要');
            } catch (JobNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotNull($this->jobs()->find($job->id));
    }

    #[Test]
    public function スキャン_確認_実行_ロールバックの一連の流れ(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);

        $scan = $this->scan($job->id);
        $this->assertSame(4, $scan['total']);

        $detail = $this->service()->get($job->id, $this->blogId);
        $this->assertSame('scanned', $detail['job']['status']);
        $this->assertNotEmpty($detail['summary']);
        $this->assertSame(['pending' => 3, 'skipped' => 1], $detail['counts']);

        $run = $this->service()->run($job->id, $this->blogId);
        $this->assertTrue($run->done);
        $this->assertSame(3, $run->converted);
        $this->assertSame('media', $this->columnRow('u1')['column_type']);

        $rb = $this->service()->rollback($job->id, $this->blogId, false);
        $this->assertTrue($rb->done);
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
        $this->assertSame(JobStatus::RolledBack, $this->jobs()->find($job->id)?->status);
    }

    #[Test]
    public function オプションを変えて再スキャンできる_実行後は変更できない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);

        $updated = $this->service()->updateOptions($job->id, $this->blogId, ['targets' => ['field_image'], 'chunkSize' => 50]);
        $this->assertSame(JobStatus::Draft, $updated->status);
        $this->assertSame([], $this->items()->countByStatus($job->id));
        $this->assertSame(1, $this->scan($job->id)['total']);

        $this->service()->run($job->id, $this->blogId);
        $this->expectException(\LogicException::class);
        $this->service()->updateOptions($job->id, $this->blogId, []);
    }

    #[Test]
    public function 不正なオプション更新は元のオプションを壊さない(): void
    {
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 7]);
        try {
            $this->service()->updateOptions($job->id, $this->blogId, ['targets' => ['x']]);
            $this->fail('例外が必要');
        } catch (\InvalidArgumentException) {
            $this->assertSame(7, $this->jobs()->find($job->id)?->options->chunkSize);
        }
    }

    #[Test]
    public function 除外はidでも絞り込みでも指定でき_実行から外れる(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);

        $this->assertSame(1, $this->service()->exclude($job->id, $this->blogId, null, ['target' => 'unit_file'], true));
        $first = $this->items()->nextPending($job->id, 1)[0];
        $this->assertSame(1, $this->service()->exclude($job->id, $this->blogId, [$first->id], null, true));
        $this->assertSame(0, $this->service()->exclude($job->id, $this->blogId, [], null, true));

        $run = $this->service()->run($job->id, $this->blogId);
        $this->assertSame(1, $run->converted);
        $this->assertSame(2, $this->items()->countByStatus($job->id)[ItemStatus::Excluded->value]);
    }

    #[Test]
    public function 実行中のジョブは削除できず_変換済みがあるジョブも削除できない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);

        try {
            $this->service()->delete($job->id, $this->blogId);
            $this->fail('例外が必要');
        } catch (\LogicException) {
            $this->assertNotNull($this->jobs()->find($job->id));
        }

        $this->service()->rollback($job->id, $this->blogId, false);
        $this->service()->delete($job->id, $this->blogId);
        $this->assertNull($this->jobs()->find($job->id));
    }

    #[Test]
    public function 変換済みが無ければ削除できる(): void
    {
        $job = $this->service()->create($this->blogId, 1, []);
        $this->service()->delete($job->id, $this->blogId);
        $this->assertNull($this->jobs()->find($job->id));
    }

    #[Test]
    public function 一覧とアイテム検索_スキップ分の再実行(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);

        $list = $this->service()->list($this->blogId, 1, 10);
        $this->assertSame(1, $list['total']);
        $this->assertSame($job->id, $list['jobs'][0]['id']);

        $items = $this->service()->items($job->id, $this->blogId, ['status' => 'skipped'], 'item_id', false, 1, 10);
        $this->assertSame(1, $items['total']);
        $row = $items['items'][0];
        $this->assertSame('SOURCE_NOT_FOUND', $row['reason']);
        $this->assertSame(['001/gone.jpg'], $row['sourcePaths']);
        $this->assertSame('unit_image', $row['target']);
        $this->assertSame('entry', $row['ownerType']);
        $this->assertSame('元ファイルが見つかりません', $row['reasonLabel']);

        $this->archives->put('001/gone.jpg', 'G');
        $this->assertSame(1, $this->service()->requeue($job->id, $this->blogId));
        $this->assertSame(1, $this->service()->run($job->id, $this->blogId)->converted);
    }

    #[Test]
    public function CSVは見出し付きで_数式として解釈されうる値を無害化する(): void
    {
        $this->seed();
        $this->insertColumn('u9', 'image', ['column_field_2' => '=cmd|calc.jpg']);
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);

        $csv = $this->service()->exportCsv($job->id, $this->blogId);
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('source_path', $lines[0]);
        $this->assertGreaterThanOrEqual(6, count($lines));
        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString("'=cmd|calc.jpg", $csv);
        $this->assertStringContainsString('SOURCE_NOT_FOUND', $csv);
    }

    #[Test]
    public function 定義の取得_メディアユニット_元ユニット種類_フィールド名(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => 'a.jpg']);
        $this->insertColumn('u2', 'image_2', ['column_field_2' => 'b.jpg']);
        $this->insertColumn('u3', 'image', ['column_field_2' => 'c.jpg']);
        $this->insertColumn('f1', 'file', ['column_field_2' => 'd.pdf']);
        $this->insertColumn('e1', 'image', ['column_field_2' => '']);
        $this->insertColumn('t1', 'text', ['column_field_2' => 'x']);
        $this->insertImageField('hero', 'a.jpg');
        $this->insertFileField('doc', 'd.pdf', 'd.pdf');

        $def = (new DefinitionProvider($this->db, $this->blogScope(), static fn (): array => ['media', 'media_2']))->get($this->blogId);

        $this->assertSame(['media', 'media_2'], $def['mediaUnitTypes']);
        $this->assertSame([['type' => 'image', 'count' => 2], ['type' => 'image_2', 'count' => 1], ['type' => 'file', 'count' => 1]], $def['sourceUnitTypes']);
        $this->assertSame(['hero'], $def['fieldKeys']['image']);
        $this->assertSame(['doc'], $def['fieldKeys']['file']);
    }

    #[Test]
    public function 定義のメディアユニットが空なら_mediaを返す(): void
    {
        $def = (new DefinitionProvider($this->db, $this->blogScope(), static fn (): array => []))->get($this->blogId);
        $this->assertSame(['media'], $def['mediaUnitTypes']);
    }

    #[Test]
    public function AdminApiは操作名で振り分け_JSONにできる配列を返す(): void
    {
        $this->seed();
        $api = $this->api();

        $created = $api->handle('create', ['options' => json_encode(['chunkSize' => 50])], $this->blogId, 1);
        $jobId = $created['job']['id'];
        $this->assertSame('draft', $created['job']['status']);

        $scan = ['done' => false];
        for ($i = 0; $i < 20 && !$scan['done']; $i++) {
            $scan = $api->handle('scan', ['jobId' => (string) $jobId], $this->blogId, 1);
        }
        $this->assertSame(4, $scan['total']);

        $this->assertSame(1, $api->handle('exclude', ['jobId' => $jobId, 'filter' => json_encode(['target' => 'unit_file']), 'excluded' => '1'], $this->blogId, 1)['updated']);
        $run = $api->handle('run', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertTrue($run['done']);
        $this->assertSame(2, $run['converted']);

        $this->assertSame(1, $api->handle('jobs', ['page' => '1'], $this->blogId, 1)['total']);
        $this->assertSame($jobId, $api->handle('job', ['jobId' => $jobId], $this->blogId, 1)['job']['id']);
        $this->assertSame(4, $api->handle('items', ['jobId' => $jobId, 'perPage' => '50'], $this->blogId, 1)['total']);
        $this->assertSame(['media', 'media_2'], $api->handle('definitions', [], $this->blogId, 1)['mediaUnitTypes']);

        $this->assertSame('paused', $api->handle('pause', ['jobId' => $jobId], $this->blogId, 1)['status'] ?? 'paused');
        $rb = $api->handle('rollback', ['jobId' => $jobId, 'deleteCreatedMedia' => '1'], $this->blogId, 1);
        $this->assertTrue($rb['done']);
        $this->assertNotSame([], $this->media->deleted);

        $this->assertSame(['deleted' => true], $api->handle('delete', ['jobId' => $jobId], $this->blogId, 1));
        $json = json_encode($created);
        $this->assertNotFalse($json);
    }

    #[Test]
    public function AdminApiは再実行とオプション更新も扱う(): void
    {
        $this->seed();
        $api = $this->api();
        $jobId = $api->handle('create', ['options' => '{"chunkSize":50}'], $this->blogId, 1)['job']['id'];
        $api->handle('update', ['jobId' => $jobId, 'options' => '{"targets":["unit_file"],"chunkSize":50}'], $this->blogId, 1);
        $scan = $api->handle('scan', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertSame(1, $scan['total']);
        $api->handle('run', ['jobId' => $jobId], $this->blogId, 1);
        $again = $api->handle('requeue', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertSame(0, $again['requeued']);
        $this->assertSame('completed', $again['job']['status']);
    }

    #[Test]
    public function スキャン直後のジョブはスキップ分の再実行を受け付けない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->expectException(\LogicException::class);
        $this->service()->requeue($job->id, $this->blogId);
    }

    #[Test]
    public function AdminApiは不正な入力を拒否する(): void
    {
        $api = $this->api();
        $bad = [
            ['unknown', []],
            ['job', []],
            ['job', ['jobId' => 'abc']],
            ['job', ['jobId' => '0']],
            ['create', ['options' => 'not json']],
            ['create', ['options' => '[1,2]x']],
            ['exclude', ['jobId' => '1', 'ids' => 'x']],
        ];
        foreach ($bad as [$action, $input]) {
            try {
                $api->handle($action, $input, $this->blogId, 1);
                $this->fail($action . ' は拒否されるべき');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function AdminApiのjobsは不正なページ番号を1に丸める(): void
    {
        $this->assertSame(0, $this->api()->handle('jobs', ['page' => '-5'], $this->blogId, 1)['total']);
    }
}
