<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\POST\ArchivesToMedia;

use ACMS_POST;
use Acms\Plugins\ArchivesToMedia\Services\Admin\ApiEndpoint;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobNotFoundException;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobService;
use Acms\Services\Facades\Application as App;

/**
 * 結果 CSV のダウンロード（フォームの POST で受ける）
 */
class ExportCsv extends ACMS_POST
{
    public function post()
    {
        $endpoint = App::make('archives-to-media.api-endpoint');
        $service = App::make('archives-to-media.job-service');
        assert($endpoint instanceof ApiEndpoint && $service instanceof JobService);

        if (!$endpoint->canExport(sessionWithAdministration())) {
            httpStatusCode('403 Forbidden');
            die('forbidden');
        }
        $jobId = (int) $this->Post->get('jobId');
        try {
            $service->ensureAccessible($jobId, BID);
        } catch (JobNotFoundException) {
            httpStatusCode('404 Not Found');
            die('not found');
        }
        // 件数が多いジョブでも途中で切れないよう、時間の制限を延ばす（サーバーが許す範囲で）
        @set_time_limit(300);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="archives-to-media-job-' . $jobId . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            die();
        }
        $service->writeCsv($jobId, BID, $out);
        fclose($out);
        die();
    }
}
