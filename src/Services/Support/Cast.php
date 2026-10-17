<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * DB の行や JSON から取り出した mixed の値を、型を絞って安全に変換する。
 */
final class Cast
{
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function nullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::str($value);
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
