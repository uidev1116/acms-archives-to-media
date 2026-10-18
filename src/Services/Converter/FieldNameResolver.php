<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;

/**
 * 書き込み先のフィールド名（suffix / same / custom）
 */
class FieldNameResolver
{
    public function resolve(string $fd, JobOptions $options): string
    {
        return match ($options->fieldMode) {
            JobOptions::FIELD_MODE_SAME => $fd,
            JobOptions::FIELD_MODE_CUSTOM => $options->fieldCustom[$fd] ?? $fd . $options->fieldSuffix,
            default => $fd . $options->fieldSuffix,
        };
    }

    /**
     * 変換対象のフィールドか（includeKeys / excludeKeys）
     */
    public function isTarget(string $fd, JobOptions $options): bool
    {
        if (in_array($fd, $options->excludeKeys, true)) {
            return false;
        }
        return $options->includeKeys === [] || in_array($fd, $options->includeKeys, true);
    }
}
