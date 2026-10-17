<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta;
use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;
use Acms\Plugins\ArchivesToMedia\Services\Support\UnitData;

/**
 * ファイルユニット → メディアユニット（設計書 §4.3）
 *
 * 変換前: field_1=キャプション, field_2=パス
 * 変換後: field_1=media_id, field_2=キャプション, field_4=拡大表示(no), field_5=アイコン表示(yes)
 */
class UnitFileConverter extends AbstractUnitConverter
{
    public function supports(TargetType $target): bool
    {
        return $target === TargetType::UnitFile;
    }

    public function metas(Candidate $candidate, JobOptions $options): array
    {
        $row = $this->requireRow($candidate->locator);
        $count = count($candidate->sourcePaths);
        $captions = UnitData::pad(UnitData::explode((string) $row['column_field_1']), $count);
        $metas = [];
        for ($i = 0; $i < $count; $i++) {
            $metas[] = new MediaMeta('', $captions[$i], $options->fillAltOnNew, $options->tags, '', false);
        }
        return $metas;
    }

    public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array
    {
        $row = $this->requireRow($candidate->locator);
        $count = count($mediaIds);
        $captions = UnitData::pad(UnitData::explode((string) $row['column_field_1']), $count);

        $values = [
            'column_type' => $options->mediaUnitTypeFor((string) $row['column_type']),
            'column_field_1' => UnitData::implode(array_map(static fn (int $m): string => $m > 0 ? (string) $m : '', $mediaIds)),
            'column_field_2' => UnitData::implode($captions),
            'column_field_3' => '',
            'column_field_4' => UnitData::implode(array_map(static fn (int $m): string => $m > 0 ? 'no' : '', $mediaIds)),
            'column_field_5' => UnitData::implode(array_map(static fn (int $m): string => $m > 0 ? 'yes' : '', $mediaIds)),
            'column_field_6' => '',
            'column_field_7' => '',
            'column_field_8' => '',
        ];
        $this->writeRow($candidate->locator, $values, array_keys($row));

        return ['row' => Snapshot::normalizeRow($this->requireRow($candidate->locator))];
    }
}
