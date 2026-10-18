<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 画面からの入力（オプション・ID・ページ番号など）が不正。文言は利用者に見せてよい。
 */
final class InvalidInputException extends \InvalidArgumentException implements PublicMessage
{
}
