<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\StateException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Item;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Support\ErrorMessage;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;
use Acms\Services\Common\Lock;
use Acms\Services\Facades\Database;

/**
 * 旧データのクリーンアップ（設計書 §10.4）。
 *
 * 別名モードで残した旧キー（{fd}@path など）を、テンプレートの移行が終わった後に消す。
 * 消した行は item_cleanup に控えるので、ロールバックでは旧キーも含めて完全に戻せる。
 * archives の実ファイルは消さない。
 */
class Cleaner
{
    /**
     * @param \Closure(): Lock $lockFactory
     */
    public function __construct(
        private readonly ItemRepository $items,
        private readonly JobRepository $jobs,
        private readonly FieldStore $store,
        private readonly FieldNameResolver $names,
        private readonly EntryLockGuard $lockGuard,
        private readonly CacheInvalidator $cache,
        private readonly \Closure $lockFactory,
        private readonly EntrySerializer $entries = new EntrySerializer(),
    ) {
    }

    /**
     * @throws \LogicException 完了済み（Completed）以外のジョブ
     * @throws JobBusyException 同じブログで別の処理が実行中
     */
    public function cleanupChunk(Job $job, float $timeBudget = 20.0): ChunkResult
    {
        if ($job->status !== JobStatus::Completed) {
            throw new StateException('クリーンアップは、変換が完了したジョブだけで実行できます: ' . $job->status->value);
        }
        // 同名モードは旧キーを変換時に置き換え済み（クリーンアップするものが無い）
        if ($job->options->fieldMode === 'same') {
            return new ChunkResult(JobStatus::Completed, 0, 0, 0, 0, true);
        }
        $lock = ($this->lockFactory)();
        if (!$lock->tryLock()) {
            throw new JobBusyException('同じブログで別の処理が実行中です。');
        }

        $started = microtime(true);
        $counts = ['converted' => 0, 'skipped' => 0, 'error' => 0];
        $log = [];
        $processed = 0;
        $afterId = $this->cursor($job);
        try {
            foreach ($this->items->nextCleanable($job->id, $afterId, $job->options->chunkSize) as $item) {
                if ($processed > 0 && (microtime(true) - $started) > $timeBudget) {
                    break;
                }
                $afterId = $item->id;
                $entry = $this->cleanup($job, $item);
                $counts[$entry['status']]++;
                $log[] = ['itemId' => $item->id, ...$entry];
                $processed++;
            }
            $done = $this->items->nextCleanable($job->id, $afterId, 1) === [];
            // 実行位置はジョブのカーソルに持つ（変換後はスキャンのカーソルを使わない）。ロック中・保留のアイテムを永久に再試行しないため。
            $this->jobs->setCursor($job->id, $done ? null : 'cleanup:' . $afterId);
        } finally {
            $this->cache->flush();
            $lock->release();
        }

        return new ChunkResult(
            JobStatus::Completed,
            $processed,
            $counts['converted'],
            $counts['skipped'],
            $counts['error'],
            $done,
            $log,
            $done ? 0 : ($this->items->cleanupCounts($job->id)['cleanable']),
        );
    }

    /**
     * @return array{status: 'converted'|'skipped'|'error', reason: string, message: string}
     */
    private function cleanup(Job $job, Item $item): array
    {
        $loc = $item->locator;
        if (!$loc instanceof FieldLocator) {
            return $this->result('skipped', ReasonCode::CleanupBlocked);
        }
        $fd = $loc->key;
        $dest = $this->names->resolve($fd, $job->options);
        if ($dest === $fd) {
            return $this->result('skipped', ReasonCode::CleanupBlocked);
        }

        // 新しいフィールド（メディア）が無いのに旧キーを消すと、データを失う
        $media = $this->store->valueOf($this->store->family($loc, $dest), $dest . '@media');
        if ($media === null || trim($media) === '') {
            return $this->result('skipped', ReasonCode::CleanupBlocked);
        }
        if ($this->lockGuard->isLocked($loc)) {
            return $this->result('skipped', ReasonCode::EntryLocked);
        }

        try {
            $this->entries->run($this->lockGuard->entryIdOf($loc), function () use ($item, $loc, $fd): void {
                Database::beginTransaction();
                try {
                    $rows = $this->store->family($loc, $fd);
                    $this->store->deleteFamily($loc, $fd);
                    $this->items->markCleaned($item->id, array_map(static fn (array $r): array => Snapshot::normalizeRow($r), $rows));
                    Database::commit();
                } catch (\Throwable $e) {
                    Database::rollBack();
                    throw $e;
                }
            });
        } catch (EntryBusyException) {
            return $this->result('skipped', ReasonCode::EntryLocked);
        } catch (\Throwable $e) {
            return $this->result('error', ReasonCode::UnknownError, ErrorMessage::of($e, 'クリーンアップでエラーになりました（アイテム ' . $item->id . '）'));
        }
        $this->cache->collect($loc, $item->blogId);
        return $this->result('converted', null);
    }

    private function cursor(Job $job): int
    {
        $raw = $job->scanCursor;
        return $raw !== null && str_starts_with($raw, 'cleanup:') ? (int) substr($raw, 8) : 0;
    }

    /**
     * @param 'converted'|'skipped'|'error' $status
     * @return array{status: 'converted'|'skipped'|'error', reason: string, message: string}
     */
    private function result(string $status, ?ReasonCode $reason, ?string $message = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason === null ? '' : $reason->value,
            'message' => $message ?? ($reason === null ? '' : $reason->label()),
        ];
    }
}
