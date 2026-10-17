<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Repository;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Item;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

class ItemRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * 候補をアイテムとして登録する。同じ所在のアイテムが既にあれば何もしない（スキャン再開の冪等性）。
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
        $affected = $this->db->exec(
            'INSERT IGNORE INTO ' . $this->db->t('a2m_item') . '
             (item_job_id, item_target, item_table, item_locator, item_locator_hash, item_owner_type, item_owner_id,
              item_source_paths, item_preview, item_status, item_reason, item_message, item_before, item_blog_id, item_updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $jobId,
                $candidate->target->value,
                $candidate->locator->table(),
                $locatorJson,
                sha1($locatorJson),
                $candidate->ownerType,
                (string) $candidate->ownerId,
                json_encode($candidate->sourcePaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $preview,
                $status->value,
                $reason?->value ?? '',
                $message,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $candidate->blogId,
                date('Y-m-d H:i:s'),
            ]
        );
        return $affected > 0 ? $this->db->lastInsertId() : null;
    }

    public function deleteByJob(int $jobId): void
    {
        $this->db->exec('DELETE FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ?', [$jobId]);
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
        return $this->byStatus($jobId, ItemStatus::Converted, $limit, true);
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
                $warning?->value ?? '',
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
            [$status->value, $reason?->value ?? '', $message, date('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * 実行前の再スナップショット（スキップ・エラー分の再実行用）
     *
     * @param array<mixed> $before
     */
    public function requeue(int $id, array $before): void
    {
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_item') . '
             SET item_status = ?, item_reason = \'\', item_message = NULL, item_before = ?, item_updated_datetime = ?
             WHERE item_id = ? AND item_status IN (?, ?)',
            [
                ItemStatus::Pending->value,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                date('Y-m-d H:i:s'),
                $id,
                ItemStatus::Skipped->value,
                ItemStatus::Error->value,
            ]
        );
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
            $out[(string) $row['item_status']] = (int) $row['c'];
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
            'target' => (string) $r['item_target'],
            'status' => (string) $r['item_status'],
            'preview' => (string) $r['item_preview'],
            'reason' => (string) $r['item_reason'],
            'count' => (int) $r['c'],
        ], $rows);
    }

    /**
     * 一覧用の検索
     *
     * @param array<string, string> $filters target / status / reason / owner / q
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(int $jobId, array $filters, string $sort, bool $desc, int $page, int $perPage): array
    {
        $perPage = max(1, min(200, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        [$where, $params] = $this->filterSql($jobId, $filters);
        $sortable = ['item_id', 'item_target', 'item_status', 'item_owner_type', 'item_preview', 'item_reason'];
        $order = in_array($sort, $sortable, true) ? $sort : 'item_id';
        $rows = $this->db->all(
            'SELECT item_id, item_target, item_table, item_locator, item_owner_type, item_owner_id, item_source_paths, item_preview,
                    item_media_ids, item_status, item_reason, item_message, item_blog_id
             FROM ' . $this->db->t('a2m_item') . ' WHERE ' . $where . ' ORDER BY ' . $order . ($desc ? ' DESC' : ' ASC') . ', item_id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        $total = (int) $this->db->one('SELECT COUNT(*) FROM ' . $this->db->t('a2m_item') . ' WHERE ' . $where, $params);
        return ['items' => $rows, 'total' => $total];
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
            $params[] = '%' . $this->db->escapeLike(trim(json_encode($filters['q'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', '"')) . '%';
        }
        return [$where, $params];
    }
}
