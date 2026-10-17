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
}
