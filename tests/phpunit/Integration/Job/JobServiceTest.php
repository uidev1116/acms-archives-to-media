<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminApi;
use Acms\Plugins\ArchivesToMedia\Services\Admin\DefinitionProvider;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Job\ForbiddenException;
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
    /** @var \Closure(int): bool */
    private \Closure $canAdminister;

    private function coordinator(): ScanCoordinator
    {
        $store = new FieldStore($this->db);
        $names = new FieldNameResolver();
        return new ScanCoordinator(
            [
                new UnitImageScanner($this->db, $this->blogScope()),
                new UnitFileScanner($this->db, $this->blogScope()),
                new FieldImageScanner($this->db, $this->blogScope(), $store, $names),
                new FieldFileScanner($this->db, $this->blogScope(), $store, $names),
            ],
            [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()],
            $this->resolver(),
            $this->items(),
            $this->jobs(),
        );
    }

    /** @var (\Closure(): list<string>)|null */
    private ?\Closure $mediaUnitTypes = null;

    private function service(): JobService
    {
        $this->canAdminister ??= static fn (int $blogId): bool => true;
        $converters = [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()];
        $coordinator = $this->coordinator();
        return new JobService(
            $this->jobs(),
            $this->items(),
            $coordinator,
            $this->runner($converters),
            $this->rollbacker($converters),
            $this->cleaner(),
            new AuditLog(),
            $this->blogScope(),
            $this->canAdminister,
            $this->mediaUnitTypes,
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
    public function 子ブログを含めるジョブは_子孫ブログすべての管理権限が必要(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 6]);
        $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $childB = $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 4, 'blog_right' => 5]);
        $checked = [];
        $this->canAdminister = static function (int $blogId) use (&$checked, $childB): bool {
            $checked[] = $blogId;
            return $blogId !== $childB;
        };

        try {
            $this->service()->create($parent, 1, ['includeChildBlogs' => true]);
            $this->fail('権限が無い子ブログがあるので拒否される');
        } catch (ForbiddenException) {
            $this->assertContains($childB, $checked);
            $this->assertSame(0, $this->jobs()->paginate($parent, 1, 10)['total'], 'ジョブは作られない');
        }

        $this->canAdminister = static fn (int $blogId): bool => true;
        $job = $this->service()->create($parent, 1, ['includeChildBlogs' => true]);
        $this->assertTrue($job->options->includeChildBlogs);
    }

    #[Test]
    public function 子ブログを含めるジョブは_権限を失ったユーザーが実行系の操作をしても拒否される(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $child = $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $this->canAdminister = static fn (int $blogId): bool => true;
        $job = $this->service()->create($parent, 1, ['includeChildBlogs' => true]);

        // 別の管理者（親ブログだけ管理できる）が、同じジョブを操作しようとする
        $this->canAdminister = static fn (int $blogId): bool => $blogId !== $child;
        $service = $this->service();
        $operations = [
            'scan' => fn () => $service->scan($job->id, $parent),
            'run' => fn () => $service->run($job->id, $parent),
            'requeue' => fn () => $service->requeueChunk($job->id, $parent),
            'rollback' => fn () => $service->rollback($job->id, $parent, false),
            'cleanup' => fn () => $service->cleanup($job->id, $parent),
            'exclude' => fn () => $service->exclude($job->id, $parent, null, ['q' => 'a'], true),
        ];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $this->fail($name . ' は拒否されるはず');
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
        // 読み取りと一時停止、削除は妨げない
        $this->assertSame($job->id, $service->get($job->id, $parent)['job']['id']);
        $service->delete($job->id, $parent);
    }

    #[Test]
    public function 子ブログを含めるジョブの一覧とCSVは_管理権限の無い子ブログの行を出さない(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $child = $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $parentEntry = $this->createTestEntry($parent, null);
        $childEntry = $this->createTestEntry($child, null);
        $this->insertColumn('pa', 'image', ['column_field_2' => 'p.jpg'], $parentEntry, $parent);
        $this->insertColumn('ch', 'image', ['column_field_2' => 'secret/c.jpg'], $childEntry, $child);

        $this->canAdminister = static fn (int $blogId): bool => true;
        $job = $this->service()->create($parent, 1, ['includeChildBlogs' => true, 'chunkSize' => 50]);
        $this->service()->scan($job->id, $parent);
        $this->assertSame(2, $this->service()->items($job->id, $parent, [], 'item_id', false, 1, 50)['total']);

        // 別の管理者（親ブログだけ管理できる）が見る
        $this->canAdminister = static fn (int $blogId): bool => $blogId !== $child;
        $service = $this->service();
        $items = $service->items($job->id, $parent, [], 'item_id', false, 1, 50);
        $this->assertSame(1, $items['total']);
        $this->assertSame([$parent], array_unique(array_map(static fn (array $i): int => Cast::int($i['blogId']), $items['items'])));

        $stream = $this->memoryStream();
        $service->writeCsv($job->id, $parent, $stream);
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        $this->assertStringContainsString('p.jpg', $csv);
        $this->assertStringNotContainsString('secret/c.jpg', $csv);
    }

    #[Test]
    public function 定義の取得は_管理権限の無い子ブログのフィールド名やユニット種類を含めない(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $child = $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $childEntry = $this->createTestEntry($child, null);
        $this->insertColumn('ch', 'image_9', ['column_field_2' => 'c.jpg'], $childEntry, $child);
        $this->insertImageField('secret_hero', 'c.jpg', 1, '', ['field_eid' => $childEntry, 'field_blog_id' => $child]);

        $all = new DefinitionProvider($this->db, $this->blogScope(), static fn (): array => ['media'], static fn (int $id): bool => true);
        $some = new DefinitionProvider($this->db, $this->blogScope(), static fn (): array => ['media'], static fn (int $id): bool => $id !== $child);

        $this->assertContains('secret_hero', $all->get($parent)['fieldKeys']['image']);
        $this->assertSame([['type' => 'image_9', 'count' => 1]], $all->get($parent)['sourceUnitTypes']);
        $this->assertNotContains('secret_hero', $some->get($parent)['fieldKeys']['image']);
        $this->assertSame([], $some->get($parent)['sourceUnitTypes']);
    }

    #[Test]
    public function サイトに無いメディアユニットの種類へのマッピングは拒否する(): void
    {
        $this->mediaUnitTypes = static fn (): array => ['media', 'media_2'];

        $ok = $this->service()->create($this->blogId, 1, ['unitTypeMap' => ['image' => 'media_2', 'file' => 'media']]);
        $this->assertSame('media_2', $ok->options->unitTypeMap['image']);

        try {
            $this->service()->create($this->blogId, 1, ['unitTypeMap' => ['image' => 'media_7']]);
            $this->fail('拒否されるはず');
        } catch (\Acms\Plugins\ArchivesToMedia\Services\Support\InvalidInputException $e) {
            $this->assertStringContainsString('media_7', $e->getMessage());
        }
        $this->expectException(\Acms\Plugins\ArchivesToMedia\Services\Support\InvalidInputException::class);
        $this->service()->updateOptions($ok->id, $this->blogId, ['unitTypeMap' => ['image' => 'media_9']]);
    }

    #[Test]
    public function 子ブログを含めないジョブでは子孫の権限を確認しない(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $this->canAdminister = static fn (int $blogId): bool => false;
        $this->assertSame($parent, $this->service()->create($parent, 1, [])->blogId);
    }

    #[Test]
    public function オプション更新でも子ブログの権限を確認する(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $job = $this->service()->create($parent, 1, []);
        $this->canAdminister = static fn (int $blogId): bool => false;

        $this->expectException(ForbiddenException::class);
        $this->service()->updateOptions($job->id, $parent, ['includeChildBlogs' => true]);
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
            fn () => $this->service()->writeCsv($job->id, $other, $this->memoryStream()),
            fn () => $this->service()->ensureAccessible($job->id, $other),
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
    public function 確定するとスナップショットを捨てて容量を回収し_ロールバックはできなくなるが_ジョブは削除できる(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);
        $converted = $this->items()->countByStatus($job->id)['converted'] ?? 0;
        $this->assertGreaterThan(0, $converted);

        $this->service()->finalize($job->id, $this->blogId);

        $this->assertSame(JobStatus::Finalized, $this->jobs()->find($job->id)?->status);
        $left = $this->db->one('SELECT COUNT(*) FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? AND (item_before IS NOT NULL OR item_after IS NOT NULL OR item_cleanup IS NOT NULL)', [$job->id]);
        $this->assertSame('0', Cast::str($left));
        $this->assertSame($converted, $this->items()->countByStatus($job->id)['converted'] ?? 0, '件数と結果（CSV）は残る');
        foreach (
            [
                'rollback' => fn () => $this->service()->rollback($job->id, $this->blogId, false),
                'run' => fn () => $this->service()->run($job->id, $this->blogId),
                'cleanup' => fn () => $this->service()->cleanup($job->id, $this->blogId),
            ] as $name => $op
        ) {
            try {
                $op();
                $this->fail($name . ' は拒否されるはず');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->service()->delete($job->id, $this->blogId);
        $this->assertNull($this->jobs()->find($job->id));
    }

    #[Test]
    public function 確定は大きなジョブでも時間で区切って進められ_途中で止まっても続きから再実行できる(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);
        $remaining = static fn (self $t): int => Cast::int($t->db->one('SELECT COUNT(*) FROM ' . $t->db->t('a2m_item') . ' WHERE item_job_id = ? AND (item_before IS NOT NULL OR item_after IS NOT NULL)', [$job->id]));
        $this->assertGreaterThan(0, $remaining($this));

        // 時間の予算が尽きた想定（1 回に 1 件だけ消す）
        $first = $this->service()->finalize($job->id, $this->blogId, false, 1, 0.0);
        $this->assertFalse($first['done']);
        $this->assertSame(JobStatus::Finalized, $this->jobs()->find($job->id)?->status);
        $this->assertGreaterThan(0, $remaining($this), '一部だけ消えた');
        $this->assertTrue($this->service()->get($job->id, $this->blogId)['finalizing'], '回収の続きがある');

        // 確定済みでも、続きの回収はやり直せる
        for ($i = 0; $i < 20 && !($res['done'] ?? false); $i++) {
            $res = $this->service()->finalize($job->id, $this->blogId, false, 1, 0.0);
        }
        $this->assertTrue($res['done'] ?? false);
        $this->assertSame(0, $remaining($this));
        $this->assertFalse($this->service()->get($job->id, $this->blogId)['finalizing'], '回収が済んだら続きは無い');
    }

    #[Test]
    public function 旧データが残っているジョブの確定は_確認なしでは拒否する(): void
    {
        $this->archives->put('003/h.jpg', 'H');
        $this->insertImageField('hero', '003/h.jpg');
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50, 'targets' => ['field_image'], 'field' => ['mode' => 'suffix']]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);
        $this->assertSame(1, $this->service()->get($job->id, $this->blogId)['cleanup']['cleanable']);

        try {
            $this->service()->finalize($job->id, $this->blogId);
            $this->fail('旧データが残っているので拒否されるはず');
        } catch (\Acms\Plugins\ArchivesToMedia\Services\Support\StateException $e) {
            $this->assertStringContainsString('クリーンアップ', $e->getMessage());
            $this->assertSame(JobStatus::Completed, $this->jobs()->find($job->id)?->status, '確定しない');
        }

        $this->service()->finalize($job->id, $this->blogId, true);
        $this->assertSame('finalized', Cast::str($this->db->one('SELECT job_status FROM ' . $this->db->t('a2m_job') . ' WHERE job_id = ?', [$job->id])));
        $this->assertSame(['cleanable' => 0, 'cleaned' => 0], $this->service()->get($job->id, $this->blogId)['cleanup'], '確定後はクリーンアップの対象なし');
    }

    #[Test]
    public function 完了していないジョブは確定できない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->expectException(\LogicException::class);
        $this->service()->finalize($job->id, $this->blogId);
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
        $this->assertSame(1, $this->service()->requeueChunk($job->id, $this->blogId, true)['requeued']);
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

        $stream = $this->memoryStream();
        $this->service()->writeCsv($job->id, $this->blogId, $stream);
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => $l !== ''));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('source_path', $lines[0]);
        $this->assertGreaterThanOrEqual(6, count($lines));
        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString("'=cmd|calc.jpg", $csv);
        $this->assertStringContainsString('SOURCE_NOT_FOUND', $csv);
    }

    #[Test]
    public function 一覧のページ番号には上限があり_巨大なOFFSETにならない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $db = new \Acms\Plugins\ArchivesToMedia\Tests\Support\RecordingDb();
        $service = new JobService(
            $this->jobs(),
            new \Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository($db),
            $this->coordinator(),
            $this->runner(),
            $this->rollbacker(),
            $this->cleaner(),
            new AuditLog(),
            $this->blogScope(),
            static fn (int $blogId): bool => true,
        );
        $api = new AdminApi($service, new DefinitionProvider($this->db, $this->blogScope(), static fn (): array => ['media']));

        $api->handle('items', ['jobId' => $job->id, 'page' => '99999999', 'perPage' => '200'], $this->blogId, 1);

        $offsets = [];
        foreach ($db->selects as [$sql]) {
            if (preg_match('/OFFSET (\d+)/', $sql, $m) === 1) {
                $offsets[] = (int) $m[1];
            }
        }
        $this->assertCount(1, $offsets);
        $this->assertLessThanOrEqual(9999 * 200, $offsets[0]);
    }

    #[Test]
    public function CSVは件数が多くても全件を書き出し_OFFSETやCOUNTを使わない(): void
    {
        $jobId = $this->jobs()->create($this->blogId, 1, \Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions::defaults());
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = $i;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i) {
                $values[] = "(?, 'unit_image', 'column', ?, ?, 'entry', '1', '[\"a/{$i}.jpg\"]', 'new', 'skipped', 'SOURCE_NOT_FOUND', ?, ?)";
                array_push($params, $jobId, '{"c":' . $i . '}', sha1((string) $i), $this->blogId, '2026-01-01 00:00:00');
            }
            $this->db->exec(
                'INSERT INTO ' . $this->db->t('a2m_item') . ' (item_job_id, item_target, item_table, item_locator, item_locator_hash, item_owner_type, item_owner_id, item_source_paths, item_preview, item_status, item_reason, item_blog_id, item_updated_datetime) VALUES ' . implode(',', $values),
                $params
            );
        }
        $db = new \Acms\Plugins\ArchivesToMedia\Tests\Support\RecordingDb();
        $service = new JobService(
            $this->jobs(),
            new \Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository($db),
            $this->coordinator(),
            $this->runner(),
            $this->rollbacker(),
            $this->cleaner(),
            new AuditLog(),
            $this->blogScope(),
            static fn (int $blogId): bool => true,
        );

        $stream = $this->memoryStream();
        $service->writeCsv($jobId, $this->blogId, $stream);
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream)), static fn (string $l): bool => $l !== ''));

        $this->assertCount(451, $lines, '見出し + 450 件');
        foreach ($db->selects as [$sql]) {
            $this->assertStringNotContainsString('OFFSET', $sql);
            $this->assertStringNotContainsString('COUNT(', $sql);
        }
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
        $jobId = Cast::int($this->child($created, 'job')['id']);
        $this->assertSame('draft', $this->child($created, 'job')['status']);

        $scan = ['done' => false, 'total' => 0];
        for ($i = 0; $i < 20 && $scan['done'] !== true; $i++) {
            $scan = $api->handle('scan', ['jobId' => (string) $jobId], $this->blogId, 1);
        }
        $this->assertSame(4, $scan['total']);

        $this->assertSame(1, $api->handle('exclude', ['jobId' => $jobId, 'filter' => json_encode(['target' => 'unit_file']), 'excluded' => '1'], $this->blogId, 1)['updated']);
        $run = $api->handle('run', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertTrue($run['done']);
        $this->assertSame(2, $run['converted']);

        $this->assertSame(1, $api->handle('jobs', ['page' => '1'], $this->blogId, 1)['total']);
        $this->assertSame($jobId, $this->child($api->handle('job', ['jobId' => $jobId], $this->blogId, 1), 'job')['id']);
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
        $jobId = Cast::int($this->child($api->handle('create', ['options' => '{"chunkSize":50}'], $this->blogId, 1), 'job')['id']);
        $api->handle('update', ['jobId' => $jobId, 'options' => '{"targets":["unit_file"],"chunkSize":50}'], $this->blogId, 1);
        $scan = $api->handle('scan', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertSame(1, $scan['total']);
        $api->handle('run', ['jobId' => $jobId], $this->blogId, 1);
        $again = $api->handle('requeue', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertSame(0, $again['requeued']);
        $this->assertTrue($again['done']);
        $this->assertSame('completed', $this->child($again, 'job')['status']);
    }

    #[Test]
    public function スキャン直後のジョブはスキップ分の再実行を受け付けない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->expectException(\LogicException::class);
        $this->service()->requeueChunk($job->id, $this->blogId, true)['requeued'];
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
            ['exclude', ['jobId' => '1', 'ids' => range(1, 5001)]],
            ['job', ['jobId' => "1\n"]],
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
        // 極端に大きいページ番号でも、オフセットが溢れずに空の結果を返す
        $this->assertSame(0, $this->api()->handle('jobs', ['page' => '99999999999999999999'], $this->blogId, 1)['total']);
    }

    #[Test]
    public function 変換済みのアイテムがあるジョブは_失敗状態でも再スキャン・オプション変更できない(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);
        $this->jobs()->setStatus($job->id, JobStatus::Failed, 'DB が切れた');
        $before = $this->items()->countByStatus($job->id);

        foreach (
            [
                fn () => $this->service()->updateOptions($job->id, $this->blogId, ['targets' => ['field_image']]),
                fn () => $this->service()->scan($job->id, $this->blogId),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('LogicException が必要');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->items()->countByStatus($job->id), 'undo 情報（アイテム）は消えない');
        $this->assertTrue($this->service()->rollback($job->id, $this->blogId, false)->done);
    }

    #[Test]
    public function 変換済みを含むジョブのリセットは拒否される(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);
        $this->jobs()->setStatus($job->id, JobStatus::Scanned);

        $this->expectException(\LogicException::class);
        $this->service()->updateOptions($job->id, $this->blogId, []);
    }

    #[Test]
    public function 旧データのクリーンアップは画面のAPIから実行でき_件数が見える(): void
    {
        $this->seed();
        $api = $this->api();
        $jobId = $this->child($api->handle('create', ['options' => '{"chunkSize":50,"targets":["field_image"],"field":{"mode":"suffix"}}'], $this->blogId, 1), 'job')['id'];
        $jobId = Cast::int($jobId);
        $api->handle('scan', ['jobId' => $jobId], $this->blogId, 1);
        $api->handle('run', ['jobId' => $jobId], $this->blogId, 1);

        $this->assertSame(['cleanable' => 1, 'cleaned' => 0], $this->child($api->handle('job', ['jobId' => $jobId], $this->blogId, 1), 'cleanup'));
        $res = $api->handle('cleanup', ['jobId' => $jobId], $this->blogId, 1);
        $this->assertTrue($res['done']);
        $this->assertSame(1, $res['converted']);
        $this->assertSame(['cleanable' => 0, 'cleaned' => 1], $this->child($api->handle('job', ['jobId' => $jobId], $this->blogId, 1), 'cleanup'));
        $this->assertSame([], $this->fieldRows('hero@%'));
    }

    #[Test]
    public function 同名モードのジョブはクリーンアップ対象が0と表示される(): void
    {
        $this->seed();
        $job = $this->service()->create($this->blogId, 1, ['chunkSize' => 50, 'targets' => ['field_image'], 'field' => ['mode' => 'same']]);
        $this->scan($job->id);
        $this->service()->run($job->id, $this->blogId);
        $this->assertSame(['cleanable' => 0, 'cleaned' => 0], $this->service()->get($job->id, $this->blogId)['cleanup']);
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('stream');
        }
        return $stream;
    }
}
