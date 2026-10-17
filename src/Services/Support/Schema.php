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
            `job_scan_cursor` varchar(255) DEFAULT NULL,
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
            `item_blog_id` int(11) NOT NULL,
            `item_updated_datetime` datetime NOT NULL,
            PRIMARY KEY (`item_id`),
            UNIQUE KEY `job_locator` (`item_job_id`, `item_locator_hash`),
            KEY `job_status` (`item_job_id`, `item_status`),
            KEY `job_target` (`item_job_id`, `item_target`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->db->t('a2m_path_map') . ' (
            `map_blog_id` int(11) NOT NULL,
            `map_source_path` varchar(512) NOT NULL,
            `map_media_id` int(11) NOT NULL,
            `map_created_by_job` int(11) DEFAULT NULL,
            `map_created_datetime` datetime NOT NULL,
            UNIQUE KEY `blog_path` (`map_blog_id`, `map_source_path`),
            KEY `map_media_id` (`map_media_id`),
            KEY `map_created_by_job` (`map_created_by_job`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }

    public function uninstall(): void
    {
        foreach (['a2m_item', 'a2m_job', 'a2m_path_map'] as $table) {
            $this->db->exec('DROP TABLE IF EXISTS ' . $this->db->t($table));
        }
    }
}
