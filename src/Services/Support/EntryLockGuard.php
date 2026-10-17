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
        [$entryId, $revId] = $this->entryOf($locator);
        if ($entryId === null) {
            return false;
        }
        // $suid を渡さないので、自分自身を含む全員のロックが対象（保存中のエントリーを触らない）
        return $this->lock->getLockedUser($entryId, $revId, null) !== false;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function entryOf(LocatorInterface $locator): array
    {
        if ($locator instanceof ColumnLocator) {
            $id = $this->db->one(
                'SELECT column_entry_id FROM ' . $this->db->t($locator->table()) . ' WHERE column_id = ?',
                [$locator->columnId]
            );
            return [$id === null ? null : (int) $id, $locator->revId];
        }
        if ($locator instanceof FieldLocator) {
            if ($locator->ownerColumn === 'eid') {
                return [(int) $locator->ownerId, $locator->revId];
            }
            if ($locator->ownerColumn === 'unit_id') {
                $id = $this->db->one(
                    'SELECT column_entry_id FROM ' . $this->db->t($locator->revId === null ? 'column' : 'column_rev') . ' WHERE column_id = ?',
                    [(string) $locator->ownerId]
                );
                return [$id === null ? null : (int) $id, $locator->revId];
            }
        }
        return [null, null];
    }
}
