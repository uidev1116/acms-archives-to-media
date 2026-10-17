<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

enum ItemStatus: string
{
    case Pending = 'pending';
    case Excluded = 'excluded';
    case Converted = 'converted';
    case Skipped = 'skipped';
    case Error = 'error';
    case RolledBack = 'rolled_back';
}
