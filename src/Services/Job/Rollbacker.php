<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Converter\ConflictException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Item;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaReferenceChecker;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator;
use Acms\Services\Common\Lock;
use Acms\Services\Media\Helper;

/**
 * 変換済みのアイテムを新しい順に元へ戻す（設計書 §10.3）。
 *
 * 現在の行が item_after と違う（変換後に編集された）アイテムは戻さず、ROLLBACK_CONFLICT のエラーにする。
 * オプションで、そのジョブが新規作成したメディアのうち、どこからも参照されていないものを削除する。
 */
class Rollbacker
{
    /**
     * @param list<ConverterInterface> $converters
     * @param \Closure(): Lock $lockFactory
     */
    public function __construct(
        private readonly array $converters,
        private readonly ItemRepository $items,
        private readonly JobRepository $jobs,
        private readonly PathMapRepository $pathMap,
        private readonly MediaReferenceChecker $references,
        private readonly Helper $media,
        private readonly CacheInvalidator $cache,
        private readonly \Closure $lockFactory,
    ) {
    }

    /**
     * @throws \LogicException ロールバックできない状態
     * @throws JobBusyException 同じブログで別の処理が実行中
     */
    public function rollbackChunk(Job $job, bool $deleteCreatedMedia, float $timeBudget = 20.0): ChunkResult
    {
        if (!$job->status->canRollback()) {
            throw new \LogicException('このジョブはロールバックできない状態です: ' . $job->status->value);
        }
        $lock = ($this->lockFactory)();
        if (!$lock->tryLock()) {
            throw new JobBusyException('同じブログで別の処理が実行中です。');
        }

        $started = microtime(true);
        $restored = 0;
        $conflicts = 0;
        $log = [];
        $processed = 0;

        try {
            $this->jobs->setStatus($job->id, JobStatus::RollingBack);
            foreach ($this->items->nextConverted($job->id, $job->options->chunkSize) as $item) {
                if ($processed > 0 && (microtime(true) - $started) > $timeBudget) {
                    break;
                }
                $entry = $this->restore($job, $item);
                $entry['status'] === 'converted' ? $restored++ : $conflicts++;
                $log[] = ['itemId' => $item->id, ...$entry];
                $processed++;
            }

            $remaining = count($this->items->nextConverted($job->id, 1));
            $done = $remaining === 0;
            $deleteFailures = [];
            if ($done && $deleteCreatedMedia) {
                $deleteFailures = $this->deleteUnreferencedMedia($job->id);
            }
            $this->jobs->refreshCounts($job->id);
            if ($done) {
                $this->jobs->setStatus(
                    $job->id,
                    JobStatus::RolledBack,
                    $deleteFailures === [] ? null : 'メディアを削除できませんでした（media_id: ' . implode(', ', $deleteFailures) . '）。メディア管理から手動で整理してください。'
                );
            }
        } catch (\Throwable $e) {
            $this->jobs->setStatus($job->id, JobStatus::Failed, $e->getMessage());
            throw $e;
        } finally {
            $this->cache->flush();
            $lock->release();
        }

        return new ChunkResult(
            $done ? JobStatus::RolledBack : JobStatus::RollingBack,
            $processed,
            $restored,
            0,
            $conflicts,
            $done,
            $log,
            $done ? 0 : ($this->items->countByStatus($job->id)[ItemStatus::Converted->value] ?? 0),
        );
    }

    /**
     * @return array{status: 'converted'|'error', reason: string, message: string}
     */
    private function restore(Job $job, Item $item): array
    {
        $converter = $this->converterFor($item);
        if ($converter === null || $item->before === null || $item->after === null) {
            return $this->conflict($item, ReasonCode::UnknownError, '変換器またはスナップショットがありません。');
        }
        try {
            \Acms\Services\Facades\Database::beginTransaction();
            try {
                try {
                    $converter->restore($item->locator, $item->before, $item->after, $job->options);
                } catch (ConflictException $e) {
                    // コミット後・ステータス更新前に中断した場合は、既に元に戻っている（現在行 = before）。冪等に扱う。
                    $current = $converter->snapshot($item->locator, $job->options);
                    if ($current === null || !\Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot::equals($current, $item->before)) {
                        throw $e;
                    }
                }
                // 行の復元とステータス更新を同じトランザクションにする
                $this->items->markStatus($item->id, ItemStatus::RolledBack);
                \Acms\Services\Facades\Database::commit();
            } catch (\Throwable $e) {
                \Acms\Services\Facades\Database::rollBack();
                throw $e;
            }
        } catch (ConflictException $e) {
            return $this->conflict($item, ReasonCode::RollbackConflict, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->conflict($item, ReasonCode::UnknownError, $e->getMessage());
        }
        $this->cache->collect($item->locator, $item->blogId);
        return ['status' => 'converted', 'reason' => '', 'message' => ''];
    }

    /**
     * @return array{status: 'error', reason: string, message: string}
     */
    private function conflict(Item $item, ReasonCode $reason, string $message): array
    {
        $this->items->markStatus($item->id, ItemStatus::Error, $reason, $message);
        return ['status' => 'error', 'reason' => $reason->value, 'message' => $message];
    }

    /**
     * 削除できなかったメディアがあっても残りを続ける（ロールバック自体はもう終わっている）。
     *
     * @return list<int> 削除に失敗した media_id
     */
    private function deleteUnreferencedMedia(int $jobId): array
    {
        $failed = [];
        foreach ($this->pathMap->mediaIdsCreatedByJob($jobId) as $mediaId) {
            if ($this->references->isReferenced($mediaId)) {
                continue;
            }
            try {
                $this->media->deleteItem($mediaId);
            } catch (\Throwable) {
                $failed[] = $mediaId;
                continue;
            }
            $this->pathMap->deleteByMedia($mediaId);
        }
        return $failed;
    }

    private function converterFor(Item $item): ?ConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($item->target)) {
                return $converter;
            }
        }
        return null;
    }
}
