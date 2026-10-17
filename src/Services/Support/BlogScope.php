<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * スキャン対象のブログ ID 一覧（実行ブログ、オプションで子孫ブログを含む）
 */
class BlogScope
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return list<int>
     */
    public function blogIds(int $blogId, bool $includeChildren): array
    {
        if (!$includeChildren) {
            return [$blogId];
        }
        $row = $this->db->row('SELECT blog_left, blog_right FROM ' . $this->db->t('blog') . ' WHERE blog_id = ?', [$blogId]);
        if ($row === null) {
            return [$blogId];
        }
        $rows = $this->db->all(
            'SELECT blog_id FROM ' . $this->db->t('blog') . ' WHERE blog_left >= ? AND blog_right <= ? ORDER BY blog_left',
            [(int) $row['blog_left'], (int) $row['blog_right']]
        );
        $ids = array_map(static fn (array $r): int => (int) $r['blog_id'], $rows);
        return $ids === [] ? [$blogId] : $ids;
    }
}
