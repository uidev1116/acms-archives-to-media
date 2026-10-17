<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

final class ResolvedMedia
{
    public function __construct(
        public readonly int $mediaId,
        /** このジョブで新規に作ったメディアか */
        public readonly bool $isNew,
    ) {
    }
}
