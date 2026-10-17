<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

/**
 * 新規に作ったメディアに設定するメタデータ
 */
final class MediaMeta
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $alt = '',
        public readonly string $caption = '',
        public readonly bool $fillAlt = false,
        public readonly array $tags = [],
        public readonly string $fileName = '',
        public readonly bool $preferLarge = true,
    ) {
    }
}
