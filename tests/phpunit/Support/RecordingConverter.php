<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

use Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;

/**
 * 呼び出し順を記録する変換器（JobRunner の手順を検証するためのテスト用）
 */
final class RecordingConverter implements ConverterInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly ConverterInterface $inner)
    {
    }

    public function supports(TargetType $target): bool
    {
        return $this->inner->supports($target);
    }

    public function snapshot(LocatorInterface $locator, JobOptions $options): ?array
    {
        $this->calls[] = 'snapshot';
        return $this->inner->snapshot($locator, $options);
    }

    public function currentSourcePaths(LocatorInterface $locator): ?array
    {
        $this->calls[] = 'currentSourcePaths';
        return $this->inner->currentSourcePaths($locator);
    }

    public function lock(LocatorInterface $locator, JobOptions $options): void
    {
        $this->calls[] = 'lock';
        $this->inner->lock($locator, $options);
    }

    public function metas(Candidate $candidate, JobOptions $options): array
    {
        return $this->inner->metas($candidate, $options);
    }

    public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array
    {
        $this->calls[] = 'apply';
        return $this->inner->apply($candidate, $mediaIds, $options);
    }

    public function restore(LocatorInterface $locator, array $before, array $after, JobOptions $options): void
    {
        $this->inner->restore($locator, $before, $after, $options);
    }
}
