<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain\Locator;

final class ColumnLocator implements LocatorInterface
{
    public function __construct(
        public readonly string $columnId,
        public readonly ?int $revId = null,
    ) {
        if ($columnId === '') {
            throw new \InvalidArgumentException('column_id が空です。');
        }
    }

    public function table(): string
    {
        return $this->revId === null ? 'column' : 'column_rev';
    }

    public function toArray(): array
    {
        return ['kind' => 'column', 'column_id' => $this->columnId, 'rev_id' => $this->revId];
    }
}
