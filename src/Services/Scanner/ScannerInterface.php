<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;

interface ScannerInterface
{
    public function target(): TargetType;

    /**
     * カーソルの続きから最大 $limit 件の候補を返す。
     *
     * @return array{candidates: list<Candidate>, nextCursor: ?string}
     */
    public function scan(JobOptions $options, int $blogId, ?string $cursor, int $limit): array;
}
