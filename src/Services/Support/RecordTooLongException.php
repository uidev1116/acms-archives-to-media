<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 候補の所在や元ファイルのパスが長すぎて、列に収まらない（黙って切り詰めると壊れた JSON になる）。文言は利用者に見せてよい。
 */
final class RecordTooLongException extends \RuntimeException implements PublicMessage
{
}
