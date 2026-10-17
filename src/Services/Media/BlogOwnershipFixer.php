<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

/**
 * registerLocalFile() は現在の BID にメディアを登録するので、データの所有ブログと違う場合に補正する。
 * 再利用したメディアには使わない。
 */
class BlogOwnershipFixer
{
    public function __construct(private readonly Db $db)
    {
    }

    public function fix(int $mediaId, int $ownerBlogId): void
    {
        $this->db->exec(
            'UPDATE ' . $this->db->t('media') . ' SET media_blog_id = ? WHERE media_id = ? AND media_blog_id <> ?',
            [$ownerBlogId, $mediaId, $ownerBlogId]
        );
    }
}
