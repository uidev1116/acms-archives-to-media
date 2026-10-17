<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use ACMS_App;
use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminApi;
use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminBootstrap;
use Acms\Plugins\ArchivesToMedia\Services\Admin\ApiEndpoint;
use Acms\Plugins\ArchivesToMedia\Services\Admin\AssetUrl;
use Acms\Plugins\ArchivesToMedia\Services\Admin\DefinitionProvider;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldGroupAppender;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldMediaConverter;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Converter\UnitFileConverter;
use Acms\Plugins\ArchivesToMedia\Services\Converter\UnitImageConverter;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobRunner;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobService;
use Acms\Plugins\ArchivesToMedia\Services\Job\Rollbacker;
use Acms\Plugins\ArchivesToMedia\Services\Job\ScanCoordinator;
use Acms\Plugins\ArchivesToMedia\Services\Media\ArchiveMediaResolver;
use Acms\Plugins\ArchivesToMedia\Services\Media\BlogOwnershipFixer;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMetaWriter;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaReferenceChecker;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourcePathSelector;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;
use Acms\Plugins\ArchivesToMedia\Services\Support\BlogScope;
use Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Plugins\ArchivesToMedia\Services\Support\Schema;
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Common\Lock;
use Acms\Services\Facades\Application as App;
use Acms\Services\Facades\Asset;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Twig;
use Acms\Services\Media\Helper;
use Acms\Services\Media\MediaSourceImporter;
use Acms\Services\Storage\Filesystem;

/**
 * ArchivesToMedia: archives/ を参照しているユニット・フィールドを、メディア参照へ一括変換する拡張アプリ。
 *
 * このクラスは Twig の登録、自分の画面のときだけの差し込み（InjectTemplate・アセット API）、
 * サービスコンテナへの登録、インストール／アンインストールだけを受け持つ。ロジックは Services/ にある。
 */
class ServiceProvider extends ACMS_App
{
    /** @var string */
    public $version = '0.1.0';

    /** @var string */
    public $name = 'ArchivesToMedia';

    /** @var string */
    public $author = 'uidev1116';

    /** @var bool */
    public $module = false;

    /** @var false|string */
    public $menu = 'archives_to_media_index';

    /** @var string */
    public $desc = 'archives/ の画像・ファイルを参照しているユニットとカスタムフィールドを、メディア参照へ一括変換します。ドライラン・チャンク実行・ロールバックに対応。';

    /**
     * プラグインが有効なときの初期処理（リクエストごとに呼ばれる）
     *
     * @return void
     */
    public function init()
    {
        // テンプレートディレクトリ（ファイルシステムのパスなので PLUGIN_LIB_DIR）
        Twig::addTemplatePath(PLUGIN_LIB_DIR . 'ArchivesToMedia/template', 'archives_to_media');

        // プラグインでは register() が呼ばれないので、登録は init() の中で App::singleton() / App::bind() で行う
        $this->registerServices();

        // 自分の管理画面を開いているときだけ、差し込みとアセットの登録を行う
        if (!defined('ADMIN') || ADMIN !== 'app_' . $this->menu) {
            return;
        }

        $inject = InjectTemplate::singleton();
        $inject->add('admin-topicpath', PLUGIN_DIR . 'ArchivesToMedia/template/admin/topicpath.html');
        $inject->add('admin-main', static function (): string {
            $bootstrap = App::make('archives-to-media.admin-bootstrap');
            assert($bootstrap instanceof AdminBootstrap);
            $json = json_encode($bootstrap->toArray(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return Twig::renderTemplate('@archives_to_media/admin/main.twig', [
                // </script> が混ざらないように JSON_HEX_TAG などでエスケープ済み
                'bootstrap_json' => $json === false ? '{}' : $json,
            ]);
        });

        // ローカルファイルなので version（キャッシュ対策のクエリ）は自動で付く
        $base = AssetUrl::base(DIR_OFFSET, PLUGIN_DIR);
        Asset::style('archives-to-media-style', $base . 'css/archives-to-media.css', [
            'position' => 'head',
        ]);
        Asset::script('archives-to-media', $base . 'dist/archives-to-media.js', [
            'deps' => ['acms-admin'],
            'position' => 'body_end',
            'attrs' => ['type' => 'module'],
        ]);
    }

    /**
     * インストール前の環境チェック
     *
     * @return bool
     */
    public function checkRequirements()
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            return false;
        }
        // アセット API（3.2.29 以降）
        if (!class_exists(Asset::class)) {
            return false;
        }
        if (!is_readable(SCRIPT_DIR . ARCHIVES_DIR)) {
            return false;
        }
        foreach ([MEDIA_LIBRARY_DIR, MEDIA_STORAGE_DIR, CACHE_DIR] as $dir) {
            if (!LocalStorage::isWritable($dir)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return void
     */
    public function install()
    {
        (new Schema(new Db()))->install();
    }

    /**
     * @return void
     */
    public function uninstall()
    {
        (new Schema(new Db()))->uninstall();
    }

    /**
     * @return bool
     */
    public function update()
    {
        (new Schema(new Db()))->install(); // CREATE TABLE IF NOT EXISTS なので何度でも安全
        return true;
    }

    /**
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }

    /**
     * サービスコンテナへの登録。コアのエイリアス（media.source-importer など）は
     * 再登録も override / extend もせず、専用のインスタンスを自分のエイリアスで作る。
     */
    private function registerServices(): void
    {
        $archivesBase = static fn (): string => SCRIPT_DIR . ARCHIVES_DIR;
        $maxSize = static fn (): int => (int) config('archives_to_media_max_filesize', 50 * 1024 * 1024);

        App::singleton('archives-to-media.db', static fn () => new Db());
        App::singleton('archives-to-media.blog-scope', static fn () => new BlogScope(self::db()));
        App::singleton('archives-to-media.audit-log', static fn () => new AuditLog());
        App::singleton('archives-to-media.cache-invalidator', static fn () => new CacheInvalidator(self::db()));

        // コアの media.source-importer は CSV インポート用のベースで登録済みなので流用せず、
        // ARCHIVES_DIR をベースにした専用インスタンスを作る
        App::singleton('archives-to-media.source-importer', static fn () => new MediaSourceImporter(
            new Filesystem(),
            self::media(),
            $archivesBase(),
            $maxSize(),
        ));
        App::singleton('archives-to-media.path-map', static fn () => new PathMapRepository(self::db()));
        App::singleton('archives-to-media.job-repository', static fn () => new JobRepository(self::db()));
        App::singleton('archives-to-media.item-repository', static fn () => new ItemRepository(self::db()));
        App::singleton('archives-to-media.media-resolver', static fn () => new ArchiveMediaResolver(
            self::get('archives-to-media.source-importer', MediaSourceImporter::class),
            self::media(),
            self::get('archives-to-media.path-map', PathMapRepository::class),
            new SourcePathSelector($archivesBase()),
            new BlogOwnershipFixer(self::db()),
            new MediaMetaWriter(self::db(), self::media()),
            $archivesBase(),
            $maxSize(),
        ));

        App::singleton('archives-to-media.converters', static function () use ($archivesBase): array {
            $store = new FieldStore(self::db());
            return [
                new UnitImageConverter(self::db(), new SourcePathSelector($archivesBase())),
                new UnitFileConverter(self::db()),
                new FieldMediaConverter($store, new FieldNameResolver(), new FieldGroupAppender($store)),
            ];
        });

        // 多重実行防止（WXRImport・コアの webp/csv インポートと同じ Common\Lock パターン）。ブログ単位。
        App::bind('archives-to-media.lock', static fn () => new Lock(CACHE_DIR . 'archives-to-media-lock-' . BID));
        $lockFactory = static function (): Lock {
            $lock = App::make('archives-to-media.lock');
            assert($lock instanceof Lock);
            return $lock;
        };

        App::singleton('archives-to-media.scan-coordinator', static function (): ScanCoordinator {
            $db = self::db();
            $scope = self::get('archives-to-media.blog-scope', BlogScope::class);
            $store = new FieldStore($db);
            $names = new FieldNameResolver();
            return new ScanCoordinator(
                [
                    new UnitImageScanner($db, $scope),
                    new UnitFileScanner($db, $scope),
                    new FieldImageScanner($db, $scope, $store, $names),
                    new FieldFileScanner($db, $scope, $store, $names),
                ],
                self::converters(),
                self::get('archives-to-media.media-resolver', ArchiveMediaResolver::class),
                self::get('archives-to-media.item-repository', ItemRepository::class),
                self::get('archives-to-media.job-repository', JobRepository::class),
            );
        });
        App::singleton('archives-to-media.job-runner', static function () use ($lockFactory): JobRunner {
            $entryLock = App::make('entry.lock');
            assert($entryLock instanceof \Acms\Services\Entry\Lock);
            return new JobRunner(
                self::converters(),
                self::get('archives-to-media.media-resolver', ArchiveMediaResolver::class),
                self::get('archives-to-media.item-repository', ItemRepository::class),
                self::get('archives-to-media.job-repository', JobRepository::class),
                new EntryLockGuard(self::db(), $entryLock),
                self::get('archives-to-media.cache-invalidator', CacheInvalidator::class),
                self::db(),
                $lockFactory,
            );
        });
        App::singleton('archives-to-media.rollbacker', static fn () => new Rollbacker(
            self::converters(),
            self::get('archives-to-media.item-repository', ItemRepository::class),
            self::get('archives-to-media.job-repository', JobRepository::class),
            self::get('archives-to-media.path-map', PathMapRepository::class),
            new MediaReferenceChecker(self::db()),
            self::media(),
            self::get('archives-to-media.cache-invalidator', CacheInvalidator::class),
            $lockFactory,
        ));
        App::singleton('archives-to-media.job-service', static fn () => new JobService(
            self::get('archives-to-media.job-repository', JobRepository::class),
            self::get('archives-to-media.item-repository', ItemRepository::class),
            self::get('archives-to-media.scan-coordinator', ScanCoordinator::class),
            self::get('archives-to-media.job-runner', JobRunner::class),
            self::get('archives-to-media.rollbacker', Rollbacker::class),
            self::get('archives-to-media.audit-log', AuditLog::class),
        ));
        App::singleton('archives-to-media.admin-api', static fn () => new AdminApi(
            self::get('archives-to-media.job-service', JobService::class),
            new DefinitionProvider(
                self::db(),
                self::get('archives-to-media.blog-scope', BlogScope::class),
                static fn (): array => array_values(array_map(static fn (mixed $v): string => Cast::str($v), configArray('column_add_type'))),
            ),
        ));
        App::singleton('archives-to-media.api-endpoint', static fn () => new ApiEndpoint(
            self::get('archives-to-media.admin-api', AdminApi::class),
            self::get('archives-to-media.audit-log', AuditLog::class),
        ));
        App::singleton('archives-to-media.admin-bootstrap', static fn () => new AdminBootstrap(
            BID,
            '0.1.0',
            Cast::str(acmsLink(['bid' => BID, 'admin' => 'app_archives_to_media_index'])),
        ));
    }

    private static function db(): Db
    {
        return self::get('archives-to-media.db', Db::class);
    }

    private static function media(): Helper
    {
        return self::get('media', Helper::class);
    }

    /**
     * @return list<\Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface>
     */
    private static function converters(): array
    {
        $converters = App::make('archives-to-media.converters');
        assert(is_array($converters));
        /** @var list<\Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface> $converters */
        return $converters;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function get(string $alias, string $class): object
    {
        $service = App::make($alias);
        assert($service instanceof $class);
        return $service;
    }
}
