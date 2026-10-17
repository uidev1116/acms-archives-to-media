<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Services\Media\Helper;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

/**
 * 新規メディアの alt（media_field_3）・キャプション（media_field_1）・タグを書く。再利用したメディアは書き換えない。
 */
class MediaMetaWriter
{
    public function __construct(private readonly Db $db, private readonly Helper $media)
    {
    }

    public function write(int $mediaId, int $ownerBlogId, MediaMeta $meta): void
    {
        if ($meta->fillAlt) {
            $sets = [];
            $params = [];
            if ($meta->alt !== '') {
                $sets[] = 'media_field_3 = ?';
                $params[] = $meta->alt;
            }
            if ($meta->caption !== '') {
                $sets[] = 'media_field_1 = ?';
                $params[] = $meta->caption;
            }
            if ($sets !== []) {
                $params[] = $mediaId;
                $this->db->exec('UPDATE ' . $this->db->t('media') . ' SET ' . implode(', ', $sets) . ' WHERE media_id = ?', $params);
            }
        }
        if ($meta->tags !== []) {
            $this->media->saveTags($mediaId, implode(',', $meta->tags), $ownerBlogId);
        }
    }
}
