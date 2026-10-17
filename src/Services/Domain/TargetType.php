<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

enum TargetType: string
{
    case UnitImage = 'unit_image';
    case UnitFile = 'unit_file';
    case FieldImage = 'field_image';
    case FieldFile = 'field_file';

    public function isUnit(): bool
    {
        return $this === self::UnitImage || $this === self::UnitFile;
    }

    public function isField(): bool
    {
        return !$this->isUnit();
    }

    public function label(): string
    {
        return match ($this) {
            self::UnitImage => '画像ユニット',
            self::UnitFile => 'ファイルユニット',
            self::FieldImage => '画像フィールド',
            self::FieldFile => 'ファイルフィールド',
        };
    }
}
