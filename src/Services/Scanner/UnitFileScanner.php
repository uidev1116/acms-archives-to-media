<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;

class UnitFileScanner extends AbstractUnitScanner
{
    public function target(): TargetType
    {
        return TargetType::UnitFile;
    }

    protected function typeRegex(): string
    {
        return '/^file(_\d+)?$/';
    }

    protected function typePrefix(): string
    {
        return 'file';
    }
}
