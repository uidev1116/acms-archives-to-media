<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration;

use Acms\Plugins\ArchivesToMedia\ServiceProvider;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\Schema;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * DDL は暗黙コミットされるので、テストごとのトランザクションを使わない TestCase で確かめる。
 */
final class ServiceProviderInstallTest extends TestCase
{
    private function tableExists(string $table): bool
    {
        $db = new Db();
        return Cast::int($db->one('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [DB_PREFIX . $table])) === 1;
    }

    #[Test]
    public function インストールは冪等で_アンインストールで消え_再インストールできる(): void
    {
        $p = new ServiceProvider();
        try {
            $p->uninstall();
            foreach (['a2m_job', 'a2m_item', 'a2m_path_map'] as $t) {
                $this->assertFalse($this->tableExists($t), $t);
            }

            $p->install();
            $p->install();
            foreach (['a2m_job', 'a2m_item', 'a2m_path_map'] as $t) {
                $this->assertTrue($this->tableExists($t), $t);
            }
            $this->assertTrue($p->update());
            $this->assertTrue($p->activate());
            $this->assertTrue($p->deactivate());
        } finally {
            (new Schema(new Db()))->install();
        }
    }

    #[Test]
    public function ロールバックできる変換済みアイテムがあるあいだは_アンインストールを拒否する(): void
    {
        $db = new Db();
        $schema = new Schema($db);
        $p = new ServiceProvider();
        $db->exec('DELETE FROM ' . $db->t('a2m_item') . ' WHERE item_job_id = 987654');
        $db->exec('INSERT INTO ' . $db->t('a2m_item') . ' (item_job_id, item_target, item_table, item_locator, item_locator_hash, item_source_paths, item_status, item_blog_id, item_updated_datetime)'
            . ' VALUES (987654, \'unit_image\', \'column\', \'{}\', ?, \'[]\', \'converted\', 1, NOW())', [sha1('a2m-uninstall-test')]);
        try {
            $this->assertSame(1, $schema->rollbackableItems());
            try {
                $p->uninstall();
                $this->fail('例外が必要です');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('ロールバック', $e->getMessage());
            }
            $this->assertTrue($this->tableExists('a2m_item'), '拒否したのでテーブルは残る');

            $db->exec('UPDATE ' . $db->t('a2m_item') . ' SET item_status = \'rolled_back\' WHERE item_job_id = 987654');
            $this->assertSame(0, $schema->rollbackableItems());
        } finally {
            $db->exec('DELETE FROM ' . $db->t('a2m_item') . ' WHERE item_job_id = 987654');
        }
    }

    #[Test]
    public function 強制指定ならロールバックできる変換済みアイテムがあっても削除できる(): void
    {
        $db = new Db();
        $schema = new Schema($db);
        $db->exec('INSERT INTO ' . $db->t('a2m_item') . ' (item_job_id, item_target, item_table, item_locator, item_locator_hash, item_source_paths, item_status, item_blog_id, item_updated_datetime)'
            . ' VALUES (987655, \'unit_image\', \'column\', \'{}\', ?, \'[]\', \'converted\', 1, NOW())', [sha1('a2m-uninstall-force')]);
        try {
            $schema->uninstall(true);
            $this->assertFalse($this->tableExists('a2m_item'));
        } finally {
            $schema->install();
            $db->exec('DELETE FROM ' . $db->t('a2m_item') . ' WHERE item_job_id = 987655');
        }
    }

    #[Test]
    public function テーブルが無い状態でも変換済み件数は0を返す(): void
    {
        $db = new Db();
        $schema = new Schema($db);
        $schema->uninstall(true);
        try {
            $this->assertSame(0, $schema->rollbackableItems());
        } finally {
            $schema->install();
        }
    }
}
