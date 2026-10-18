<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\UnitData;

/**
 * メディアがユニット・フィールドのどこかから参照されているか。
 *
 * 参照の判定は先頭がワイルドカードの検索（インデックスが効かない）になるので、メディア 1 件ごとに
 * テーブルを走査しない。複数のメディアをまとめて受け取り、テーブルごとに 1 回の走査で調べる。
 */
class MediaReferenceChecker
{
    /** 1 回の走査でまとめて調べるメディアの数 */
    private const BATCH = 200;

    public function __construct(private readonly Db $db)
    {
    }

    public function isReferenced(int $mediaId): bool
    {
        return $this->referencedAmong([$mediaId]) !== [];
    }

    /**
     * 渡したメディアのうち、参照されているもの。
     *
     * @param list<int> $mediaIds
     * @return list<int>
     */
    public function referencedAmong(array $mediaIds): array
    {
        $referenced = [];
        foreach (array_chunk(array_values(array_unique($mediaIds)), self::BATCH) as $batch) {
            $wanted = array_fill_keys($batch, true);
            $found = [...$this->inUnits($wanted), ...$this->inBlockEditors($wanted), ...$this->inFields($wanted)];
            foreach ($found as $id) {
                $referenced[(int) $id] = true;
            }
        }
        return array_keys($referenced);
    }

    /**
     * メディアユニット（column_field_1 に media_id が区切り文字つきで入る）
     *
     * @param array<int|string, true> $wanted
     * @return list<string>
     */
    private function inUnits(array $wanted): array
    {
        $d = preg_quote(UnitData::DELIMITER);
        $regexp = '(^|' . $d . ')(' . implode('|', array_map('strval', array_keys($wanted))) . ')(' . $d . '|$)';
        $found = [];
        foreach (['column', 'column_rev'] as $table) {
            $rows = $this->db->all(
                'SELECT column_field_1 FROM ' . $this->db->t($table) . ' WHERE column_type LIKE \'media%\' AND column_field_1 REGEXP ?',
                [$regexp]
            );
            foreach ($rows as $row) {
                foreach (UnitData::explode(Cast::str($row['column_field_1'])) as $part) {
                    if (isset($wanted[$part])) {
                        $found[] = $part;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * ブロックエディターは本文 HTML の data-mid 属性でメディアを参照する
     *
     * @param array<int|string, true> $wanted
     * @return list<string>
     */
    private function inBlockEditors(array $wanted): array
    {
        $regexp = 'data-mid="(' . implode('|', array_map('strval', array_keys($wanted))) . ')"';
        $found = [];
        foreach (['column', 'column_rev'] as $table) {
            $rows = $this->db->all(
                'SELECT column_field_1 FROM ' . $this->db->t($table) . ' WHERE column_type LIKE \'block-editor%\' AND column_field_1 REGEXP ?',
                [$regexp]
            );
            foreach ($rows as $row) {
                if (preg_match_all('/data-mid="(\d+)"/', Cast::str($row['column_field_1']), $m) > 0) {
                    foreach ($m[1] as $id) {
                        if (isset($wanted[$id])) {
                            $found[] = $id;
                        }
                    }
                }
            }
        }
        return $found;
    }

    /**
     * メディアフィールド（{fd}@media の値が media_id）
     *
     * @param array<int|string, true> $wanted
     * @return list<string>
     */
    private function inFields(array $wanted): array
    {
        $ids = array_map('strval', array_keys($wanted));
        $found = [];
        foreach (['field', 'field_rev'] as $table) {
            $rows = $this->db->all(
                'SELECT DISTINCT field_value FROM ' . $this->db->t($table)
                . ' WHERE field_key LIKE \'%@media\' AND field_value IN (' . $this->db->placeholders($ids) . ')',
                $ids
            );
            foreach ($rows as $row) {
                $found[] = Cast::str($row['field_value']);
            }
        }
        return $found;
    }
}
