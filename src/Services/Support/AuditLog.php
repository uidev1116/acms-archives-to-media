<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * コアの監査ログ（AcmsLogger）への薄いラッパー。ログの失敗で本処理を止めない。
 */
class AuditLog
{
    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        try {
            \AcmsLogger::info('【ArchivesToMedia】' . $message, $context);
        } catch (\Throwable) {
            // ログに失敗しても処理は続ける
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $message, array $context = []): void
    {
        try {
            \AcmsLogger::notice('【ArchivesToMedia】' . $message, $context);
        } catch (\Throwable) {
            // 同上
        }
    }
}
