<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

final class Job
{
    public function __construct(
        public readonly int $id,
        public readonly JobStatus $status,
        public readonly JobOptions $options,
        public readonly int $total,
        public readonly int $converted,
        public readonly int $skipped,
        public readonly int $error,
        public readonly ?string $scanCursor,
        public readonly ?string $message,
        public readonly int $userId,
        public readonly int $blogId,
        public readonly string $createdAt,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['job_id'],
            JobStatus::from((string) $row['job_status']),
            JobOptions::fromJson((string) $row['job_options']),
            (int) $row['job_total'],
            (int) $row['job_converted'],
            (int) $row['job_skipped'],
            (int) $row['job_error'],
            $row['job_scan_cursor'] === null ? null : (string) $row['job_scan_cursor'],
            $row['job_message'] === null ? null : (string) $row['job_message'],
            (int) $row['job_user_id'],
            (int) $row['job_blog_id'],
            (string) $row['job_created_datetime'],
            $row['job_started_datetime'] === null ? null : (string) $row['job_started_datetime'],
            $row['job_finished_datetime'] === null ? null : (string) $row['job_finished_datetime'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'options' => $this->options->toArray(),
            'total' => $this->total,
            'converted' => $this->converted,
            'skipped' => $this->skipped,
            'error' => $this->error,
            'message' => $this->message,
            'userId' => $this->userId,
            'blogId' => $this->blogId,
            'createdAt' => $this->createdAt,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
        ];
    }
}
