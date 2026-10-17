<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain\Locator;

/**
 * フィールド値の所在。同じ所属（owner）・同じ field_sort の {fd} 系の行をまとめて 1 件とする。
 */
final class FieldLocator implements LocatorInterface
{
    public const OWNER_COLUMNS = ['eid', 'cid', 'uid', 'bid', 'mid', 'unit_id'];

    public function __construct(
        public readonly string $key,
        public readonly int $sort,
        public readonly string $ownerColumn,
        public readonly int|string $ownerId,
        public readonly ?int $revId,
        public readonly int $blogId,
    ) {
        if (!in_array($ownerColumn, self::OWNER_COLUMNS, true)) {
            throw new \InvalidArgumentException('不正な所属です。');
        }
        if ($key === '' || $ownerId === '' || $ownerId === 0) {
            throw new \InvalidArgumentException('フィールドの所在が不正です。');
        }
    }

    public function table(): string
    {
        return $this->revId === null ? 'field' : 'field_rev';
    }

    public function toArray(): array
    {
        return [
            'kind' => 'field',
            'key' => $this->key,
            'sort' => $this->sort,
            'owner' => $this->ownerColumn,
            'owner_id' => $this->ownerId,
            'rev_id' => $this->revId,
            'blog_id' => $this->blogId,
        ];
    }

    /** エントリー・カテゴリー等、表示用の所属種別 */
    public function ownerType(): string
    {
        return match ($this->ownerColumn) {
            'eid' => 'entry',
            'cid' => 'category',
            'uid' => 'user',
            'bid' => 'blog',
            'mid' => 'module',
            default => 'unit',
        };
    }
}
