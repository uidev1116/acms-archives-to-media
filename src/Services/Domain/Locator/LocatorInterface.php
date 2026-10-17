<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain\Locator;

/**
 * 変換対象（1 ユニット or 1 フィールド値）の所在を表す。item_locator に JSON で保存する。
 */
interface LocatorInterface
{
    /** column / column_rev / field / field_rev */
    public function table(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
