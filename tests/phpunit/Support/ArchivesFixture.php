<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

/**
 * テスト用の一時 ARCHIVES_DIR
 */
final class ArchivesFixture
{
    public readonly string $dir;

    public function __construct()
    {
        $this->dir = rtrim(sys_get_temp_dir(), '/') . '/a2m_arch_' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->dir, 0777, true);
    }

    public function put(string $relative, string $content = ''): void
    {
        $path = $this->dir . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content === '' ? random_bytes(16) : $content);
    }

    public function destroy(): void
    {
        $this->rm($this->dir);
    }

    /**
     * @return list<string>
     */
    private function entries(string $path): array
    {
        $list = scandir($path);
        return $list === false ? [] : $list;
    }

    private function rm(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach ($this->entries($path) as $f) {
            if ($f !== '.' && $f !== '..') {
                $this->rm($path . '/' . $f);
            }
        }
        rmdir($path);
    }
}
