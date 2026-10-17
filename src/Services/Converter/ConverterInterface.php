<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta;

interface ConverterInterface
{
    public function supports(TargetType $target): bool;

    /**
     * 現在の行のスナップショット（スキャン時の before と実行直前の比較に使う）。行が無ければ null。
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(LocatorInterface $locator, JobOptions $options): ?array;

    /**
     * 新規メディアに設定するメタデータ（item_source_paths と同じ順序、同じ個数）
     *
     * @return list<MediaMeta>
     */
    public function metas(Candidate $candidate, JobOptions $options): array;

    /**
     * 参照を書き換える。
     *
     * @param list<int> $mediaIds item_source_paths と同じ順序（空のパスは 0）
     * @return array<string, mixed> 変換後スナップショット（item_after）
     * @throws ConversionSkipException 変換せずスキップすべき場合
     */
    public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array;

    /**
     * item_before を書き戻す。現在の行が item_after と違えば ConflictException。
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @throws ConflictException
     */
    public function restore(LocatorInterface $locator, array $before, array $after, JobOptions $options): void;
}
