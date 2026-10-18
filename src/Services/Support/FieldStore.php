<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;

/**
 * field / field_rev テーブルの行を、所属（owner）と field_sort 単位で扱う。
 * field テーブルには主キーが無いので、キー・所属・ソート番号の組で行を特定する。
 */
class FieldStore
{
    public const COLUMNS = [
        'field_key', 'field_value', 'field_type', 'field_sort', 'field_search',
        'field_eid', 'field_cid', 'field_uid', 'field_bid', 'field_mid', 'field_unit_id', 'field_blog_id',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * 所属の条件 SQL とパラメーター
     *
     * @return array{0: string, 1: list<scalar|null>}
     */
    public function ownerWhere(FieldLocator $locator): array
    {
        $sql = 'field_' . $locator->ownerColumn . ' = ?';
        $params = [$locator->ownerId];
        if ($locator->revId !== null) {
            $sql .= ' AND field_rev_id = ?';
            $params[] = $locator->revId;
        }
        return [$sql, $params];
    }

    /**
     * {fd} と {fd}@* の行（指定の field_sort のもの）
     *
     * @return list<array<string, mixed>>
     */
    public function family(FieldLocator $locator, string $fd, bool $forUpdate = false): array
    {
        [$owner, $params] = $this->ownerWhere($locator);
        return $this->db->all(
            'SELECT * FROM ' . $this->db->t($locator->table()) . '
             WHERE ' . $owner . ' AND field_sort = ? AND (BINARY field_key = BINARY ? OR BINARY field_key LIKE BINARY ?)
             ORDER BY field_key' . ($forUpdate ? ' FOR UPDATE' : ''),
            [...$params, $locator->sort, $fd, $this->db->escapeLike($fd) . '@%']
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function onlyKeys(array $rows, string $key): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => Cast::str($r['field_key']) === $key));
    }

    /**
     * 行の値を返す（無ければ null）
     *
     * @param list<array<string, mixed>> $rows
     */
    public function valueOf(array $rows, string $key): ?string
    {
        foreach ($rows as $row) {
            if (Cast::str($row['field_key']) === $key) {
                return $row['field_value'] === null ? null : Cast::str($row['field_value']);
            }
        }
        return null;
    }

    public function deleteFamily(FieldLocator $locator, string $fd): void
    {
        [$owner, $params] = $this->ownerWhere($locator);
        $this->db->exec(
            'DELETE FROM ' . $this->db->t($locator->table()) . '
             WHERE ' . $owner . ' AND field_sort = ? AND (BINARY field_key = BINARY ? OR BINARY field_key LIKE BINARY ?)',
            [...$params, $locator->sort, $fd, $this->db->escapeLike($fd) . '@%']
        );
    }

    /**
     * キーを指定して削除（同じ所属・同じ sort）
     *
     * @param list<string> $keys
     */
    public function deleteKeys(FieldLocator $locator, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        [$owner, $params] = $this->ownerWhere($locator);
        $this->db->exec(
            'DELETE FROM ' . $this->db->t($locator->table()) . '
             WHERE ' . $owner . ' AND field_sort = ? AND BINARY field_key IN (' . $this->db->placeholders($keys) . ')',
            [...$params, $locator->sort, ...$keys]
        );
    }

    /**
     * 行を 1 つ挿入する（$row は COLUMNS（+ field_rev_id）のキーを持つ）
     *
     * @param array<string, mixed> $row
     */
    public function insertRow(string $table, array $row): void
    {
        $columns = self::COLUMNS;
        if ($table === 'field_rev') {
            $columns[] = 'field_rev_id';
        }
        $values = [];
        foreach ($columns as $column) {
            $v = $row[$column] ?? null;
            $values[] = $v === null ? null : (is_scalar($v) ? $v : (string) json_encode($v));
        }
        $this->db->exec(
            'INSERT INTO ' . $this->db->t($table) . ' (' . implode(', ', $columns) . ') VALUES (' . $this->db->placeholders($columns) . ')',
            $values
        );
    }

    /**
     * 同じ所属の、グループ定義の行（field_key が @ で始まる）
     *
     * @return list<array<string, mixed>>
     */
    public function groupRows(FieldLocator $locator): array
    {
        [$owner, $params] = $this->ownerWhere($locator);
        return $this->db->all(
            'SELECT * FROM ' . $this->db->t($locator->table()) . ' WHERE ' . $owner . ' AND field_key LIKE \'@%\' ORDER BY field_key, field_sort',
            $params
        );
    }

    /**
     * 行を完全一致（全カラム）で 1 件だけ削除する
     *
     * @param array<string, mixed> $row
     */
    public function deleteExact(string $table, array $row): void
    {
        $columns = self::COLUMNS;
        if ($table === 'field_rev') {
            $columns[] = 'field_rev_id';
        }
        $where = [];
        $params = [];
        foreach ($columns as $column) {
            $v = $row[$column] ?? null;
            if ($v === null) {
                $where[] = $column . ' IS NULL';
            } else {
                $where[] = 'BINARY ' . $column . ' = BINARY ?';
                $params[] = Cast::str($v);
            }
        }
        $this->db->exec(
            'DELETE FROM ' . $this->db->t($table) . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params
        );
    }
}
