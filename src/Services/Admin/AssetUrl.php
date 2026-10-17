<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Admin;

/**
 * プラグインのアセット（JS / CSS）をブラウザから読むための URL
 */
final class AssetUrl
{
    /**
     * `/{DIR_OFFSET}extension/plugins/ArchivesToMedia/assets/`。
     *
     * PLUGIN_DIR は環境によって先頭に / を持つので（'/extension/plugins/'）、そのまま連結すると
     * `//extension/...`（プロトコル相対 URL）になってしまう。余分な / を除いてから組み立てる。
     */
    public static function base(string $dirOffset, string $pluginDir): string
    {
        $parts = array_filter(
            [trim($dirOffset, '/'), trim($pluginDir, '/'), 'ArchivesToMedia', 'assets'],
            static fn (string $p): bool => $p !== ''
        );
        return '/' . implode('/', $parts) . '/';
    }
}
