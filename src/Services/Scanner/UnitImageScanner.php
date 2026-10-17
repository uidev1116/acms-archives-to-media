<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;

class UnitImageScanner extends AbstractUnitScanner
{
    public function target(): TargetType
    {
        return TargetType::UnitImage;
    }

    protected function typeRegex(): string
    {
        return '/^image(_\d+)?$/';
    }

    protected function typePrefix(): string
    {
        return 'image';
    }
}
