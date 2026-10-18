<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Repository;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Item;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\RecordTooLongException;

class ItemRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * 候補をアイテムとして登録する。同じ所在のアイテムが既にあれば何もしない（スキャン再開の冪等性）。
     * 重複（ユニークキー）だけを無視する。列に入らない値などの書き込みエラーは、握りつぶさず例外にする。
     *
     * @param array<mixed> $before
     * @return int|null 登録した item_id。重複なら null
     */
    public function insert(
        int $jobId,
        Candidate $candidate,
        string $preview,
        array $before,
        ItemStatus $status = ItemStatus::Pending,
        ?ReasonCode $reason = null,
        ?string $message = null,
    ): ?int {
        $locatorJson = json_encode($candidate->locator->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pathsJson = json_encode($candidate->sourcePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // コアは接続ごとに sql_mode を空に近い設定（ALLOW_INVALID_DATES）にするので、列に入らない値は黙って切り詰められる。
        // 壊れた JSON を保存してしまわないよう、列の上限をここで確かめる。
        if (mb_strlen($locatorJson) > 512 || strlen($pathsJson) > 65535 || mb_strlen((string) $candidate->ownerId) > 36) {
            throw new RecordTooLongException('候補の所在または元ファイルのパスが長すぎて記録できません。');
        }
        $affected = $this->db->exec(
            'INSERT INTO ' . $this->db->t('a2m_item') . '
             (item_job_id, item_target, item_table, item_locator, item_locator_hash, item_owner_type, item_owner_id,
              item_source_paths, item_preview, item_status, item_reason, item_message, item_before, item_blog_id, item_updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE item_id = item_id',
            [
                $jobId,
                $candidate->target->value,
                $candidate->locator->table(),
                $locatorJson,
                sha1($locatorJson),
                $candidate->ownerType,
                (string) $candidate->ownerId,
                $pathsJson,
                $preview,
                $status->value,
                $reason === null ? '' : $reason->value,
                $message,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $candidate->blogId,
                date('Y-m-d H:i:s'),
            ]
        );
        return $affected > 0 ? $this->db->lastInsertId() : null;
    }

    /**
     * ジョブのアイテムを $batch 件ずつ消す（数十万行の longtext を 1 文で消すとタイムアウトしうる）。
     * 時間の予算を超えたら、残っていても止めて false を返す（もう一度呼ぶと続きから消す）。
     *
     * @return bool すべて消した
     */
    public function deleteByJob(int $jobId, float $timeBudget = 15.0, int $batch = 5000): bool
    {
        $started = microtime(true);
        do {
            $deleted = $this->db->exec('DELETE FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? LIMIT ' . max(1, $batch), [$jobId]);
            if ($deleted < $batch) {
                return true;
            }
        } while ((microtime(true) - $started) < $timeBudget);
        return false;
    }

    public function find(int $id): ?Item
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->t('a2m_item') . ' WHERE item_id = ?', [$id]);
        return $row === null ? null : Item::fromRow($row);
    }

    /**
     * @return list<Item>
     */
    public function nextPending(int $jobId, int $limit): array
    {
        return $this->byStatus($jobId, ItemStatus::Pending, $limit, false);
    }

    /**
     * 変換済みのアイテムを新しい順（ロールバック用）に返す
     *
     * @return list<Item>
     */
    public function nextConverted(int $jobId, int $limit): array
    {
        return $this->nextConvertedBefore($jobId, PHP_INT_MAX, $limit);
    }

    /**
     * 変換済みのアイテムを、$beforeId より小さい item_id の中で新しい順に返す。
     * 戻せなかった（別の処理が更新中など）アイテムが変換済みのまま残っても、同じ位置で空回りしないためのカーソル。
     *
     * @return list<Item>
     */
    public function nextConvertedBefore(int $jobId, int $beforeId, int $limit): array
    {
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? AND item_status = ? AND item_id < ? ORDER BY item_id DESC LIMIT ' . max(1, $limit),
            [$jobId, ItemStatus::Converted->value, $beforeId]
        );
        return array_map(static fn (array $r): Item => Item::fromRow($r), $rows);
    }

    /**
     * @param array<mixed> $after
     * @param list<int> $mediaIds
     */
    public function markConverted(int $id, array $mediaIds, array $after, ?ReasonCode $warning = null, ?string $message = null): void
    {
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . '
             SET item_status = ?, item_media_ids = ?, item_after = ?, item_reason = ?, item_message = ?, item_updated_datetime = ?
             WHERE item_id = ?',
            [
                ItemStatus::Converted->value,
                implode(',', $mediaIds),
                json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $warning === null ? '' : $warning->value,
                $message,
                date('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    public function markStatus(int $id, ItemStatus $status, ?ReasonCode $reason = null, ?string $message = null): void
    {
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . ' SET item_status = ?, item_reason = ?, item_message = ?, item_updated_datetime = ? WHERE item_id = ?',
            [$status->value, $reason === null ? '' : $reason->value, $message, date('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * 実行前の再スナップショット（スキップ・エラー分の再実行用）
     *
     * @param array<mixed> $before
     * @param list<string>|null $sourcePaths 現在の元ファイルのパス（スキャン後に差し替えられていても、現在のものを変換するため）
     */
    public function requeue(int $id, array $before, ?array $sourcePaths = null): void
    {
        $pathsSql = $sourcePaths === null ? '' : ', item_source_paths = ?';
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . '
             SET item_status = ?, item_reason = \'\', item_message = NULL, item_before = ?' . $pathsSql . ', item_updated_datetime = ?
             WHERE item_id = ? AND item_status IN (?, ?)',
            [
                ItemStatus::Pending->value,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ...($sourcePaths === null ? [] : [json_encode($sourcePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]),
                date('Y-m-d H:i:s'),
                $id,
                ItemStatus::Skipped->value,
                ItemStatus::Error->value,
            ]
        );
    }

    /**
     * クリーンアップ待ち（変換済みのフィールドで、まだ旧キーを消していない）アイテムを id 順に返す。
     *
     * @return list<Item>
     */
    public function nextCleanable(int $jobId, int $afterId, int $limit): array
    {
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->db->t('a2m_item') . '
             WHERE item_job_id = ? AND item_id > ? AND item_status = ? AND item_target IN (?, ?) AND item_cleanup IS NULL
             ORDER BY item_id ASC LIMIT ' . max(1, $limit),
            [$jobId, $afterId, ItemStatus::Converted->value, 'field_image', 'field_file']
        );
        return array_map(static fn (array $r): Item => Item::fromRow($r), $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows 消した旧キーの行
     */
    public function markCleaned(int $id, array $rows): void
    {
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . ' SET item_cleanup = ?, item_updated_datetime = ? WHERE item_id = ?',
            [json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), date('Y-m-d H:i:s'), $id]
        );
    }

    public function clearCleanup(int $id): void
    {
        $this->db->exec('UPDATE ' . $this->db->t('a2m_item') . ' SET item_cleanup = NULL WHERE item_id = ?', [$id]);
    }

    /**
     * @return array{cleanable: int, cleaned: int}
     */
    public function cleanupCounts(int $jobId): array
    {
        $row = $this->db->row(
            'SELECT SUM(item_cleanup IS NULL) AS cleanable, SUM(item_cleanup IS NOT NULL) AS cleaned FROM ' . $this->db->t('a2m_item') . '
             WHERE item_job_id = ? AND item_status = ? AND item_target IN (?, ?)',
            [$jobId, ItemStatus::Converted->value, 'field_image', 'field_file']
        );
        return ['cleanable' => Cast::int($row['cleanable'] ?? 0), 'cleaned' => Cast::int($row['cleaned'] ?? 0)];
    }

    /**
     * @param list<ItemStatus> $statuses
     * @return list<Item>
     */
    public function byStatuses(int $jobId, array $statuses, int $afterId, int $limit): array
    {
        $values = array_map(static fn (ItemStatus $s): string => $s->value, $statuses);
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? AND item_id > ? AND item_status IN (' . $this->db->placeholders($values) . ') ORDER BY item_id ASC LIMIT ' . max(1, $limit),
            [$jobId, $afterId, ...$values]
        );
        return array_map(static fn (array $r): Item => Item::fromRow($r), $rows);
    }

    /**
     * 除外の切り替え。pending <-> excluded の間だけ動かす。
     *
     * @param list<int> $ids
     */
    public function setExcluded(int $jobId, array $ids, bool $excluded): int
    {
        if ($ids === []) {
            return 0;
        }
        [$from, $to] = $excluded ? [ItemStatus::Pending, ItemStatus::Excluded] : [ItemStatus::Excluded, ItemStatus::Pending];
        return $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . ' SET item_status = ?, item_updated_datetime = ?
             WHERE item_job_id = ? AND item_status = ? AND item_id IN (' . $this->db->placeholders($ids) . ')',
            [$to->value, date('Y-m-d H:i:s'), $jobId, $from->value, ...$ids]
        );
    }

    /**
     * 絞り込み条件に合う pending/excluded を一括で切り替える
     *
     * @param array<string, string> $filters
     */
    public function setExcludedByFilter(int $jobId, array $filters, bool $excluded): int
    {
        [$from, $to] = $excluded ? [ItemStatus::Pending, ItemStatus::Excluded] : [ItemStatus::Excluded, ItemStatus::Pending];
        [$where, $params] = $this->filterSql($jobId, $filters);
        $where .= ' AND item_status = ?';
        $params[] = $from->value;
        return $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . ' SET item_status = ?, item_updated_datetime = ? WHERE ' . $where,
            [$to->value, date('Y-m-d H:i:s'), ...$params]
        );
    }

    /**
     * ロールバック用のスナップショット（変換前後の行、クリーンアップで消した行）を、$afterId の続きから $limit 件分捨てる。
     * 件数・結果・メディア ID は残す。数十万行の longtext を 1 文で書き換えるとタイムアウトしうるので、
     * item_id の範囲で区切る（既に消した範囲を読み直さない）。呼び出し側は、返した last を次の afterId にして繰り返す。
     *
     * @return array{last: int, count: int} last: 今回処理した最後の item_id（count が 0 なら $afterId のまま）
     */
    public function purgeSnapshots(int $jobId, int $afterId, int $limit): array
    {
        $rows = $this->db->all(
            'SELECT item_id FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? AND item_id > ? ORDER BY item_id LIMIT ' . max(1, $limit),
            [$jobId, $afterId]
        );
        if ($rows === []) {
            return ['last' => $afterId, 'count' => 0];
        }
        $first = Cast::int($rows[0]['item_id']);
        $last = Cast::int($rows[count($rows) - 1]['item_id']);
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . ' SET item_before = NULL, item_after = NULL, item_cleanup = NULL'
            . ' WHERE item_job_id = ? AND item_id BETWEEN ? AND ?',
            [$jobId, $first, $last]
        );
        return ['last' => $last, 'count' => count($rows)];
    }

    /**
     * @return array<string, int> status => 件数
     */
    public function countByStatus(int $jobId): array
    {
        $rows = $this->db->all(
            'SELECT item_status, COUNT(*) AS c FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? GROUP BY item_status',
            [$jobId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[Cast::str($row['item_status'])] = Cast::int($row['c']);
        }
        return $out;
    }

    /**
     * 種類 × 状態 の件数
     *
     * @return list<array{target: string, status: string, preview: string, reason: string, count: int}>
     */
    public function summary(int $jobId): array
    {
        $rows = $this->db->all(
            'SELECT item_target, item_status, item_preview, item_reason, COUNT(*) AS c FROM ' . $this->db->t('a2m_item') . '
             WHERE item_job_id = ? GROUP BY item_target, item_status, item_preview, item_reason',
            [$jobId]
        );
        return array_map(static fn (array $r): array => [
            'target' => Cast::str($r['item_target']),
            'status' => Cast::str($r['item_status']),
            'preview' => Cast::str($r['item_preview']),
            'reason' => Cast::str($r['item_reason']),
            'count' => Cast::int($r['c']),
        ], $rows);
    }

    /**
     * CSV 出力用に、item_id の昇順で $afterId の続きを読む（OFFSET を使わない）。
     *
     * @param list<int>|null $blogIds 指定すると、そのブログの行だけ
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $jobId, int $afterId, int $limit, ?array $blogIds = null): array
    {
        [$blogSql, $blogParams] = $this->blogSql($blogIds);
        return $this->db->all(
            'SELECT item_id, item_target, item_table, item_locator, item_owner_type, item_owner_id, item_source_paths, item_preview,
                    item_media_ids, item_status, item_reason, item_message, item_blog_id
             FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? AND item_id > ?' . $blogSql . ' ORDER BY item_id ASC LIMIT ' . max(1, $limit),
            [$jobId, $afterId, ...$blogParams]
        );
    }

    /**
     * 一覧用の検索
     *
     * 並べ替えと切り出しは item_id だけを取って行い（長い列を一時テーブルに持ち込まない）、
     * 選ばれた行だけをあとから読む。絞り込みが無く、ジョブの総数が分かっている（$knownTotal）ときは数え直さない。
     *
     * @param array<string, string> $filters target / status / reason / owner / q
     * @param int|null $knownTotal 絞り込み無しのときの総数（ジョブの集計値）
     * @param list<int>|null $blogIds 指定すると、そのブログの行だけ
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(int $jobId, array $filters, string $sort, bool $desc, int $page, int $perPage, ?int $knownTotal = null, ?array $blogIds = null): array
    {
        $perPage = max(1, min(200, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        [$where, $params] = $this->filterSql($jobId, $filters);
        [$blogSql, $blogParams] = $this->blogSql($blogIds);
        $where .= $blogSql;
        array_push($params, ...$blogParams);
        $sortable = ['item_id', 'item_target', 'item_status', 'item_owner_type', 'item_preview', 'item_reason'];
        $order = in_array($sort, $sortable, true) ? $sort : 'item_id';
        $idRows = $this->db->all(
            'SELECT item_id FROM ' . $this->db->t('a2m_item') . ' WHERE ' . $where . ' ORDER BY ' . $order . ($desc ? ' DESC' : ' ASC') . ', item_id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        $ids = array_map(static fn (array $r): int => Cast::int($r['item_id']), $idRows);
        $items = [];
        if ($ids !== []) {
            $rows = $this->db->all(
                'SELECT item_id, item_target, item_table, item_locator, item_owner_type, item_owner_id, item_source_paths, item_preview,
                        item_media_ids, item_status, item_reason, item_message, item_blog_id
                 FROM ' . $this->db->t('a2m_item') . ' WHERE item_id IN (' . $this->db->placeholders($ids) . ')',
                $ids
            );
            $byId = [];
            foreach ($rows as $row) {
                $byId[Cast::int($row['item_id'])] = $row;
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $items[] = $byId[$id];
                }
            }
        }
        $total = $filters === [] && $blogIds === null && $knownTotal !== null
            ? $knownTotal
            : Cast::int($this->db->one('SELECT COUNT(*) FROM ' . $this->db->t('a2m_item') . ' WHERE ' . $where, $params));
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<Item>
     */
    private function byStatus(int $jobId, ItemStatus $status, int $limit, bool $desc): array
    {
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? AND item_status = ? ORDER BY item_id ' . ($desc ? 'DESC' : 'ASC') . ' LIMIT ' . max(1, $limit),
            [$jobId, $status->value]
        );
        return array_map(static fn (array $r): Item => Item::fromRow($r), $rows);
    }

    /**
     * @param list<int>|null $blogIds
     * @return array{0: string, 1: list<int>}
     */
    private function blogSql(?array $blogIds): array
    {
        if ($blogIds === null) {
            return ['', []];
        }
        if ($blogIds === []) {
            return [' AND 1 = 0', []];
        }
        return [' AND item_blog_id IN (' . $this->db->placeholders($blogIds) . ')', $blogIds];
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function filterSql(int $jobId, array $filters): array
    {
        $where = 'item_job_id = ?';
        $params = [$jobId];
        foreach (['target' => 'item_target', 'status' => 'item_status', 'reason' => 'item_reason', 'owner' => 'item_owner_type', 'preview' => 'item_preview'] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$key];
            }
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $where .= ' AND item_source_paths LIKE ?';
            $encoded = json_encode($filters['q'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $params[] = '%' . $this->db->escapeLike(trim($encoded === false ? '' : $encoded, '"')) . '%';
        }
        return [$where, $params];
    }
}
