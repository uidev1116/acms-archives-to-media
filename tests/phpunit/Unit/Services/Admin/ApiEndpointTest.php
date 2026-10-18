<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Admin;

use Acms\Plugins\ArchivesToMedia\Services\Admin\AdminApi;
use Acms\Plugins\ArchivesToMedia\Services\Admin\ApiEndpoint;
use Acms\Plugins\ArchivesToMedia\Services\Job\ForbiddenException;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobNotFoundException;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ApiEndpointTest extends TestCase
{
    /**
     * @param array<string, mixed> $returns
     */
    private function endpoint(?\Throwable $throws = null, array $returns = ['ok' => true]): ApiEndpoint
    {
        $api = $this->createMock(AdminApi::class);
        if ($throws !== null) {
            $api->method('handle')->willThrowException($throws);
        } else {
            $api->method('handle')->willReturn($returns);
        }
        return new ApiEndpoint($api, $this->createMock(AuditLog::class));
    }

    #[Test]
    public function 管理者でなければ403_APIは呼ばない(): void
    {
        $api = $this->createMock(AdminApi::class);
        $api->expects($this->never())->method('handle');
        $res = (new ApiEndpoint($api, $this->createMock(AuditLog::class)))->execute('scan', [], false, 1, 1);
        $this->assertSame(403, $res['status']);
        $this->assertSame('forbidden', $res['body']['code']);
    }

    #[Test]
    public function 許可されていない操作は400(): void
    {
        $res = $this->endpoint()->execute('drop_everything', [], true, 1, 1);
        $this->assertSame(400, $res['status']);
    }

    #[Test]
    #[DataProvider('actions')]
    public function 許可された操作は結果をそのまま返す(string $action): void
    {
        $res = $this->endpoint(null, ['x' => 1])->execute($action, ['jobId' => '1'], true, 1, 1);
        $this->assertSame(200, $res['status']);
        $this->assertSame(['x' => 1], $res['body']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function actions(): array
    {
        $out = [];
        foreach (ApiEndpoint::ACTIONS as $a) {
            $out[$a] = [$a];
        }
        return $out;
    }

    #[Test]
    public function 例外はHTTPステータスとコードに変換される(): void
    {
        $cases = [
            [new \InvalidArgumentException('bad'), 422, 'invalid'],
            [new JobNotFoundException('nf'), 404, 'not_found'],
            [new ForbiddenException('no'), 403, 'forbidden'],
            [new JobBusyException('busy'), 409, 'busy'],
            [new \LogicException('state'), 409, 'state'],
            [new \RuntimeException('boom'), 500, 'internal'],
        ];
        foreach ($cases as [$e, $status, $code]) {
            $res = $this->endpoint($e)->execute('run', ['jobId' => '1'], true, 1, 1);
            $this->assertSame($status, $res['status'], $code);
            $this->assertSame($code, $res['body']['code']);
            if ($status === 500) {
                $this->assertStringNotContainsString('boom', \Acms\Plugins\ArchivesToMedia\Services\Support\Cast::str($res['body']['error']), '内部エラーの詳細は画面に出さない');
            } else {
                $this->assertSame($e->getMessage(), $res['body']['error']);
            }
        }
    }

    #[Test]
    public function CSV出力は管理者だけ(): void
    {
        $this->assertFalse($this->endpoint()->canExport(false));
        $this->assertTrue($this->endpoint()->canExport(true));
    }
}
