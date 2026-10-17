<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

/**
 * 変換後に編集されていて、元に戻せない
 */
final class ConflictException extends \RuntimeException
{
}
