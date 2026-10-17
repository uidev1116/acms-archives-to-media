<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Admin;

/**
 * 画面の初期データ（JSON として script タグで渡す）。CSRF トークンは管理画面の meta タグ（window.csrfToken）から取る。
 */
class AdminBootstrap
{
    public function __construct(
        private readonly int $blogId,
        private readonly string $version,
        private readonly string $adminUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'blogId' => $this->blogId,
            'version' => $this->version,
            'adminUrl' => $this->adminUrl,
            'actions' => ApiEndpoint::ACTIONS,
            'csv' => ['action' => 'ACMS_POST_ArchivesToMedia_ExportCsv'],
            'api' => ['action' => 'ACMS_POST_ArchivesToMedia_Api'],
        ];
    }
}
