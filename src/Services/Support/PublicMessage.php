<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 画面・CSV・ジョブの記録にそのまま出してよい文言を持つ例外の目印。
 * 目印の無い例外（DB やコアが投げるもの）は、SQL やサーバーのパスを含みうるので、そのままは出さない。
 */
interface PublicMessage extends \Throwable
{
}
