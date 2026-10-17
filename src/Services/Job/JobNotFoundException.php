<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

/**
 * ジョブが無い、または現在のブログのジョブではない（存在の有無を区別して漏らさない）
 */
final class JobNotFoundException extends \RuntimeException
{
}
