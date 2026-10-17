<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorInterface;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;

/**
 * column / column_rev の行を書き換えるコンバーターの共通処理
 */
abstract class AbstractUnitConverter implements ConverterInterface
{
    public function __construct(protected readonly Db $db)
    {
    }

    public function snapshot(LocatorInterface $locator, JobOptions $options): ?array
    {
        $row = $this->loadRow($locator);
        return $row === null ? null : ['row' => Snapshot::normalizeRow($row)];
    }

    public function restore(LocatorInterface $locator, array $before, array $after, JobOptions $options): void
    {
        $current = $this->loadRow($locator);
        if ($current === null || !isset($after['row'], $before['row']) || !is_array($after['row']) || !is_array($before['row'])) {
            throw new ConflictException('変換後の行が見つかりません。');
        }
        if (!Snapshot::equals(Snapshot::normalizeRow($current), $after['row'])) {
            throw new ConflictException('変換後にユニットが編集されています。');
        }
        $this->writeRow($locator, $before['row'], array_keys($current));
    }

    protected function requireColumn(LocatorInterface $locator): ColumnLocator
    {
        if (!$locator instanceof ColumnLocator) {
            throw new \InvalidArgumentException('ColumnLocator が必要です。');
        }
        return $locator;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadRow(LocatorInterface $locator): ?array
    {
        $loc = $this->requireColumn($locator);
        if ($loc->revId === null) {
            return $this->db->row('SELECT * FROM ' . $this->db->t('column') . ' WHERE column_id = ?', [$loc->columnId]);
        }
        return $this->db->row(
            'SELECT * FROM ' . $this->db->t('column_rev') . ' WHERE column_id = ? AND column_rev_id = ?',
            [$loc->columnId, $loc->revId]
        );
    }

    /**
     * 主キー以外のカラムを書き込む（カラム名は実在するものだけを受け付ける）
     *
     * @param array<string, mixed> $values
     * @param list<string> $allowedColumns
     */
    protected function writeRow(LocatorInterface $locator, array $values, array $allowedColumns): void
    {
        $loc = $this->requireColumn($locator);
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, $allowedColumns, true) || $column === 'column_id' || $column === 'column_rev_id') {
                continue;
            }
            $sets[] = '`' . $column . '` = ?';
            $params[] = $value === null ? null : (string) $value;
        }
        if ($sets === []) {
            return;
        }
        $where = 'column_id = ?';
        $params[] = $loc->columnId;
        if ($loc->revId !== null) {
            $where .= ' AND column_rev_id = ?';
            $params[] = $loc->revId;
        }
        $this->db->exec('UPDATE ' . $this->db->t($loc->table()) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where, $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireRow(LocatorInterface $locator): array
    {
        $row = $this->loadRow($locator);
        if ($row === null) {
            throw new ConversionSkipException(\Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode::StaleSnapshot, 'ユニットが見つかりません。');
        }
        return $row;
    }
}
