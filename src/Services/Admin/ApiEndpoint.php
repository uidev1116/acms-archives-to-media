<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Admin;

use Acms\Plugins\ArchivesToMedia\Services\Job\ForbiddenException;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobNotFoundException;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;

/**
 * POST ハンドラの中身。権限の結果（呼び出し側が sessionWithAdministration() で判定したもの）と入力を受け取り、
 * HTTP ステータスと JSON 本体に変換する。CSRF トークンの検証は ACMS_POST の基底クラスが済ませている。
 */
class ApiEndpoint
{
    public const ACTIONS = [
        'definitions', 'jobs', 'job', 'create', 'update', 'scan', 'exclude',
        'items', 'run', 'pause', 'requeue', 'rollback', 'delete',
    ];

    public function __construct(private readonly AdminApi $api, private readonly AuditLog $log)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status: int, body: array<string, mixed>}
     */
    public function execute(string $action, array $input, bool $isAdministrator, int $blogId, int $userId): array
    {
        if (!$isAdministrator) {
            return $this->error(403, 'forbidden', '管理者権限が必要です。');
        }
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->error(400, 'bad_action', '不明な操作です。');
        }
        try {
            return ['status' => 200, 'body' => $this->api->handle($action, $input, $blogId, $userId)];
        } catch (\InvalidArgumentException $e) {
            return $this->error(422, 'invalid', $e->getMessage());
        } catch (ForbiddenException $e) {
            return $this->error(403, 'forbidden', $e->getMessage());
        } catch (JobNotFoundException $e) {
            return $this->error(404, 'not_found', $e->getMessage());
        } catch (JobBusyException $e) {
            return $this->error(409, 'busy', $e->getMessage());
        } catch (\LogicException $e) {
            return $this->error(409, 'state', $e->getMessage());
        } catch (\Throwable $e) {
            $this->log->notice('操作に失敗しました', ['action' => $action, 'error' => $e->getMessage()]);
            return $this->error(500, 'internal', $e->getMessage());
        }
    }

    public function canExport(bool $isAdministrator): bool
    {
        return $isAdministrator;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message, 'code' => $code]];
    }
}
