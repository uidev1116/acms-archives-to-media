<?php

declare(strict_types=1);

// 共有 bootstrap（本体の起動）に、プラグインのテーブル作成を上乗せする。
// DDL は暗黙コミットされるので、テストごとのトランザクションの外（ここ）で行う。
require_once __DIR__ . '/../vendor/ablogcms/testing-framework/bootstrap.php';

(new \Acms\Plugins\ArchivesToMedia\Services\Support\Schema(new \Acms\Plugins\ArchivesToMedia\Services\Support\Db()))->install();
