<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * プラグインのテーブル（a2m_job / a2m_item / a2m_path_map）の作成と削除
 */
final class Schema
{
    public function __construct(private readonly Db $db)
    {
    }

    public function install(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->db->t('a2m_job') . ' (
            `job_id` int(11) NOT NULL AUTO_INCREMENT,
            `job_status` varchar(16) NOT NULL DEFAULT \'draft\',
            `job_options` longtext NOT NULL,
            `job_total` int(11) NOT NULL DEFAULT 0,
            `job_converted` int(11) NOT NULL DEFAULT 0,
            `job_skipped` int(11) NOT NULL DEFAULT 0,
            `job_error` int(11) NOT NULL DEFAULT 0,
            `job_scan_cursor` text DEFAULT NULL,
            `job_message` text DEFAULT NULL,
            `job_user_id` int(11) NOT NULL,
            `job_blog_id` int(11) NOT NULL,
            `job_created_datetime` datetime NOT NULL,
            `job_started_datetime` datetime DEFAULT NULL,
            `job_finished_datetime` datetime DEFAULT NULL,
            PRIMARY KEY (`job_id`),
            KEY `job_blog_id` (`job_blog_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->db->t('a2m_item') . ' (
            `item_id` int(11) NOT NULL AUTO_INCREMENT,
            `item_job_id` int(11) NOT NULL,
            `item_target` varchar(16) NOT NULL,
            `item_table` varchar(16) NOT NULL,
            `item_locator` varchar(512) NOT NULL,
            `item_locator_hash` char(40) NOT NULL,
            `item_owner_type` varchar(8) NOT NULL DEFAULT \'\',
            `item_owner_id` varchar(36) NOT NULL DEFAULT \'\',
            `item_source_paths` text NOT NULL,
            `item_preview` varchar(16) NOT NULL DEFAULT \'\',
            `item_media_ids` varchar(255) NOT NULL DEFAULT \'\',
            `item_status` varchar(16) NOT NULL DEFAULT \'pending\',
            `item_reason` varchar(32) NOT NULL DEFAULT \'\',
            `item_message` text DEFAULT NULL,
            `item_before` longtext DEFAULT NULL,
            `item_after` longtext DEFAULT NULL,
            `item_cleanup` longtext DEFAULT NULL,
            `item_blog_id` int(11) NOT NULL,
            `item_updated_datetime` datetime NOT NULL,
            PRIMARY KEY (`item_id`),
            UNIQUE KEY `job_locator` (`item_job_id`, `item_locator_hash`),
            KEY `job_status` (`item_job_id`, `item_status`),
            KEY `job_target` (`item_job_id`, `item_target`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->db->t('a2m_path_map') . ' (
            `map_blog_id` int(11) NOT NULL,
            `map_source_path` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `map_path_hash` char(40) NOT NULL,
            `map_media_id` int(11) NOT NULL,
            `map_created_by_job` int(11) DEFAULT NULL,
            `map_created_datetime` datetime NOT NULL,
            UNIQUE KEY `blog_path_hash` (`map_blog_id`, `map_path_hash`),
            KEY `map_media_id` (`map_media_id`),
            KEY `map_created_by_job` (`map_created_by_job`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $this->migrate();
    }

    /**
     * 既に作成済みのテーブルを現在の定義にそろえる（何度実行しても安全）。
     *
     * - パスは大文字小文字を区別する（Linux のファイル名と同じ）。ユニークキーは長いパスそのものではなく
     *   sha1 のハッシュ列に張る（古い MySQL のキー長制限を避ける）。
     * - スキャンのカーソルは長くなりうるので text。
     * - クリーンアップで消した旧キーの行を item_cleanup に控え、ロールバックで戻せるようにする。
     */
    private function migrate(): void
    {
        $path = 'a2m_path_map';
        if (!$this->columnExists($path, 'map_path_hash')) {
            $this->db->exec('ALTER TABLE ' . $this->db->t($path) . ' ADD COLUMN `map_path_hash` char(40) NOT NULL DEFAULT \'\' AFTER `map_source_path`');
        }
        $this->db->exec('UPDATE ' . $this->db->t($path) . ' SET map_path_hash = SHA1(map_source_path) WHERE map_path_hash = \'\'');
        if ($this->indexExists($path, 'blog_path')) {
            $this->db->exec('ALTER TABLE ' . $this->db->t($path) . ' DROP INDEX `blog_path`');
        }
        if (!$this->indexExists($path, 'blog_path_hash')) {
            $this->db->exec('ALTER TABLE ' . $this->db->t($path) . ' ADD UNIQUE KEY `blog_path_hash` (`map_blog_id`, `map_path_hash`)');
        }
        $this->db->exec('ALTER TABLE ' . $this->db->t($path) . ' MODIFY `map_source_path` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        $this->db->exec('ALTER TABLE ' . $this->db->t('a2m_job') . ' MODIFY `job_scan_cursor` text DEFAULT NULL');
        if (!$this->columnExists('a2m_item', 'item_cleanup')) {
            $this->db->exec('ALTER TABLE ' . $this->db->t('a2m_item') . ' ADD COLUMN `item_cleanup` longtext DEFAULT NULL AFTER `item_after`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return Cast::int($this->db->one(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [DB_PREFIX . $table, $column]
        )) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return Cast::int($this->db->one(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [DB_PREFIX . $table, $index]
        )) > 0;
    }

    /**
     * ロールバックに必要な情報（item_before / item_after）が残っている変換済みアイテムの数。テーブルが無ければ 0。
     */
    public function rollbackableItems(): int
    {
        $exists = Cast::int($this->db->one(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [DB_PREFIX . 'a2m_item']
        ));
        if ($exists === 0) {
            return 0;
        }
        return Cast::int($this->db->one('SELECT COUNT(*) FROM ' . $this->db->t('a2m_item') . ' WHERE item_status = \'converted\''));
    }

    /**
     * テーブルを削除する。ロールバックできる変換済みアイテムが残っていれば、$force でない限り拒否する
     * （削除するとロールバックできなくなるため）。
     *
     * @throws \RuntimeException
     */
    public function uninstall(bool $force = false): void
    {
        $count = $this->rollbackableItems();
        if (!$force && $count > 0) {
            throw new \RuntimeException(
                'ロールバックできる変換済みアイテムが ' . $count . ' 件あります。アンインストールするとロールバックできなくなるため中止しました。'
                . '先にロールバックするか、不要であれば config.user.php（ablogcms/ 直下）で ARCHIVES_TO_MEDIA_FORCE_UNINSTALL を true に定義してください。'
            );
        }
        foreach (['a2m_item', 'a2m_job', 'a2m_path_map'] as $table) {
            $this->db->exec('DROP TABLE IF EXISTS ' . $this->db->t($table));
        }
    }
}
