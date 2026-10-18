<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 今のジョブの状態では実行できない操作。文言は利用者に見せてよい。
 */
final class StateException extends \LogicException implements PublicMessage
{
}
