<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 多言語ユニットのデータ（:acms_unit_delimiter: 区切り）の分割・結合。
 * コアの UnitMultiLangTrait と同じ規則にそろえる。
 */
final class UnitData
{
    public const DELIMITER = ':acms_unit_delimiter:';

    /**
     * @return list<string>
     */
    public static function explode(string $data): array
    {
        return explode(self::DELIMITER, $data);
    }

    /**
     * @param list<string> $parts
     */
    public static function implode(array $parts): string
    {
        $parts = str_replace(self::DELIMITER, ':acms-unit-delimiter:', $parts);
        $data = implode(self::DELIMITER, $parts);
        if (preg_match('/^(' . preg_quote(self::DELIMITER, '/') . ')+$/', $data) === 1) {
            return '';
        }
        return $data;
    }

    /**
     * @param list<string> $parts
     * @return list<string> $count 個にそろえる（足りなければ空文字で埋め、多ければ切る）
     */
    public static function pad(array $parts, int $count): array
    {
        $parts = array_slice($parts, 0, $count);
        while (count($parts) < $count) {
            $parts[] = '';
        }
        return $parts;
    }
}
