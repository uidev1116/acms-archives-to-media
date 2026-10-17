<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Repository;

use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

/**
 * archives 相対パス → media_id の対応表（ジョブをまたいで使う）
 */
class PathMapRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * 対応表から media_id を引く。メディアが既に削除されている行は無視する。
     */
    public function find(int $blogId, string $path): ?int
    {
        $mediaId = $this->db->one(
            'SELECT m.map_media_id FROM ' . $this->db->t('a2m_path_map') . ' m
             INNER JOIN ' . $this->db->t('media') . ' d ON d.media_id = m.map_media_id
             WHERE m.map_blog_id = ? AND m.map_source_path = ?',
            [$blogId, $path]
        );
        return $mediaId === null ? null : (int) $mediaId;
    }

    public function save(int $blogId, string $path, int $mediaId, ?int $createdByJob): void
    {
        // 古い（メディア削除済み）行があれば置き換える
        $this->db->exec(
            'DELETE FROM ' . $this->db->t('a2m_path_map') . ' WHERE map_blog_id = ? AND map_source_path = ?',
            [$blogId, $path]
        );
        $this->db->exec(
            'INSERT INTO ' . $this->db->t('a2m_path_map') . ' (map_blog_id, map_source_path, map_media_id, map_created_by_job, map_created_datetime)
             VALUES (?, ?, ?, ?, ?)',
            [$blogId, $path, $mediaId, $createdByJob, date('Y-m-d H:i:s')]
        );
    }

    /**
     * そのジョブが新規作成したメディア
     *
     * @return list<int>
     */
    public function mediaIdsCreatedByJob(int $jobId): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT map_media_id FROM ' . $this->db->t('a2m_path_map') . ' WHERE map_created_by_job = ?',
            [$jobId]
        );
        return array_values(array_map(static fn (array $r): int => (int) $r['map_media_id'], $rows));
    }

    public function deleteByMedia(int $mediaId): void
    {
        $this->db->exec('DELETE FROM ' . $this->db->t('a2m_path_map') . ' WHERE map_media_id = ?', [$mediaId]);
    }
}
