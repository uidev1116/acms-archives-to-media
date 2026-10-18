<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourceException;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourcePathSelector;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;
use Acms\Plugins\ArchivesToMedia\Services\Support\UnitData;

/**
 * 画像ユニット → メディアユニット（設計書 §4.2）
 *
 * 変換前: field_1=キャプション, field_2=パス, field_3=リンク, field_4=alt, field_5=表示サイズ, field_6=EXIF
 * 変換後: field_1=media_id, field_2=キャプション, field_3=alt, field_4=拡大表示, field_5=アイコン表示,
 *         field_6=表示サイズ, field_7=リンク, field_8=空
 */
class UnitImageConverter extends AbstractUnitConverter
{
    public function __construct(Db $db, private readonly SourcePathSelector $selector)
    {
        parent::__construct($db);
    }

    public function supports(TargetType $target): bool
    {
        return $target === TargetType::UnitImage;
    }

    public function metas(Candidate $candidate, JobOptions $options): array
    {
        $row = $this->requireRow($candidate->locator);
        $count = count($candidate->sourcePaths);
        $captions = UnitData::pad(UnitData::explode(Cast::str($row['column_field_1'])), $count);
        $alts = UnitData::pad(UnitData::explode(Cast::str($row['column_field_4'])), $count);
        $metas = [];
        for ($i = 0; $i < $count; $i++) {
            $metas[] = new MediaMeta($alts[$i], $captions[$i], $options->fillAltOnNew, $options->tags, '', true);
        }
        return $metas;
    }

    public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array
    {
        $row = $this->requireRow($candidate->locator);
        // 変換済み・別種のユニットは二重に変換しない
        if (preg_match('/^image(_\d+)?$/', Cast::str($row['column_type'])) !== 1) {
            throw new ConversionSkipException(ReasonCode::StaleSnapshot, '画像ユニットではありません（変換済みの可能性があります）。');
        }
        $paths = UnitData::pad(UnitData::explode(Cast::str($row['column_field_2'])), count($mediaIds));
        $count = count($mediaIds);

        $captions = UnitData::pad(UnitData::explode(Cast::str($row['column_field_1'])), $count);
        $links = UnitData::pad(UnitData::explode(Cast::str($row['column_field_3'])), $count);
        $alts = UnitData::pad(UnitData::explode(Cast::str($row['column_field_4'])), $count);

        $enlarged = [];
        $useIcon = [];
        foreach ($mediaIds as $i => $mid) {
            if ($mid <= 0) {
                $enlarged[] = '';
                $useIcon[] = '';
                continue;
            }
            $useIcon[] = 'no';
            $enlarged[] = match ($options->enlarged) {
                'yes' => 'yes',
                'no' => 'no',
                default => ($links[$i] === '' && $this->hasLarge($paths[$i])) ? 'yes' : 'no',
            };
        }

        $values = [
            'column_type' => $options->mediaUnitTypeFor(Cast::str($row['column_type'])),
            'column_field_1' => UnitData::implode(array_map(static fn (int $m): string => $m > 0 ? (string) $m : '', $mediaIds)),
            'column_field_2' => UnitData::implode($captions),
            'column_field_3' => UnitData::implode($alts),
            'column_field_4' => UnitData::implode($enlarged),
            'column_field_5' => UnitData::implode($useIcon),
            'column_field_6' => Cast::str($row['column_field_5']),
            'column_field_7' => UnitData::implode($links),
            'column_field_8' => '',
        ];
        $this->writeRow($candidate->locator, $values, array_keys($row));

        $after = $this->requireRow($candidate->locator);
        return ['row' => Snapshot::normalizeRow($after)];
    }

    private function hasLarge(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        try {
            return $this->selector->hasLarge($this->selector->normalize($path));
        } catch (SourceException) {
            return false;
        }
    }
}
