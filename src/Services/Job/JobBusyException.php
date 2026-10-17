<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

/**
 * 同じブログで別の処理が実行中
 */
final class JobBusyException extends \RuntimeException
{
}
