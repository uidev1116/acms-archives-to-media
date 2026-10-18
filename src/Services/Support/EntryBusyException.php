<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 同じエントリーを別の処理（保存・リビジョン切り替えなど）が書き換え中で、ロックを取れなかった
 */
final class EntryBusyException extends \RuntimeException implements \Acms\Plugins\ArchivesToMedia\Services\Support\PublicMessage
{
}
