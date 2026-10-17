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
        ];
        foreach ($expected as $alias => $class) {
            $this->assertInstanceOf($class, App::make($alias), $alias);
        }
        $this->assertIsArray(App::make('archives-to-media.converters'));
        $this->assertCount(3, App::make('archives-to-media.converters'));
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
        $this->assertSame('ACMS_POST_ArchivesToMedia_Api', $data['api']['action']);
        $this->assertSame('ACMS_POST_ArchivesToMedia_ExportCsv', $data['csv']['action']);
        $this->assertNotFalse(json_encode($data));
    }

    #[Test]
    public function 要件チェックは環境が整っていればtrue(): void
    {
        $this->assertIsBool((new ServiceProvider())->checkRequirements());
    }

    #[Test]
    public function 自分の画面以外ではアセットを登録しない(): void
    {
        $this->provider();
        $this->assertFalse(\Acms\Services\Facades\Asset::has('archives-to-media'));
    }
}
