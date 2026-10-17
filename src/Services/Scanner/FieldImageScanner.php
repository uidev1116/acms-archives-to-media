<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;

/**
 * 画像フィールド: {fd}@baseName が無い、または {fd}@x がある
 */
class FieldImageScanner extends AbstractFieldScanner
{
    public function target(): TargetType
    {
        return TargetType::FieldImage;
    }

    protected function accepts(array $rows, string $fd): bool
    {
        return !FieldFileScanner::looksLikeFile($rows, $fd);
    }

    protected function legacySuffixes(): array
    {
        return FieldNameResolver::LEGACY_IMAGE;
    }
}
