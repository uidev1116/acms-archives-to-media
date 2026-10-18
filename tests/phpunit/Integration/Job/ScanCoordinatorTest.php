<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Job\ScanCoordinator;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ScanCoordinatorTest extends A2mTestCase
{
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

    /**
     * @param array<string, mixed> $options
     */
    private function newJob(array $options = []): int
    {
        return $this->jobs()->create($this->blogId, 1, JobOptions::fromArray($options + ['chunkSize' => 2]));
    }

    /**
     * @return array{status: string, scanned: int, total: int, done: bool}
     */
    private function scanAll(int $jobId): array
    {
        $res = [];
        for ($i = 0; $i < 50; $i++) {
            $job = $this->jobs()->find($jobId);
            $this->assertNotNull($job);
            $res = $this->coordinator()->scanChunk($job);
            if ($res['done']) {
                return $res;
            }
        }
        $this->fail('スキャンが終わらない');
    }

    private function seedMixed(): void
    {
        $this->archives->put('001/a.jpg', 'A');
        $this->archives->put('001/large-a.jpg', 'ALARGE');
        $this->archives->put('002/doc.pdf', 'PDF');
        $this->archives->put('003/h.jpg', 'H');
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('u2', 'image', ['column_field_2' => '001/missing.jpg']);
        $this->insertColumn('u3', 'file', ['column_field_2' => '002/doc.pdf']);
        $this->insertImageField('hero', '003/h.jpg');
        $this->insertFileField('doc', '002/doc.pdf', 'doc.pdf');
    }

    #[Test]
    public function 全対象をスキャンしてアイテムとドライランの見込みを作る(): void
    {
        $this->seedMixed();
        $jobId = $this->newJob();

        $res = $this->scanAll($jobId);

        $this->assertSame(JobStatus::Scanned->value, $res['status']);
        $this->assertSame(5, $res['total']);
        $job = $this->jobs()->find($jobId);
        $this->assertSame(JobStatus::Scanned, $job?->status);
        $this->assertSame(5, $job->total);
        $this->assertNull($job->scanCursor);

        $byStatus = $this->items()->countByStatus($jobId);
        $this->assertSame(['pending' => 4, 'skipped' => 1], $byStatus);
        $skipped = $this->items()->byStatuses($jobId, [ItemStatus::Skipped], 0, 10)[0];
        $this->assertSame('SOURCE_NOT_FOUND', $skipped->reason);
        $this->assertSame('skip', $skipped->preview);
        $pending = $this->items()->nextPending($jobId, 10);
        $this->assertSame(['new', 'new', 'new', 'new'], array_map(static fn ($i) => $i->preview, $pending));
        $this->assertNotNull($pending[0]->before);
    }

    #[Test]
    public function スキャンは何も書き換えない_ドライラン(): void
    {
        $this->seedMixed();
        $columns = $this->db->all('SELECT * FROM ' . $this->db->t('column') . ' WHERE column_entry_id = ? ORDER BY column_id', [$this->entryId]);
        $fields = $this->fieldRows();
        $jobId = $this->newJob();
        $this->scanAll($jobId);

        $this->assertSame([], $this->media->registered);
        $this->assertEquals($columns, $this->db->all('SELECT * FROM ' . $this->db->t('column') . ' WHERE column_entry_id = ? ORDER BY column_id', [$this->entryId]));
        $this->assertEquals($fields, $this->fieldRows());
        $this->assertSame('0', Cast::str($this->db->one('SELECT COUNT(*) FROM ' . $this->db->t('a2m_path_map'))));
    }

    #[Test]
    public function 既存メディアと同じ内容なら再利用の見込み_混在ならmixed(): void
    {
        $this->archives->put('001/a.jpg', 'SAME');
        $this->archives->put('002/b.jpg', 'SAME');
        $this->archives->put('003/c.jpg', 'NEW');
        $this->resolver()->resolve('001/a.jpg', $this->blogId, 99, new MediaMeta());
        $d = ':acms_unit_delimiter:';
        $this->insertColumn('reuse', 'image', ['column_field_2' => '002/b.jpg']);
        $this->insertColumn('mixed', 'image', ['column_field_2' => '002/b.jpg' . $d . '003/c.jpg']);
        $jobId = $this->newJob(['targets' => ['unit_image']]);

        $this->scanAll($jobId);

        $previews = array_map(static fn ($i) => $i->preview, $this->items()->nextPending($jobId, 10));
        $this->assertSame(['mixed', 'reuse'], $this->sortedValues($previews));
    }

    /**
     * @param list<string> $v
     * @return list<string>
     */
    private function sortedValues(array $v): array
    {
        sort($v);
        return $v;
    }

    #[Test]
    public function 多言語の一部だけ失敗するならPARTIAL_SOURCEでスキップ(): void
    {
        $this->archives->put('001/ja.jpg');
        $this->insertColumn('ml', 'image', ['column_field_2' => '001/ja.jpg:acms_unit_delimiter:001/gone.jpg']);
        $jobId = $this->newJob(['targets' => ['unit_image']]);
        $this->scanAll($jobId);

        $item = $this->items()->byStatuses($jobId, [ItemStatus::Skipped], 0, 5)[0];
        $this->assertSame('PARTIAL_SOURCE', $item->reason);
    }

    #[Test]
    public function パスが不正ならSOURCE_FORBIDDENでスキップ(): void
    {
        $this->insertColumn('bad', 'image', ['column_field_2' => '../../etc/passwd']);
        $jobId = $this->newJob(['targets' => ['unit_image']]);
        $this->scanAll($jobId);
        $item = $this->items()->byStatuses($jobId, [ItemStatus::Skipped], 0, 5)[0];
        $this->assertSame('SOURCE_FORBIDDEN', $item->reason);
    }

    #[Test]
    public function 書き込み先の衝突はDEST_CONFLICTでスキップ(): void
    {
        $this->archives->put('003/h.jpg');
        $this->insertImageField('hero', '003/h.jpg');
        $this->insertField('hero_media', 'x');
        $jobId = $this->newJob(['targets' => ['field_image']]);
        $this->scanAll($jobId);
        $item = $this->items()->byStatuses($jobId, [ItemStatus::Skipped], 0, 5)[0];
        $this->assertSame('DEST_CONFLICT', $item->reason);
    }

    #[Test]
    public function 選んだ対象だけを走査する(): void
    {
        $this->seedMixed();
        $jobId = $this->newJob(['targets' => ['field_file']]);
        $res = $this->scanAll($jobId);
        $this->assertSame(1, $res['total']);
    }

    #[Test]
    public function 途中で止めても続きから再開でき_重複しない(): void
    {
        $this->seedMixed();
        $jobId = $this->newJob(['chunkSize' => 1]);

        $job = $this->jobs()->find($jobId);
        $this->assertNotNull($job);
        $first = $this->coordinator()->scanChunk($job);
        $this->assertFalse($first['done']);
        $this->assertSame(JobStatus::Scanning->value, $first['status']);
        $this->assertNotNull($this->jobs()->find($jobId)?->scanCursor);

        // 別のコーディネーター（別リクエスト）で続きを読む
        $res = $this->scanAll($jobId);
        $this->assertSame(5, $res['total']);
    }

    #[Test]
    public function 空のサイトでも完了する(): void
    {
        $jobId = $this->newJob();
        $res = $this->scanAll($jobId);
        $this->assertSame(0, $res['total']);
        $this->assertSame(JobStatus::Scanned, $this->jobs()->find($jobId)?->status);
    }

    #[Test]
    public function 実行中や完了済みのジョブはスキャンできない(): void
    {
        $jobId = $this->newJob();
        $this->jobs()->setStatus($jobId, JobStatus::Running);
        $this->expectException(\LogicException::class);
        $this->coordinator()->scanChunk($this->jobs()->find($jobId) ?? throw new \RuntimeException());
    }

    #[Test]
    public function 再スキャンでアイテムを作り直す(): void
    {
        $this->seedMixed();
        $jobId = $this->newJob();
        $this->scanAll($jobId);
        $this->assertSame(5, $this->jobs()->find($jobId)?->total);

        $this->jobs()->setOptions($jobId, JobOptions::fromArray(['targets' => ['unit_image'], 'chunkSize' => 2]));
        $this->coordinator()->reset($this->jobs()->find($jobId) ?? throw new \RuntimeException());
        $this->assertSame(JobStatus::Draft, $this->jobs()->find($jobId)->status);
        $this->assertSame([], $this->items()->countByStatus($jobId));

        $res = $this->scanAll($jobId);
        $this->assertSame(2, $res['total']);
    }

    #[Test]
    public function 変換後に再スキャンすると候補は0件_冪等(): void
    {
        $this->archives->put('003/h.jpg');
        $this->insertImageField('hero', '003/h.jpg');
        $this->fieldConverter()->apply(
            new \Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate(
                \Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType::FieldImage,
                new \Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator('hero', 1, 'eid', $this->entryId, null, $this->blogId),
                'entry',
                $this->entryId,
                $this->blogId,
                ['003/h.jpg']
            ),
            [5],
            JobOptions::defaults()
        );
        $jobId = $this->newJob();
        $this->assertSame(0, $this->scanAll($jobId)['total']);
    }

    #[Test]
    public function 予期しない例外が起きても_スキャン全体は止まらずエラーとして記録する(): void
    {
        $this->archives->put('001/ok.jpg', 'OK');
        $this->insertColumn('ok', 'image', ['column_field_2' => '001/ok.jpg']);
        $this->insertColumn('bad', 'image', ['column_field_2' => '001/bad.jpg']);
        $exploding = new class ($this->unitImageConverter()) implements \Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface {
            public function __construct(private readonly \Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface $inner)
            {
            }

            public function supports(\Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType $target): bool
            {
                return $this->inner->supports($target);
            }

            public function snapshot(\Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface $locator, JobOptions $options): ?array
            {
                if ($locator instanceof \Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator && $locator->columnId === 'bad') {
                    throw new \JsonException('Malformed UTF-8');
                }
                return $this->inner->snapshot($locator, $options);
            }

            public function metas(\Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate $candidate, JobOptions $options): array
            {
                return $this->inner->metas($candidate, $options);
            }

            public function currentSourcePaths(\Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface $locator): ?array
            {
                return $this->inner->currentSourcePaths($locator);
            }

            public function lock(\Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface $locator, JobOptions $options): void
            {
                $this->inner->lock($locator, $options);
            }

            public function apply(\Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate $candidate, array $mediaIds, JobOptions $options): array
            {
                return $this->inner->apply($candidate, $mediaIds, $options);
            }

            public function restore(\Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface $locator, array $before, array $after, JobOptions $options): void
            {
                $this->inner->restore($locator, $before, $after, $options);
            }
        };
        $coordinator = new ScanCoordinator(
            [new UnitImageScanner($this->db, $this->blogScope())],
            [$exploding],
            $this->resolver(),
            $this->items(),
            $this->jobs(),
        );
        $jobId = $this->newJob(['targets' => ['unit_image']]);

        $res = ['done' => false, 'total' => 0];
        for ($i = 0; $i < 10 && !$res['done']; $i++) {
            $res = $coordinator->scanChunk($this->jobs()->find($jobId) ?? throw new \RuntimeException());
        }

        $this->assertTrue($res['done']);
        $this->assertSame(2, $res['total']);
        $err = $this->items()->byStatuses($jobId, [ItemStatus::Error], 0, 5);
        $this->assertCount(1, $err);
        $this->assertSame('UNKNOWN_ERROR', $err[0]->reason);
        $this->assertCount(1, $this->items()->byStatuses($jobId, [ItemStatus::Pending], 0, 5));
    }
}
