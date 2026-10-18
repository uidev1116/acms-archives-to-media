<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

/**
 * 操作に必要な権限が無い（例: 子ブログを含めるのに、その子ブログの管理権限が無い）
 */
final class ForbiddenException extends \RuntimeException
{
}
