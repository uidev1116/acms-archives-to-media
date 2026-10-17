<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorFactory;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;

final class Item
{
    /**
     * @param list<string> $sourcePaths
     * @param list<int> $mediaIds
     * @param array<mixed>|null $before
     * @param array<mixed>|null $after
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
        $paths = json_decode((string) $row['item_source_paths'], true);
        $before = isset($row['item_before']) ? json_decode((string) $row['item_before'], true) : null;
        $after = isset($row['item_after']) ? json_decode((string) $row['item_after'], true) : null;
        $mediaIds = array_values(array_map('intval', array_filter(
            explode(',', (string) $row['item_media_ids']),
            static fn (string $v): bool => $v !== ''
        )));

        return new self(
            (int) $row['item_id'],
            (int) $row['item_job_id'],
            TargetType::from((string) $row['item_target']),
            LocatorFactory::fromJson((string) $row['item_locator']),
            (string) $row['item_owner_type'],
            (string) $row['item_owner_id'],
            is_array($paths) ? array_values(array_map('strval', $paths)) : [],
            (string) $row['item_preview'],
            $mediaIds,
            ItemStatus::from((string) $row['item_status']),
            (string) $row['item_reason'],
            isset($row['item_message']) ? (string) $row['item_message'] : null,
            is_array($before) ? $before : null,
            is_array($after) ? $after : null,
            (int) $row['item_blog_id'],
        );
    }
}
