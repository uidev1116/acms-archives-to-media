<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

enum JobStatus: string
{
    case Draft = 'draft';
    case Scanning = 'scanning';
    case Scanned = 'scanned';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';

    /** 実行（run）を開始・再開できる状態か */
    public function canRun(): bool
    {
        return match ($this) {
            self::Scanned, self::Running, self::Paused, self::Failed => true,
            default => false,
        };
    }

    /** スキャン（再スキャン含む）を開始・再開できる状態か */
    public function canScan(): bool
    {
        return match ($this) {
            self::Draft, self::Scanning, self::Scanned => true,
            default => false,
        };
    }

    /** ロールバックを開始・再開できる状態か */
    public function canRollback(): bool
    {
        return match ($this) {
            self::Completed, self::Paused, self::Running, self::RollingBack, self::Failed => true,
            default => false,
        };
    }
}
