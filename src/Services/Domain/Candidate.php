<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;

/**
 * スキャンで見つけた変換候補（1 ユニット or 1 フィールド値）
 */
final class Candidate
{
    /**
     * @param list<string> $sourcePaths archives 相対パス（多言語ユニットは複数。通常サイズのパス）
     * @param list<string> $fileNames   メディアに付けるファイル名のヒント（$sourcePaths と同じ順。空文字なら basename）
     * @param array<string, mixed> $context コンバーターが使う補助情報（元のユニット種類、large パス等）
     */
    public function __construct(
        public readonly TargetType $target,
        public readonly LocatorInterface $locator,
        public readonly string $ownerType,
        public readonly int|string $ownerId,
        public readonly int $blogId,
        public readonly array $sourcePaths,
        public readonly array $fileNames = [],
        public readonly array $context = [],
    ) {
    }
}
