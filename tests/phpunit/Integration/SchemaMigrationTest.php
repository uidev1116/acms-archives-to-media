<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\Schema;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * DDL は暗黙コミットされるので、トランザクションを使わない TestCase で、旧い定義のテーブルからの移行を確かめる。
 */
final class SchemaMigrationTest extends TestCase
{
    private function has(Db $db, string $sql, string $table, string $name): bool
    {
        return Cast::int($db->one($sql, [DB_PREFIX . $table, $name])) > 0;
    }

    private function column(Db $db, string $table, string $name): bool
    {
        return $this->has($db, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', $table, $name);
    }

    private function dataType(Db $db, string $table, string $name): string
    {
        return Cast::str($db->one(
            'SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [DB_PREFIX . $table, $name]
        ));
    }

    private function index(Db $db, string $table, string $name): bool
    {
        return $this->has($db, 'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', $table, $name);
    }

    #[Test]
    public function 旧い定義のテーブルを新しい定義に移行し_何度実行しても安全(): void
    {
        $db = new Db();
        $schema = new Schema($db);
        try {
            $schema->uninstall();
            // 初版の定義（パスに直接ユニークキー、item_cleanup 列なし）を再現する
            $db->exec('CREATE TABLE ' . $db->t('a2m_path_map') . ' (
                `map_blog_id` int(11) NOT NULL,
                `map_source_path` varchar(512) NOT NULL,
                `map_media_id` int(11) NOT NULL,
                `map_created_by_job` int(11) DEFAULT NULL,
                `map_created_datetime` datetime NOT NULL,
                UNIQUE KEY `blog_path` (`map_blog_id`, `map_source_path`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->exec('INSERT INTO ' . $db->t('a2m_path_map') . ' VALUES (7, ?, 11, NULL, NOW())', ['001/日本語.jpg']);

            $schema->install();
            $schema->install();

            $this->assertTrue($this->column($db, 'a2m_path_map', 'map_path_hash'));
            $this->assertFalse($this->index($db, 'a2m_path_map', 'blog_path'), '長いパスへのユニークキーは外す');
            $this->assertTrue($this->index($db, 'a2m_path_map', 'blog_path_hash'));
            $this->assertSame(sha1('001/日本語.jpg'), $db->one('SELECT map_path_hash FROM ' . $db->t('a2m_path_map') . ' WHERE map_blog_id = 7'));
            $this->assertTrue($this->column($db, 'a2m_item', 'item_cleanup'));
            $this->assertTrue($this->column($db, 'a2m_job', 'job_scan_cursor'));
            $this->assertTrue($this->index($db, 'a2m_item', 'job_summary'));
            $this->assertTrue($this->index($db, 'a2m_item', 'job_item'));
            $this->assertSame('text', $this->dataType($db, 'a2m_item', 'item_media_ids'));
        } finally {
            $schema->uninstall();
            $schema->install();
        }
    }

    #[Test]
    public function 新規インストールでも同じ定義になる(): void
    {
        $db = new Db();
        $this->assertTrue($this->column($db, 'a2m_path_map', 'map_path_hash'));
        $this->assertTrue($this->index($db, 'a2m_path_map', 'blog_path_hash'));
        $this->assertTrue($this->column($db, 'a2m_item', 'item_cleanup'));
        $this->assertTrue($this->index($db, 'a2m_item', 'job_summary'), '種類別の集計用のインデックス');
        $this->assertTrue($this->index($db, 'a2m_item', 'job_item'), 'CSV などを item_id 順に読む用のインデックス');
        $this->assertSame('text', $this->dataType($db, 'a2m_item', 'item_media_ids'), 'メディア ID が多いユニットでも切り詰めない');
    }
}
