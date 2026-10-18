<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;

/**
 * 変換せずにスキップすべきことを、理由コード付きで表す
 */
final class ConversionSkipException extends \RuntimeException implements \Acms\Plugins\ArchivesToMedia\Services\Support\PublicMessage
{
    public function __construct(public readonly ReasonCode $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason->label());
    }
}
