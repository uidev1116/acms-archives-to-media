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
            [new \Acms\Plugins\ArchivesToMedia\Services\Support\InvalidInputException('bad'), 422, 'invalid'],
            [new JobNotFoundException('nf'), 404, 'not_found'],
            [new ForbiddenException('no'), 403, 'forbidden'],
            [new JobBusyException('busy'), 409, 'busy'],
            [new \Acms\Plugins\ArchivesToMedia\Services\Support\StateException('state'), 409, 'state'],
            // このプラグインが書いたものではない例外は、文言を出さない
            [new \InvalidArgumentException('core internal detail'), 500, 'internal'],
            [new \LogicException('core internal detail'), 500, 'internal'],
            [new \RuntimeException('boom'), 500, 'internal'],
        ];
        foreach ($cases as [$e, $status, $code]) {
            $res = $this->endpoint($e)->execute('run', ['jobId' => '1'], true, 1, 1);
            $this->assertSame($status, $res['status'], $code);
            $this->assertSame($code, $res['body']['code']);
            if ($status === 500) {
                $this->assertStringNotContainsString('boom', \Acms\Plugins\ArchivesToMedia\Services\Support\Cast::str($res['body']['error']), '内部エラーの詳細は画面に出さない');
                $this->assertStringNotContainsString('core internal detail', \Acms\Plugins\ArchivesToMedia\Services\Support\Cast::str($res['body']['error']));
            } else {
                $this->assertSame($e->getMessage(), $res['body']['error']);
            }
        }
    }

    #[Test]
    public function リクエストからは許可したキーだけを読み_ブログとユーザーをそのまま渡す(): void
    {
        $api = $this->createMock(AdminApi::class);
        $api->expects($this->once())->method('handle')->with(
            'items',
            ['jobId' => '7', 'page' => '2'],
            42,
            9,
        )->willReturn(['ok' => true]);
        $read = [];
        $post = ['action' => 'items', 'jobId' => '7', 'page' => '2', 'evil' => 'x', 'formToken' => 'secret'];

        $res = (new ApiEndpoint($api, $this->createMock(AuditLog::class)))->executeRequest(
            static function (string $key) use (&$read, $post): ?string {
                $read[] = $key;
                return $post[$key] ?? null;
            },
            true,
            42,
            9,
        );

        $this->assertSame(200, $res['status']);
        $this->assertNotContains('evil', $read, '許可していないキーは読まない');
        $this->assertNotContains('formToken', $read);
    }

    #[Test]
    public function リクエストに操作名が無ければ400(): void
    {
        $res = $this->endpoint()->executeRequest(static fn (string $key): ?string => null, true, 1, 1);
        $this->assertSame(400, $res['status']);
    }

    #[Test]
    public function CSV出力は管理者だけ(): void
    {
        $this->assertFalse($this->endpoint()->canExport(false));
        $this->assertTrue($this->endpoint()->canExport(true));
    }
}
