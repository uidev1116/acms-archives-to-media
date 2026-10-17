<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Admin;

use Acms\Plugins\ArchivesToMedia\Services\Job\JobService;

/**
 * 画面からのリクエストを操作名で振り分ける。リクエストやセッションには依存せず、入力は配列で受け取る。
 * 権限・CSRF の確認は呼び出し側（POST ハンドラ）の責務。
 */
class AdminApi
{
    public function __construct(private readonly JobService $service, private readonly DefinitionProvider $definitions)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws \InvalidArgumentException 入力が不正
     * @throws \Acms\Plugins\ArchivesToMedia\Services\Job\JobNotFoundException
     * @throws \Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException 同じブログで別の処理が実行中
     * @throws \LogicException 今の状態では実行できない操作
     */
    public function handle(string $action, array $input, int $blogId, int $userId): array
    {
        return match ($action) {
            'definitions' => $this->definitions->get($blogId),
            'jobs' => $this->service->list($blogId, $this->int($input, 'page', 1, false), 20),
            'job' => $this->service->get($this->jobId($input), $blogId),
            'create' => ['job' => $this->service->create($blogId, $userId, $this->json($input, 'options'))->toArray()],
            'update' => ['job' => $this->service->updateOptions($this->jobId($input), $blogId, $this->json($input, 'options'))->toArray()],
            'scan' => $this->service->scan($this->jobId($input), $blogId),
            'exclude' => ['updated' => $this->exclude($input, $blogId)],
            'items' => $this->items($input, $blogId),
            'run' => $this->service->run($this->jobId($input), $blogId)->toArray(),
            'pause' => ['job' => $this->service->pause($this->jobId($input), $blogId)->toArray()],
            'requeue' => $this->requeue($input, $blogId),
            'rollback' => $this->service->rollback($this->jobId($input), $blogId, $this->bool($input, 'deleteCreatedMedia'))->toArray(),
            'delete' => $this->delete($input, $blogId),
            default => throw new \InvalidArgumentException('不明な操作です。'),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function requeue(array $input, int $blogId): array
    {
        $jobId = $this->jobId($input);
        $count = $this->service->requeue($jobId, $blogId);
        return ['requeued' => $count, 'job' => $this->service->get($jobId, $blogId)['job']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{deleted: true}
     */
    private function delete(array $input, int $blogId): array
    {
        $this->service->delete($this->jobId($input), $blogId);
        return ['deleted' => true];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exclude(array $input, int $blogId): int
    {
        $filter = isset($input['filter']) && $input['filter'] !== '' ? $this->filterOf($this->json($input, 'filter')) : null;
        $ids = null;
        if ($filter === null) {
            $raw = $input['ids'] ?? [];
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (!is_array($raw)) {
                throw new \InvalidArgumentException('ids が不正です。');
            }
            $ids = [];
            foreach ($raw as $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    throw new \InvalidArgumentException('ids が不正です。');
                }
                $ids[] = (int) $id;
            }
        }
        return $this->service->exclude($this->jobId($input), $blogId, $ids, $filter, $this->bool($input, 'excluded'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function items(array $input, int $blogId): array
    {
        $filters = [];
        foreach (['target', 'status', 'reason', 'owner', 'preview', 'q'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && $input[$key] !== '') {
                $filters[$key] = $input[$key];
            }
        }
        return $this->service->items(
            $this->jobId($input),
            $blogId,
            $filters,
            is_string($input['sort'] ?? null) ? $input['sort'] : 'item_id',
            $this->bool($input, 'desc'),
            $this->int($input, 'page', 1, false),
            $this->int($input, 'perPage', 50, false),
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<string, string>
     */
    private function filterOf(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function jobId(array $input): int
    {
        return $this->int($input, 'jobId', 0, true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function int(array $input, string $key, int $default, bool $required): int
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            if ($required) {
                throw new \InvalidArgumentException($key . ' が必要です。');
            }
            return $default;
        }
        $v = $input[$key];
        if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            $v = (int) $v;
        }
        if (!is_int($v)) {
            throw new \InvalidArgumentException($key . ' が不正です。');
        }
        if ($required && $v < 1) {
            throw new \InvalidArgumentException($key . ' が不正です。');
        }
        return $required ? $v : max($default > 0 ? 1 : 0, $v);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function bool(array $input, string $key): bool
    {
        return in_array($input[$key] ?? false, [true, 1, '1', 'true', 'on'], true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<mixed>
     */
    private function json(array $input, string $key): array
    {
        $raw = $input[$key] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException($key . ' が必要です。');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException($key . ' が不正な JSON です。');
        }
        return $data;
    }
}
