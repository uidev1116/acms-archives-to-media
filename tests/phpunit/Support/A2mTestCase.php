<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldGroupAppender;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldMediaConverter;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Converter\UnitFileConverter;
use Acms\Plugins\ArchivesToMedia\Services\Converter\UnitImageConverter;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobRunner;
use Acms\Plugins\ArchivesToMedia\Services\Job\Rollbacker;
use Acms\Plugins\ArchivesToMedia\Services\Media\ArchiveMediaResolver;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaReferenceChecker;
use Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard;
use Acms\Services\Common\Lock;
use Acms\Services\Entry\Lock as EntryLock;
use Acms\Plugins\ArchivesToMedia\Services\Media\BlogOwnershipFixer;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMetaWriter;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourcePathSelector;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Services\Support\BlogScope;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Services\Media\MediaSourceImporter;
use Acms\Services\Storage\Filesystem;
use Acms\TestingFramework\DatabaseTestCase;

/**
 * 統合テストの共通部品。本物の DB（テスト用 DB、テストごとにロールバック）と一時 ARCHIVES_DIR を使い、
 * メディア登録だけ FakeMediaHelper に差し替える。
 */
abstract class A2mTestCase extends DatabaseTestCase
{
    protected Db $db;
    protected ArchivesFixture $archives;
    protected FakeMediaHelper $media;
    protected int $blogId;
    protected int $entryId;

    protected function setUpDatabase(): void
    {
        $this->db = new Db();
        $this->archives = new ArchivesFixture();
        $this->media = new FakeMediaHelper();
        $this->blogId = $this->createTestBlog();
        $this->entryId = $this->createTestEntry($this->blogId, null);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->archives)) {
            $this->archives->destroy();
        }
        if ($this->lockFile !== '' && is_file($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    protected function selector(): SourcePathSelector
    {
        return new SourcePathSelector($this->archives->dir);
    }

    protected function resolver(int $maxSize = 1048576): ArchiveMediaResolver
    {
        $importer = new MediaSourceImporter(new Filesystem(), $this->media, $this->archives->dir, $maxSize);
        return new ArchiveMediaResolver(
            $importer,
            $this->media,
            new PathMapRepository($this->db),
            $this->selector(),
            new BlogOwnershipFixer($this->db),
            new MediaMetaWriter($this->db, $this->media),
            $this->archives->dir,
            $maxSize,
        );
    }

    protected function jobs(): JobRepository
    {
        return new JobRepository($this->db);
    }

    protected function items(): ItemRepository
    {
        return new ItemRepository($this->db);
    }

    protected function unitImageConverter(): UnitImageConverter
    {
        return new UnitImageConverter($this->db, $this->selector());
    }

    protected function unitFileConverter(): UnitFileConverter
    {
        return new UnitFileConverter($this->db);
    }

    protected function fieldConverter(): FieldMediaConverter
    {
        $store = new FieldStore($this->db);
        return new FieldMediaConverter($store, new FieldNameResolver(), new FieldGroupAppender($store));
    }

    protected string $lockFile = '';

    /**
     * @param list<\Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface>|null $converters
     */
    protected function runner(?array $converters = null, ?\Closure $lockFactory = null): JobRunner
    {
        return new JobRunner(
            $converters ?? [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()],
            $this->resolver(),
            $this->items(),
            $this->jobs(),
            new EntryLockGuard($this->db, new EntryLock('on', 'off', 48)),
            new CacheInvalidator($this->db),
            $this->db,
            $lockFactory ?? $this->lockFactory(),
        );
    }

    protected function lockFactory(): \Closure
    {
        if ($this->lockFile === '') {
            $this->lockFile = rtrim(sys_get_temp_dir(), '/') . '/a2m_lock_' . bin2hex(random_bytes(4));
        }
        $file = $this->lockFile;
        return static fn (): Lock => new Lock($file);
    }

    /**
     * @param list<\Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface>|null $converters
     */
    protected function rollbacker(?array $converters = null): Rollbacker
    {
        return new Rollbacker(
            $converters ?? [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()],
            $this->items(),
            $this->jobs(),
            new PathMapRepository($this->db),
            new MediaReferenceChecker($this->db),
            $this->media,
            new CacheInvalidator($this->db),
            $this->lockFactory(),
            new FieldStore($this->db),
        );
    }

    protected function cleaner(): \Acms\Plugins\ArchivesToMedia\Services\Job\Cleaner
    {
        return new \Acms\Plugins\ArchivesToMedia\Services\Job\Cleaner(
            $this->items(),
            $this->jobs(),
            new FieldStore($this->db),
            new FieldNameResolver(),
            new EntryLockGuard($this->db, new EntryLock('on', 'off', 48)),
            new CacheInvalidator($this->db),
            $this->lockFactory(),
        );
    }

    /**
     * ジョブを作ってスキャンを完了させ、job_id を返す
     *
     * @param array<string, mixed> $options
     */
    protected function scannedJob(array $options = []): int
    {
        $store = new FieldStore($this->db);
        $names = new FieldNameResolver();
        $coordinator = new \Acms\Plugins\ArchivesToMedia\Services\Job\ScanCoordinator(
            [
                new \Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitImageScanner($this->db, $this->blogScope()),
                new \Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitFileScanner($this->db, $this->blogScope()),
                new \Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldImageScanner($this->db, $this->blogScope(), $store, $names),
                new \Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldFileScanner($this->db, $this->blogScope(), $store, $names),
            ],
            [$this->unitImageConverter(), $this->unitFileConverter(), $this->fieldConverter()],
            $this->resolver(),
            $this->items(),
            $this->jobs(),
        );
        $id = $this->jobs()->create($this->blogId, 1, \Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions::fromArray($options + ['field' => ['mode' => 'suffix'], 'chunkSize' => 50]));
        for ($i = 0; $i < 100; $i++) {
            $job = $this->jobs()->find($id);
            $this->assertNotNull($job);
            if ($coordinator->scanChunk($job)['done']) {
                return $id;
            }
        }
        $this->fail('scan did not finish');
    }

    /**
     * 配列の子要素（配列）を型付きで取り出す
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    protected function child(array $array, string $key): array
    {
        $value = $array[$key] ?? null;
        $this->assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }

    protected function blogScope(): BlogScope
    {
        return new BlogScope($this->db);
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    protected function insertColumn(string $id, string $type, array $overrides = [], ?int $entryId = null, ?int $blogId = null, ?int $rev = null): void
    {
        $row = array_merge([
            'column_id' => $id,
            'column_status' => 'open',
            'column_sort' => 1,
            'column_align' => 'auto',
            'column_type' => $type,
            'column_parent_id' => null,
            'column_anker' => '',
            'column_attr' => '',
            'column_group' => '',
            'column_size' => '',
            'column_field_1' => '',
            'column_field_2' => '',
            'column_field_3' => '',
            'column_field_4' => '',
            'column_field_5' => '',
            'column_field_6' => '',
            'column_field_7' => '',
            'column_field_8' => '',
            'column_group_class' => '',
            'column_group_tag' => '',
            'column_entry_id' => $entryId ?? $this->entryId,
            'column_blog_id' => $blogId ?? $this->blogId,
        ], $overrides);
        if ($rev !== null) {
            $row['column_rev_id'] = $rev;
        }
        $this->insertTestData($rev === null ? 'column' : 'column_rev', $row);
    }

    /**
     * @return array<string, mixed>
     */
    protected function columnRow(string $id, ?int $rev = null): array
    {
        $row = $this->db->row(
            'SELECT * FROM ' . $this->db->t($rev === null ? 'column' : 'column_rev') . ' WHERE column_id = ?' . ($rev === null ? '' : ' AND column_rev_id = ' . $rev),
            [$id]
        );
        $this->assertNotNull($row);
        return $row;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertField(string $key, ?string $value, int $sort = 1, array $overrides = [], ?int $rev = null): void
    {
        $row = array_merge([
            'field_key' => $key,
            'field_value' => $value,
            'field_type' => null,
            'field_sort' => $sort,
            'field_search' => 'off',
            'field_eid' => $this->entryId,
            'field_cid' => null,
            'field_uid' => null,
            'field_bid' => null,
            'field_mid' => null,
            'field_unit_id' => null,
            'field_blog_id' => $this->blogId,
        ], $overrides);
        if ($rev !== null) {
            $row['field_rev_id'] = $rev;
        }
        $this->insertTestData($rev === null ? 'field' : 'field_rev', $row);
    }

    /**
     * 画像フィールド（旧形式）を作る
     */
    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertImageField(string $fd, string $path, int $sort = 1, string $alt = '', array $overrides = [], ?int $rev = null): void
    {
        $this->insertField($fd, '', $sort, $overrides + ['field_search' => 'on'], $rev);
        $this->insertField($fd . '@path', $path, $sort, $overrides, $rev);
        $this->insertField($fd . '@largePath', '', $sort, $overrides, $rev);
        $this->insertField($fd . '@x', '800', $sort, $overrides, $rev);
        $this->insertField($fd . '@y', '600', $sort, $overrides, $rev);
        $this->insertField($fd . '@alt', $alt, $sort, $overrides, $rev);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertFileField(string $fd, string $path, string $baseName, string $downloadName = '', int $sort = 1, array $overrides = []): void
    {
        $this->insertField($fd . '@path', $path, $sort, $overrides);
        $this->insertField($fd . '@baseName', $baseName, $sort, $overrides);
        $this->insertField($fd . '@downloadName', $downloadName, $sort, $overrides);
        $this->insertField($fd . '@fileSize', '123', $sort, $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fieldRows(string $like = '%', ?int $eid = null): array
    {
        return $this->db->all(
            'SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? AND field_key LIKE ? ORDER BY field_key, field_sort',
            [$eid ?? $this->entryId, $like]
        );
    }
}
