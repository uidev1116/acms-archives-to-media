<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\POST\ArchivesToMedia;

use ACMS_POST;
use Acms\Plugins\ArchivesToMedia\Services\Admin\ApiEndpoint;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Services\Facades\Application as App;
use Acms\Services\Facades\Response;

/**
 * 画面からの全操作の入口。ACMS_POST の基底クラスが formToken（CSRF）を検証した後、
 * 権限の確認とサービス呼び出しを ApiEndpoint に任せ、JSON で返す。
 */
class Api extends ACMS_POST
{
    public function post()
    {
        $endpoint = App::make('archives-to-media.api-endpoint');
        assert($endpoint instanceof ApiEndpoint);

        @set_time_limit(60);
        $post = $this->Post;
        $result = $endpoint->executeRequest(
            static fn (string $key): ?string => $post->isExists($key) ? Cast::str($post->get($key)) : null,
            sessionWithAdministration(),
            BID,
            (int) SUID,
        );
        // Response::json() はステータスを 200 に固定するので、エラーのステータスを返すために自前で出力する
        Response::safeHeaders($result['status'], 'application/json');
        echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        die();
    }
}
