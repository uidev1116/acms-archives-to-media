<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 行スナップショットの正規化と比較。値はすべて文字列（または null）に揃えて比較する。
 */
final class Snapshot
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    public static function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v));
        }
        ksort($out);
        return $out;
    }

    /**
     * 複数行を順序に依存しない形に正規化する。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, string|null>>
     */
    public static function normalizeRows(array $rows): array
    {
        $normalized = array_map(static fn (array $r): array => self::normalizeRow($r), $rows);
        usort($normalized, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));
        return $normalized;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public static function equals(array $a, array $b): bool
    {
        return json_encode($a) === json_encode($b);
    }
}
