<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Services\Facades\Field as FieldFacade;

/**
 * チャンクの途中で触った所属を覚えておき、チャンクの終わりにまとめてキャッシュをクリアする。
 */
class CacheInvalidator
{
    /** @var array<string, array{0: string, 1: int|string, 2: int|null}> */
    private array $fieldTargets = [];

    /** @var array<int, true> */
    private array $blogIds = [];

    public function __construct(private readonly Db $db)
    {
    }

    public function collect(LocatorInterface $locator, int $blogId): void
    {
        $this->blogIds[$blogId] = true;
        if ($locator instanceof FieldLocator) {
            $this->fieldTargets[$locator->ownerColumn . ':' . $locator->ownerId . ':' . (string) $locator->revId] = [$locator->ownerColumn, $locator->ownerId, $locator->revId];
        }
        if ($locator instanceof ColumnLocator) {
            $entryId = $this->db->one('SELECT column_entry_id FROM ' . $this->db->t($locator->table()) . ' WHERE column_id = ?', [$locator->columnId]);
            if ($entryId !== null) {
                $this->fieldTargets['eid:' . $entryId . ':' . (string) $locator->revId] = ['eid', (int) $entryId, $locator->revId];
            }
        }
    }

    public function flush(): void
    {
        foreach ($this->fieldTargets as [$type, $id, $rev]) {
            try {
                FieldFacade::deleteFieldCache($type, $id, $rev);
            } catch (\Throwable) {
                // キャッシュのクリアに失敗しても、変換自体は成功している
            }
        }
        foreach (array_keys($this->blogIds) as $blogId) {
            try {
                \ACMS_POST_Cache::clearPageCache($blogId);
            } catch (\Throwable) {
                // 同上
            }
        }
        $this->fieldTargets = [];
        $this->blogIds = [];
    }
}
