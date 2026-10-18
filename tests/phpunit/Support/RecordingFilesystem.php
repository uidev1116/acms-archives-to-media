<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

use Acms\Services\Storage\Filesystem;

/**
 * 呼ばれたコアの Filesystem のメソッドを記録する（自前の is_file / realpath ではなく、コアの部品を使っていることの確認用）。
 */
final class RecordingFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $calls = [];

    public function isFile(string $path): bool
    {
        $this->calls[] = 'isFile';
        return parent::isFile($path);
    }

    public function getFileSize(string $path): int
    {
        $this->calls[] = 'getFileSize';
        return parent::getFileSize($path);
    }

    public function safeRealpath(string $path)
    {
        $this->calls[] = 'safeRealpath';
        return parent::safeRealpath($path);
    }

    public function validateDirectoryTraversalPath($path, $publicDir = '', $checkExists = true)
    {
        $this->calls[] = 'validateDirectoryTraversalPath';
        return parent::validateDirectoryTraversalPath($path, $publicDir, $checkExists);
    }

    public function isSafeRelativePath(string $path): bool
    {
        $this->calls[] = 'isSafeRelativePath';
        return parent::isSafeRelativePath($path);
    }

    public function uniqueFilePath(string $original, string $prefix = '', int $num = 0): string
    {
        $this->calls[] = 'uniqueFilePath';
        return parent::uniqueFilePath($original, $prefix, $num);
    }

    public function remove(string $path): bool
    {
        $this->calls[] = 'remove';
        return parent::remove($path);
    }
}
