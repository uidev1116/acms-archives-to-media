<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Admin;

use Acms\Plugins\ArchivesToMedia\Services\Admin\AssetUrl;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class AssetUrlTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function プラグインのアセットのURLは_先頭が1つのスラッシュで二重にならない(string $dirOffset, string $pluginDir, string $expected): void
    {
        $this->assertSame($expected, AssetUrl::base($dirOffset, $pluginDir));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function cases(): array
    {
        return [
            'PLUGIN_DIR が先頭に / を持つ（実機）' => ['', '/extension/plugins/', '/extension/plugins/ArchivesToMedia/assets/'],
            '先頭に / が無い' => ['', 'extension/plugins/', '/extension/plugins/ArchivesToMedia/assets/'],
            'サブディレクトリ設置' => ['site/', '/extension/plugins/', '/site/extension/plugins/ArchivesToMedia/assets/'],
            'DIR_OFFSET が / 始まり' => ['/site/', '/extension/plugins/', '/site/extension/plugins/ArchivesToMedia/assets/'],
            '末尾の / が無い' => ['site', '/extension/plugins', '/site/extension/plugins/ArchivesToMedia/assets/'],
        ];
    }
}
