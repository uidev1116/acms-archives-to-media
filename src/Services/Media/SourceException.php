<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;

/**
 * 元ファイルを取り込めないことを、理由コード付きで表す例外
 */
final class SourceException extends \RuntimeException
{
    public function __construct(public readonly ReasonCode $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason->label(), 0, $previous);
    }
}
