<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;

/**
 * 画像フィールド・ファイルフィールド → メディアフィールド（設計書 §4.4 / §4.5）
 *
 * 書き込む行: {dest}（値 = media_id）と {dest}@media（値 = media_id、field_type = media）。
 * 既定（別名モード）では旧キーに触れない。同名モードでは旧キーを消す。
 */
class FieldMediaConverter implements ConverterInterface
{
    public function __construct(
        private readonly FieldStore $store,
        private readonly FieldNameResolver $names,
        private readonly FieldGroupAppender $groups,
    ) {
    }

    public function supports(TargetType $target): bool
    {
        return $target->isField();
    }

    public function snapshot(LocatorInterface $locator, JobOptions $options): ?array
    {
        $loc = $this->requireField($locator);
        $rows = $this->ownRows($loc, $options);
        return $rows === [] ? null : ['rows' => Snapshot::normalizeRows($rows)];
    }

    public function lock(LocatorInterface $locator, JobOptions $options): void
    {
        $loc = $this->requireField($locator);
        $fd = $loc->key;
        $this->store->family($loc, $fd, true);
        $dest = $this->names->resolve($fd, $options);
        if ($dest !== $fd) {
            $this->store->family($loc, $dest, true);
        }
    }

    public function currentSourcePaths(LocatorInterface $locator): ?array
    {
        $loc = $this->requireField($locator);
        $value = $this->store->valueOf($this->store->family($loc, $loc->key), $loc->key . '@path');
        return $value === null ? null : [$value];
    }

    public function metas(Candidate $candidate, JobOptions $options): array
    {
        $loc = $this->requireField($candidate->locator);
        $rows = $this->store->family($loc, $loc->key);
        $isImage = $candidate->target === TargetType::FieldImage;
        if ($isImage) {
            $alt = $this->store->valueOf($rows, $loc->key . '@alt') ?? '';
            return [new MediaMeta($alt, '', $options->fillAltOnNew, $options->tags, '', true)];
        }
        $name = $this->store->valueOf($rows, $loc->key . '@downloadName');
        if ($name === null || trim($name) === '') {
            $name = $this->store->valueOf($rows, $loc->key . '@baseName') ?? '';
        }
        return [new MediaMeta('', '', false, $options->tags, $name, false)];
    }

    public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array
    {
        $loc = $this->requireField($candidate->locator);
        $fd = $loc->key;
        $dest = $this->names->resolve($fd, $options);
        $mediaId = $mediaIds[0] ?? 0;
        if ($mediaId <= 0) {
            throw new ConversionSkipException(ReasonCode::MediaRegisterFailed, 'media_id がありません。');
        }

        $rows = $this->store->family($loc, $fd);
        $pathRow = $this->firstRow($rows, $fd . '@path');
        if ($pathRow === null || trim(Cast::str($pathRow['field_value'])) === '') {
            throw new ConversionSkipException(ReasonCode::StaleSnapshot, '元のパスが見つかりません。');
        }

        $sameMode = $dest === $fd;
        if ($sameMode) {
            $mediaRow = $this->firstRow($rows, $fd . '@media');
            if ($mediaRow !== null && trim(Cast::str($mediaRow['field_value'])) !== '') {
                throw new ConversionSkipException(ReasonCode::DestConflict, '既にメディアフィールドです。');
            }
        } elseif ($this->store->family($loc, $dest) !== []) {
            throw new ConversionSkipException(ReasonCode::DestConflict);
        }

        $baseRow = $this->firstRow($rows, $fd);
        $search = $baseRow !== null ? Cast::str($baseRow['field_search']) : 'on';

        if ($sameMode) {
            if ($options->removeLegacyKeys) {
                $this->store->deleteFamily($loc, $fd);
            } else {
                $this->store->deleteKeys($loc, [$fd, $fd . '@media']);
            }
        }

        $template = $pathRow;
        $base = $template;
        $base['field_key'] = $dest;
        $base['field_value'] = (string) $mediaId;
        $base['field_type'] = null;
        $base['field_search'] = $search;
        $this->store->insertRow($loc->table(), $base);

        $media = $template;
        $media['field_key'] = $dest . '@media';
        $media['field_value'] = (string) $mediaId;
        $media['field_type'] = 'media';
        $media['field_search'] = 'off';
        $this->store->insertRow($loc->table(), $media);

        $added = [];
        if ($options->appendToGroup) {
            $added = $this->groups->append($loc, $fd, $dest);
        }

        return [
            'rows' => Snapshot::normalizeRows($this->ownRows($loc, $options)),
            'groupAdded' => array_map(static fn (array $r): array => Snapshot::normalizeRow($r), $added),
        ];
    }

    public function restore(LocatorInterface $locator, array $before, array $after, JobOptions $options): void
    {
        $loc = $this->requireField($locator);
        $beforeRows = $before['rows'] ?? null;
        $afterRows = $after['rows'] ?? null;
        if (!is_array($beforeRows) || !is_array($afterRows)) {
            throw new ConflictException('スナップショットが不正です。');
        }
        $current = Snapshot::normalizeRows($this->ownRows($loc, $options));
        if (!Snapshot::equals($current, $afterRows)) {
            throw new ConflictException('変換後にフィールドが編集されています。');
        }

        $fd = $loc->key;
        $dest = $this->names->resolve($fd, $options);
        $this->store->deleteFamily($loc, $fd);
        if ($dest !== $fd) {
            $this->store->deleteFamily($loc, $dest);
        }
        foreach ($beforeRows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $this->store->insertRow($loc->table(), $row);
            }
        }
        $groupAdded = $after['groupAdded'] ?? [];
        // 同じフィールドの別の繰り返し（sort）が変換されたまま残っているあいだは、グループ定義の追記を消さない
        // （そちらの {dest} がグループのループに出なくなる）
        if (is_array($groupAdded) && !$this->store->existsInOtherSort($loc, $dest . '@media')) {
            foreach ($groupAdded as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    $this->store->deleteExact($loc->table(), $row);
                }
            }
        }
    }

    /**
     * このアイテムが所有する行（{fd} 系と {dest} 系）
     *
     * @return list<array<string, mixed>>
     */
    private function ownRows(FieldLocator $loc, JobOptions $options): array
    {
        $fd = $loc->key;
        $dest = $this->names->resolve($fd, $options);
        $rows = $this->store->family($loc, $fd);
        if ($dest !== $fd) {
            $rows = [...$rows, ...$this->store->family($loc, $dest)];
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function firstRow(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if (Cast::str($row['field_key']) === $key) {
                return $row;
            }
        }
        return null;
    }

    private function requireField(LocatorInterface $locator): FieldLocator
    {
        if (!$locator instanceof FieldLocator) {
            throw new \InvalidArgumentException('FieldLocator が必要です。');
        }
        return $locator;
    }
}
