<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorFactory;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;

final class Item
{
    /**
     * @param list<string> $sourcePaths
     * @param list<int> $mediaIds
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public readonly int $id,
        public readonly int $jobId,
        public readonly TargetType $target,
        public readonly LocatorInterface $locator,
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly array $sourcePaths,
        public readonly string $preview,
        public readonly array $mediaIds,
        public readonly ItemStatus $status,
        public readonly string $reason,
        public readonly ?string $message,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly int $blogId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $paths = json_decode(Cast::str($row['item_source_paths']), true);
        /** @var array<string, mixed>|null $before */
        $before = isset($row['item_before']) ? json_decode(Cast::str($row['item_before']), true) : null;
        /** @var array<string, mixed>|null $after */
        $after = isset($row['item_after']) ? json_decode(Cast::str($row['item_after']), true) : null;
        $mediaIds = array_values(array_map('intval', array_filter(
            explode(',', Cast::str($row['item_media_ids'])),
            static fn (string $v): bool => $v !== ''
        )));

        return new self(
            Cast::int($row['item_id']),
            Cast::int($row['item_job_id']),
            TargetType::from(Cast::str($row['item_target'])),
            LocatorFactory::fromJson(Cast::str($row['item_locator'])),
            Cast::str($row['item_owner_type']),
            Cast::str($row['item_owner_id']),
            is_array($paths) ? array_values(array_map(static fn (mixed $p): string => Cast::str($p), $paths)) : [],
            Cast::str($row['item_preview']),
            $mediaIds,
            ItemStatus::from(Cast::str($row['item_status'])),
            Cast::str($row['item_reason']),
            isset($row['item_message']) ? Cast::str($row['item_message']) : null,
            is_array($before) ? $before : null,
            is_array($after) ? $after : null,
            Cast::int($row['item_blog_id']),
        );
    }
}
