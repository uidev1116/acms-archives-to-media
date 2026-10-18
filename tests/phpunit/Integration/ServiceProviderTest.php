<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration;

use Acms\Plugins\ArchivesToMedia\ServiceProvider;
use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminApi;
use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminBootstrap;
use Acms\Plugins\ArchivesToMedia\Services\Admin\ApiEndpoint;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobRunner;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobService;
use Acms\Plugins\ArchivesToMedia\Services\Job\Rollbacker;
use Acms\Plugins\ArchivesToMedia\Services\Job\ScanCoordinator;
use Acms\Plugins\ArchivesToMedia\Services\Media\ArchiveMediaResolver;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\Services\Common\Lock;
use Acms\Services\Facades\Application as App;
use Acms\Services\Media\MediaSourceImporter;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends A2mTestCase
{
    private function provider(): ServiceProvider
    {
        $p = new ServiceProvider();
        $p->init();
        return $p;
    }

    #[Test]
    public function 識別子はスケルトンの規約どおり(): void
    {
        $p = new ServiceProvider();
        $this->assertSame('0.1.0', $p->version);
        $this->assertSame('ArchivesToMedia', $p->name);
        $this->assertSame('uidev1116', $p->author);
        $this->assertSame('archives_to_media_index', $p->menu);
        $this->assertFalse($p->module);
        $this->assertNotSame('', $p->desc);
    }

    #[Test]
    public function initでサービスがコンテナに登録され_解決できる(): void
    {
        $this->provider();
        $expected = [
            'archives-to-media.db' => Db::class,
            'archives-to-media.source-importer' => MediaSourceImporter::class,
            'archives-to-media.media-resolver' => ArchiveMediaResolver::class,
            'archives-to-media.scan-coordinator' => ScanCoordinator::class,
            'archives-to-media.job-runner' => JobRunner::class,
            'archives-to-media.rollbacker' => Rollbacker::class,
            'archives-to-media.job-service' => JobService::class,
            'archives-to-media.admin-api' => AdminApi::class,
            'archives-to-media.api-endpoint' => ApiEndpoint::class,
            'archives-to-media.admin-bootstrap' => AdminBootstrap::class,
            'archives-to-media.lock' => Lock::class,
            'archives-to-media.entry-serializer' => \Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer::class,
            'archives-to-media.cleaner' => \Acms\Plugins\ArchivesToMedia\Services\Job\Cleaner::class,
        ];
        foreach ($expected as $alias => $class) {
            $this->assertInstanceOf($class, App::make($alias), $alias);
        }
        $this->assertIsArray(App::make('archives-to-media.converters'));
        $this->assertCount(3, App::make('archives-to-media.converters'));
    }

    #[Test]
    public function 書き込みはコアのUnitLockの中で行う(): void
    {
        $this->provider();
        $serializer = App::make('archives-to-media.entry-serializer');
        $this->assertInstanceOf(\Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer::class, $serializer);

        $name = \Acms\Services\Unit\UnitLock::generateLockName(4242);
        // 別の接続が同じエントリーのロックを持っていれば、タイムアウトして EntryBusyException になる
        $env = static function (string $key, string $default): string {
            $value = getenv($key);
            return is_string($value) && $value !== '' ? $value : $default;
        };
        $pdo = new \PDO(
            'mysql:host=' . $env('ACMS_DB_HOST', 'db') . ';dbname=' . $env('ACMS_DB_NAME', ''),
            $env('ACMS_DB_USER', 'root'),
            $env('ACMS_DB_PASS', ''),
        );
        $pdo->query("SELECT GET_LOCK('" . $name . "', 1)");
        try {
            $this->expectException(\Acms\Plugins\ArchivesToMedia\Services\Support\EntryBusyException::class);
            $serializer->run(4242, static fn (): int => 1);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . $name . "')");
        }
    }

    #[Test]
    public function 画面に渡すバージョンは_拡張アプリのバージョンと同じ(): void
    {
        $provider = $this->provider();
        $bootstrap = App::make('archives-to-media.admin-bootstrap');
        $this->assertInstanceOf(AdminBootstrap::class, $bootstrap);
        $this->assertSame($provider->version, $bootstrap->toArray()['version']);
    }

    #[Test]
    public function コアのメディア関連サービスを差し替えていない(): void
    {
        $before = App::make('media.source-importer');
        $this->provider();
        $this->assertSame($before, App::make('media.source-importer'));
        $this->assertNotSame($before, App::make('archives-to-media.source-importer'));
    }

    #[Test]
    public function ロックはブログ単位のファイル名(): void
    {
        $this->provider();
        $lock = App::make('archives-to-media.lock');
        $this->assertInstanceOf(Lock::class, $lock);
        $ref = new \ReflectionProperty($lock, 'lockFile');
        $this->assertSame(CACHE_DIR . 'archives-to-media-lock-' . BID, $ref->getValue($lock));
    }

    #[Test]
    public function ブートストラップは画面の初期データを返す(): void
    {
        $this->provider();
        $b = App::make('archives-to-media.admin-bootstrap');
        $this->assertInstanceOf(AdminBootstrap::class, $b);
        $data = $b->toArray();
        $this->assertSame(BID, $data['blogId']);
        $this->assertSame(ApiEndpoint::ACTIONS, $data['actions']);
        $this->assertSame('ACMS_POST_ArchivesToMedia_Api', $this->child($data, 'api')['action']);
        $this->assertSame('ACMS_POST_ArchivesToMedia_ExportCsv', $this->child($data, 'csv')['action']);
        $this->assertNotFalse(json_encode($data));
    }

    #[Test]
    public function 要件チェックは環境が整っていればtrue(): void
    {
        $provider = new ServiceProvider();
        $this->assertSame($provider->checkRequirements(), $provider->checkRequirements());
    }

    #[Test]
    public function 管理画面の本体はマウント先と安全にエスケープした初期データを描く(): void
    {
        $provider = $this->provider();
        $html = $provider->renderAdminMain();

        $this->assertStringContainsString('id="a2m-root"', $html);
        $this->assertSame(1, preg_match('#<script type="application/json" id="a2m-bootstrap">(.*?)</script>#s', $html, $m));
        $payload = $m[1] ?? '';
        $data = json_decode($payload, true);
        $this->assertIsArray($data);
        $this->assertSame(BID, $data['blogId']);
        $this->assertStringNotContainsString('</script', $payload);
    }

    #[Test]
    public function 管理画面のアセットを登録する_存在しないハンドルには依存しない(): void
    {
        $provider = new ServiceProvider();
        $this->assertFalse(\Acms\Services\Facades\Asset::has('acms-admin'), 'このコアには標準ハンドル acms-admin が無い');

        $provider->registerAdminAssets();

        $this->assertTrue(\Acms\Services\Facades\Asset::has('archives-to-media'));
        $this->assertTrue(\Acms\Services\Facades\Asset::has('archives-to-media-style'));
        \Acms\Services\Facades\Asset::dequeue('archives-to-media');
        \Acms\Services\Facades\Asset::dequeue('archives-to-media-style');
    }

    #[Test]
    public function 標準ハンドルがあるコアでは_その後に読み込む(): void
    {
        \Acms\Services\Facades\Asset::script('acms-admin', '/js/admin.js');
        try {
            (new ServiceProvider())->registerAdminAssets();
            $this->assertTrue(\Acms\Services\Facades\Asset::has('archives-to-media'));
        } finally {
            \Acms\Services\Facades\Asset::dequeue('archives-to-media');
            \Acms\Services\Facades\Asset::dequeue('archives-to-media-style');
            \Acms\Services\Facades\Asset::dequeue('acms-admin');
        }
    }

    #[Test]
    public function 自分の画面以外ではアセットを登録しない(): void
    {
        $this->provider();
        $this->assertFalse(\Acms\Services\Facades\Asset::has('archives-to-media'));
    }
}
