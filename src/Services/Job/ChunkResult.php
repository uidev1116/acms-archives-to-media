<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;

/**
 * 1 リクエスト（チャンク）分の処理結果
 */
final class ChunkResult
{
    /**
     * @param list<array{itemId: int, status: string, reason: string, message: string}> $log
     */
    public function __construct(
        public readonly JobStatus $status,
        public readonly int $processed,
        public readonly int $converted,
        public readonly int $skipped,
        public readonly int $error,
        public readonly bool $done,
        public readonly array $log = [],
        public readonly int $remaining = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'processed' => $this->processed,
            'converted' => $this->converted,
            'skipped' => $this->skipped,
            'error' => $this->error,
            'done' => $this->done,
            'remaining' => $this->remaining,
            'log' => $this->log,
        ];
    }
}
