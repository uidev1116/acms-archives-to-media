<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

use Acms\Services\Facades\Database;

/**
 * コアの Database ファサードを、プレースホルダー付きの生 SQL で使うための薄いラッパー。
 *
 * コアの query() は失敗しても例外を投げず false を返すので、書き込み系は false を例外に変換する。
 */
class Db
{
    /**
     * プレフィックス付きのテーブル名（バッククォート付き）
     */
    public function t(string $table): string
    {
        return '`' . DB_PREFIX . $table . '`';
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $rows = Database::query(['sql' => $sql, 'params' => $params], 'all');
        if (!is_array($rows)) {
            throw new \RuntimeException('DB query failed.');
        }
        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql . ' LIMIT 1', $params);
        return $rows[0] ?? null;
    }

    /**
     * @param list<scalar|null> $params
     */
    public function one(string $sql, array $params = []): mixed
    {
        $row = $this->row($sql, $params);
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    /**
     * 書き込み。変更された行数を返す。失敗は例外。
     *
     * @param list<scalar|null> $params
     */
    public function exec(string $sql, array $params = []): int
    {
        // 'seq' モードは影響行数ではなく LAST_INSERT_ID を返すので使わない
        $result = Database::query(['sql' => $sql, 'params' => $params], 'exec');
        if ($result === false) {
            throw new \RuntimeException('DB write failed.');
        }
        return Database::affected_rows();
    }

    public function lastInsertId(): int
    {
        return Cast::int(Database::query('SELECT LAST_INSERT_ID()', 'one'));
    }

    public function begin(): void
    {
        Database::beginTransaction();
    }

    public function commit(): void
    {
        Database::commit();
    }

    public function rollBack(): void
    {
        Database::rollBack();
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->begin();
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
        $this->commit();
        return $result;
    }

    /**
     * LIKE のワイルドカードをエスケープする
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param list<scalar> $values
     */
    public function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
