<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Services\Entry\Lock;

/**
 * 変換対象が、誰かが編集中（編集ロック中）のエントリーに属していないかを判定する。
 * コアの entry.lock サービスを使う（entry_lock_datetime / entry_lock_uid）。
 */
class EntryLockGuard
{
    public function __construct(private readonly Db $db, private readonly Lock $lock)
    {
    }

    public function isLocked(LocatorInterface $locator): bool
    {
        $entryId = $this->entryOf($locator);
        if ($entryId === null) {
            return false;
        }
        // ロックの置き場所は承認フローの設定で entry / entry_rev に分かれるので、安全側に両方を見る。
        // 自分自身を含む全員のロックが対象（保存中のエントリーを触らない）。有効期限はコアの設定に従う。
        $expire = date('Y-m-d H:i:s', $this->lock->getExpiredDatetime());
        foreach (['entry', 'entry_rev'] as $table) {
            $locked = $this->db->row(
                'SELECT 1 FROM ' . $this->db->t($table) . ' WHERE entry_id = ? AND entry_lock_uid >= 1 AND entry_lock_datetime > ?',
                [$entryId, $expire]
            );
            if ($locked !== null) {
                return true;
            }
        }
        return false;
    }

    private function entryOf(LocatorInterface $locator): ?int
    {
        if ($locator instanceof ColumnLocator) {
            $id = $this->db->one(
                'SELECT column_entry_id FROM ' . $this->db->t($locator->table()) . ' WHERE column_id = ?',
                [$locator->columnId]
            );
            return $id === null ? null : (int) $id;
        }
        if ($locator instanceof FieldLocator) {
            if ($locator->ownerColumn === 'eid') {
                return (int) $locator->ownerId;
            }
            if ($locator->ownerColumn === 'unit_id') {
                $id = $this->db->one(
                    'SELECT column_entry_id FROM ' . $this->db->t($locator->revId === null ? 'column' : 'column_rev') . ' WHERE column_id = ?',
                    [(string) $locator->ownerId]
                );
                return $id === null ? null : (int) $id;
            }
        }
        return null;
    }
}
