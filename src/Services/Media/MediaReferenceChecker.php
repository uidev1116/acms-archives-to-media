<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\UnitData;

/**
 * メディアがユニット・フィールドのどこかから参照されているか
 */
class MediaReferenceChecker
{
    public function __construct(private readonly Db $db)
    {
    }

    public function isReferenced(int $mediaId): bool
    {
        $id = (string) $mediaId;
        $d = $this->db->escapeLike(UnitData::DELIMITER);
        foreach (['column', 'column_rev'] as $table) {
            $count = (int) $this->db->one(
                'SELECT COUNT(*) FROM ' . $this->db->t($table) . '
                 WHERE column_type LIKE \'media%\' AND (column_field_1 = ? OR column_field_1 LIKE ? OR column_field_1 LIKE ? OR column_field_1 LIKE ?)',
                [$id, $id . $d . '%', '%' . $d . $id, '%' . $d . $id . $d . '%']
            );
            if ($count > 0) {
                return true;
            }
        }
        foreach (['field', 'field_rev'] as $table) {
            $count = (int) $this->db->one(
                'SELECT COUNT(*) FROM ' . $this->db->t($table) . ' WHERE field_key LIKE \'%@media\' AND field_value = ?',
                [$id]
            );
            if ($count > 0) {
                return true;
            }
        }
        return false;
    }
}
