<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;

/**
 * ファイルフィールド: 同じ所属・同じ {fd} に {fd}@baseName があり、{fd}@x が無い
 */
class FieldFileScanner extends AbstractFieldScanner
{
    public function target(): TargetType
    {
        return TargetType::FieldFile;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function looksLikeFile(array $rows, string $fd): bool
    {
        $hasBaseName = false;
        $hasX = false;
        foreach ($rows as $row) {
            $key = (string) $row['field_key'];
            if ($key === $fd . '@baseName') {
                $hasBaseName = true;
            }
            if ($key === $fd . '@x') {
                $hasX = true;
            }
        }
        return $hasBaseName && !$hasX;
    }

    protected function accepts(array $rows, string $fd): bool
    {
        return self::looksLikeFile($rows, $fd);
    }

    protected function legacySuffixes(): array
    {
        return FieldNameResolver::LEGACY_FILE;
    }
}
