<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;
use Acms\Plugins\ArchivesToMedia\Services\Support\BlogScope;

/**
 * 画面（AdminApi）から使う操作のまとめ役。
 * すべての操作で、ジョブが現在のブログのものであることを確認する。
 */
class JobService
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ItemRepository $items,
        private readonly ScanCoordinator $scanner,
        private readonly JobRunner $runner,
        private readonly Rollbacker $rollbacker,
        private readonly Cleaner $cleaner,
        private readonly AuditLog $log,
        private readonly BlogScope $scope,
        /** @var \Closure(int): bool 指定ブログの管理権限があるか（sessionWithAdministration($bid)） */
        private readonly \Closure $canAdminister,
    ) {
    }

    /**
     * @param array<mixed> $options
     * @throws \InvalidArgumentException
     */
    public function create(int $blogId, int $userId, array $options): Job
    {
        $parsed = JobOptions::fromArray($options);
        $this->assertCanAdminister($blogId, $parsed);
        $id = $this->jobs->create($blogId, $userId, $parsed);
        $this->log->info('ジョブを作成しました', ['jobId' => $id, 'blogId' => $blogId, 'userId' => $userId, 'options' => $parsed->toArray()]);
        return $this->jobFor($id, $blogId);
    }

    /**
     * @return array{job: array<string, mixed>, summary: list<array<string, mixed>>, counts: array<string, int>, cleanup: array{cleanable: int, cleaned: int}}
     */
    public function get(int $jobId, int $blogId): array
    {
        $job = $this->jobFor($jobId, $blogId);
        return [
            'job' => $job->toArray(),
            'summary' => $this->items->summary($jobId),
            'counts' => $this->items->countByStatus($jobId),
            // 同名モードは変換時に旧キーを置き換え済みなので、クリーンアップの対象は無い
            'cleanup' => $job->options->fieldMode === 'same' ? ['cleanable' => 0, 'cleaned' => 0] : $this->items->cleanupCounts($jobId),
        ];
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, total: int}
     */
    public function list(int $blogId, int $page, int $perPage): array
    {
        $res = $this->jobs->paginate($blogId, $page, $perPage);
        return ['jobs' => array_map(static fn (Job $j): array => $j->toArray(), $res['jobs']), 'total' => $res['total']];
    }

    /**
     * @return array{status: string, scanned: int, total: int, done: bool}
     */
    public function scan(int $jobId, int $blogId): array
    {
        $job = $this->jobToOperate($jobId, $blogId);
        $result = $this->scanner->scanChunk($job);
        if ($result['done']) {
            $this->log->info('スキャンが完了しました', ['jobId' => $jobId, 'total' => $result['total']]);
        }
        return $result;
    }

    /**
     * オプションを変えて再スキャンする（アイテムは作り直し）。
     *
     * @param array<mixed> $options
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function updateOptions(int $jobId, int $blogId, array $options): Job
    {
        $job = $this->jobFor($jobId, $blogId);
        $parsed = JobOptions::fromArray($options);
        $this->assertCanAdminister($blogId, $parsed);
        $this->scanner->reset($job);
        $this->jobs->setOptions($jobId, $parsed);
        return $this->jobFor($jobId, $blogId);
    }

    /**
     * @param list<int>|null $ids
     * @param array<string, string>|null $filter
     */
    public function exclude(int $jobId, int $blogId, ?array $ids, ?array $filter, bool $excluded): int
    {
        $this->jobFor($jobId, $blogId);
        if ($filter !== null) {
            return $this->items->setExcludedByFilter($jobId, $filter, $excluded);
        }
        return $this->items->setExcluded($jobId, $ids ?? [], $excluded);
    }

    public function run(int $jobId, int $blogId): ChunkResult
    {
        $job = $this->jobToOperate($jobId, $blogId);
        $first = $job->status === JobStatus::Scanned;
        if ($first) {
            $this->log->info('変換を開始しました', ['jobId' => $jobId, 'userId' => $job->userId, 'options' => $job->options->toArray()]);
        }
        $result = $this->runner->runChunk($job);
        if ($result->done) {
            $this->log->info('変換が完了しました', ['jobId' => $jobId, 'converted' => $this->jobs->find($jobId)?->converted]);
        }
        if ($result->error > 0) {
            $this->log->notice('変換でエラーになったアイテムがあります', ['jobId' => $jobId, 'error' => $result->error]);
        }
        return $result;
    }

    public function pause(int $jobId, int $blogId): Job
    {
        $this->runner->pause($this->jobFor($jobId, $blogId));
        return $this->jobFor($jobId, $blogId);
    }

    public function requeue(int $jobId, int $blogId): int
    {
        return $this->runner->requeue($this->jobToOperate($jobId, $blogId));
    }

    public function rollback(int $jobId, int $blogId, bool $deleteCreatedMedia): ChunkResult
    {
        $job = $this->jobToOperate($jobId, $blogId);
        if ($job->status !== JobStatus::RollingBack) {
            $this->log->info('ロールバックを開始しました', ['jobId' => $jobId, 'deleteCreatedMedia' => $deleteCreatedMedia]);
        }
        $result = $this->rollbacker->rollbackChunk($job, $deleteCreatedMedia);
        if ($result->done) {
            $this->log->info('ロールバックが完了しました', ['jobId' => $jobId, 'restored' => $result->converted, 'conflicts' => $result->error]);
        }
        return $result;
    }

    public function cleanup(int $jobId, int $blogId): ChunkResult
    {
        $job = $this->jobToOperate($jobId, $blogId);
        $result = $this->cleaner->cleanupChunk($job);
        if ($result->done) {
            $this->log->info('旧データのクリーンアップが完了しました', ['jobId' => $jobId]);
        }
        return $result;
    }

    /**
     * 変換済みのアイテムが残っているジョブや、処理中のジョブは削除できない。
     */
    public function delete(int $jobId, int $blogId): void
    {
        $job = $this->jobFor($jobId, $blogId);
        $active = [JobStatus::Scanning, JobStatus::Running, JobStatus::RollingBack];
        $hasConverted = ($this->items->countByStatus($jobId)[ItemStatus::Converted->value] ?? 0) > 0;
        if (in_array($job->status, $active, true) || $hasConverted) {
            throw new \LogicException('処理中、または変換済みのアイテムが残っているジョブは削除できません。先にロールバックしてください。');
        }
        $this->jobs->delete($jobId);
        $this->log->info('ジョブを削除しました', ['jobId' => $jobId]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function items(int $jobId, int $blogId, array $filters, string $sort, bool $desc, int $page, int $perPage): array
    {
        $this->jobFor($jobId, $blogId);
        $res = $this->items->search($jobId, $filters, $sort, $desc, $page, $perPage);
        return ['items' => array_map($this->presentRow(...), $res['items']), 'total' => $res['total']];
    }

    /**
     * ジョブが存在し、このブログのものであることを確かめる（CSV のヘッダーを出す前の確認用）。
     *
     * @throws JobNotFoundException
     */
    public function ensureAccessible(int $jobId, int $blogId): void
    {
        $this->jobFor($jobId, $blogId);
    }

    /**
     * 結果 CSV（UTF-8 BOM 付き）をストリームへ書き出す。件数が多くてもメモリを使い切らないよう、200 件ずつ書く。
     * 表計算ソフトで数式として解釈されないよう、先頭が = + - @ の値は無害化する。
     *
     * @param resource $stream
     * @throws JobNotFoundException
     */
    public function writeCsv(int $jobId, int $blogId, $stream): void
    {
        $this->jobFor($jobId, $blogId);
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['item_id', 'target', 'owner_type', 'owner_id', 'status', 'reason', 'source_path', 'media_ids', 'message'], ',', '"', '\\');
        $page = 1;
        do {
            $res = $this->items->search($jobId, [], 'item_id', false, $page, 200);
            foreach ($res['items'] as $row) {
                $r = $this->presentRow($row);
                fputcsv($stream, array_map($this->csvCell(...), [
                    $r['id'], $r['target'], $r['ownerType'], $r['ownerId'], $r['status'], $r['reason'],
                    implode(' | ', $r['sourcePaths']), implode(',', $r['mediaIds']), Cast::str($r['message']),
                ]), ',', '"', '\\');
            }
            $page++;
        } while (count($res['items']) === 200);
    }

    /**
     * 子ブログを含める場合は、含める全ブログへの管理権限を確認する（設計書 §12）。
     *
     * @throws ForbiddenException
     */
    private function assertCanAdminister(int $blogId, JobOptions $options): void
    {
        if (!$options->includeChildBlogs) {
            return;
        }
        foreach ($this->scope->blogIds($blogId, true) as $id) {
            if (!($this->canAdminister)($id)) {
                throw new ForbiddenException('子ブログ（ID: ' . $id . '）の管理権限がありません。');
            }
        }
    }

    private function csvCell(mixed $value): string
    {
        $s = Cast::str($value);
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, target: string, table: string, locator: mixed, ownerType: string, ownerId: string, sourcePaths: list<string>, preview: string, mediaIds: list<int>, status: string, reason: string, reasonLabel: string, message: string|null, blogId: int}
     */
    private function presentRow(array $row): array
    {
        $paths = json_decode(Cast::str($row['item_source_paths']), true);
        $reason = ReasonCode::tryFrom(Cast::str($row['item_reason']));
        return [
            'id' => Cast::int($row['item_id']),
            'target' => Cast::str($row['item_target']),
            'table' => Cast::str($row['item_table']),
            'locator' => json_decode(Cast::str($row['item_locator']), true),
            'ownerType' => Cast::str($row['item_owner_type']),
            'ownerId' => Cast::str($row['item_owner_id']),
            'sourcePaths' => is_array($paths) ? array_values(array_map(static fn (mixed $p): string => Cast::str($p), $paths)) : [],
            'preview' => Cast::str($row['item_preview']),
            'mediaIds' => array_values(array_map('intval', array_filter(explode(',', Cast::str($row['item_media_ids'])), static fn (string $v): bool => $v !== ''))),
            'status' => Cast::str($row['item_status']),
            'reason' => Cast::str($row['item_reason']),
            'reasonLabel' => $reason?->label() ?? '',
            'message' => $row['item_message'] === null ? null : Cast::str($row['item_message']),
            'blogId' => Cast::int($row['item_blog_id']),
        ];
    }

    /**
     * データを書き換える操作の対象ジョブ。ジョブを作った人とは別の管理者が操作することもあるので、
     * 子ブログを含むジョブは、操作する人が全子孫ブログの管理権限を持つことを毎回確認する。
     *
     * @throws JobNotFoundException
     * @throws ForbiddenException
     */
    private function jobToOperate(int $jobId, int $blogId): Job
    {
        $job = $this->jobFor($jobId, $blogId);
        $this->assertCanAdminister($blogId, $job->options);
        return $job;
    }

    private function jobFor(int $jobId, int $blogId): Job
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->blogId !== $blogId) {
            throw new JobNotFoundException('ジョブが見つかりません。');
        }
        return $job;
    }
}
