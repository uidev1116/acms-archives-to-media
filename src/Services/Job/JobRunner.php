<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConversionSkipException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Item;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Media\ArchiveMediaResolver;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourceException;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard;
use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;
use Acms\Services\Common\Lock;

/**
 * pending のアイテムをチャンク単位で変換する（設計書 §5.2）。
 *
 * 1 アイテムの流れ:
 *   1. スナップショットの照合と編集ロックの確認（書き込み前）
 *   2. メディアの解決（トランザクションの外。登録済みのメディアは対応表に残り、失敗しても次回再利用される）
 *   3. トランザクション内で再照合 → 変換 → 記録
 */
class JobRunner
{
    /**
     * @param list<ConverterInterface> $converters
     * @param \Closure(): Lock $lockFactory 現在のブログ用のロックを作る
     */
    public function __construct(
        private readonly array $converters,
        private readonly ArchiveMediaResolver $resolver,
        private readonly ItemRepository $items,
        private readonly JobRepository $jobs,
        private readonly EntryLockGuard $lockGuard,
        private readonly CacheInvalidator $cache,
        private readonly Db $db,
        private readonly \Closure $lockFactory,
    ) {
    }

    /**
     * @throws \LogicException 実行できない状態
     * @throws JobBusyException 同じブログで別の処理が実行中
     */
    public function runChunk(Job $job, float $timeBudget = 20.0): ChunkResult
    {
        if (!$job->status->canRun()) {
            throw new \LogicException('このジョブは実行できない状態です: ' . $job->status->value);
        }
        $lock = ($this->lockFactory)();
        if (!$lock->tryLock()) {
            throw new JobBusyException('同じブログで別の処理が実行中です。');
        }

        $started = microtime(true);
        $counts = ['converted' => 0, 'skipped' => 0, 'error' => 0];
        $log = [];
        $processed = 0;

        try {
            $this->jobs->setStatus($job->id, JobStatus::Running);
            foreach ($this->items->nextPending($job->id, $job->options->chunkSize) as $item) {
                if ($processed > 0 && (microtime(true) - $started) > $timeBudget) {
                    break;
                }
                $entry = $this->process($job, $item);
                $counts[$entry['status']]++;
                $log[] = ['itemId' => $item->id, ...$entry];
                $processed++;
            }

            $remaining = count($this->items->nextPending($job->id, 1));
            $done = $remaining === 0;
            $this->jobs->refreshCounts($job->id);
            if ($done) {
                $this->jobs->setStatus($job->id, JobStatus::Completed);
            }
            $remainingCount = $done ? 0 : $this->countPending($job->id);
        } catch (\Throwable $e) {
            $this->jobs->setStatus($job->id, JobStatus::Failed, $e->getMessage());
            throw $e;
        } finally {
            $this->cache->flush();
            $lock->release();
        }

        return new ChunkResult(
            $done ? JobStatus::Completed : JobStatus::Running,
            $processed,
            $counts['converted'],
            $counts['skipped'],
            $counts['error'],
            $done,
            $log,
            $remainingCount,
        );
    }

    /**
     * 実行中のジョブを一時停止にする（タブを閉じたときと同じ状態）
     */
    public function pause(Job $job): void
    {
        $this->jobs->transition($job->id, [JobStatus::Running, JobStatus::Scanned], JobStatus::Paused);
    }

    /**
     * スキップ・エラー分を、スナップショットを取り直して pending に戻す。
     *
     * @return int 戻した件数
     */
    public function requeue(Job $job): int
    {
        if (!in_array($job->status, [JobStatus::Completed, JobStatus::Paused, JobStatus::Running, JobStatus::Failed], true)) {
            throw new \LogicException('このジョブはスキップ分を再実行できない状態です: ' . $job->status->value);
        }
        $count = 0;
        $afterId = 0;
        while (true) {
            $batch = $this->items->byStatuses($job->id, [ItemStatus::Skipped, ItemStatus::Error], $afterId, 100);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $item) {
                $afterId = $item->id;
                $converter = $this->converterFor($item->target);
                $before = $converter?->snapshot($item->locator, $job->options);
                $paths = $converter?->currentSourcePaths($item->locator);
                if ($before === null || $paths === null || count(array_filter($paths, static fn (string $p): bool => $p !== '')) === 0) {
                    continue; // 行が無くなった・パスが空になったものは戻さない
                }
                $this->items->requeue($item->id, $before, $paths);
                $count++;
            }
        }
        if ($count > 0) {
            $this->jobs->refreshCounts($job->id);
            $this->jobs->setStatus($job->id, JobStatus::Paused);
        }
        return $count;
    }

    /**
     * @return array{status: 'converted'|'skipped'|'error', reason: string, message: string}
     */
    private function process(Job $job, Item $item): array
    {
        $converter = $this->converterFor($item->target);
        if ($converter === null || $item->before === null) {
            return $this->fail($item, ItemStatus::Error, ReasonCode::UnknownError, '変換器またはスナップショットがありません。');
        }
        $candidate = new Candidate($item->target, $item->locator, $item->ownerType, $item->ownerId, $item->blogId, $item->sourcePaths);

        try {
            // 1. 書き込み前の確認
            $current = $converter->snapshot($item->locator, $job->options);
            if ($current === null || !Snapshot::equals($current, $item->before)) {
                return $this->fail($item, ItemStatus::Skipped, ReasonCode::StaleSnapshot);
            }
            if ($this->lockGuard->isLocked($item->locator)) {
                return $this->fail($item, ItemStatus::Skipped, ReasonCode::EntryLocked);
            }
            if ($converter->currentSourcePaths($item->locator) !== $item->sourcePaths) {
                return $this->fail($item, ItemStatus::Skipped, ReasonCode::StaleSnapshot);
            }

            // 2. メディアの解決（トランザクションの外）
            [$mediaIds, $newFlags, $metas] = $this->resolveMedia($job, $item, $converter, $candidate);

            // 3. 変換（トランザクション内で再照合）
            $this->db->begin();
            try {
                // 再照合から書き込みまでの間に他のリクエストが同じ行を書き換えないよう、先に行ロックを取る
                $converter->lock($item->locator, $job->options);
                $again = $converter->snapshot($item->locator, $job->options);
                if ($again === null || !Snapshot::equals($again, $item->before)) {
                    $this->db->rollBack();
                    return $this->fail($item, ItemStatus::Skipped, ReasonCode::StaleSnapshot);
                }
                // メディア解決に時間がかかった間にロックされた場合に備え、書き込み直前にも確認する
                if ($this->lockGuard->isLocked($item->locator)) {
                    $this->db->rollBack();
                    return $this->fail($item, ItemStatus::Skipped, ReasonCode::EntryLocked);
                }
                $after = $converter->apply($candidate, $mediaIds, $job->options);
                $warning = $this->altMismatch($item, $mediaIds, $newFlags, $metas);
                $this->items->markConverted($item->id, $mediaIds, $after, $warning, $warning?->label());
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $this->cache->collect($item->locator, $item->blogId);

            return ['status' => 'converted', 'reason' => $warning === null ? '' : $warning->value, 'message' => $warning === null ? '' : $warning->label()];
        } catch (SourceException $e) {
            return $this->fail($item, ItemStatus::Skipped, $e->reason, $e->getMessage());
        } catch (ConversionSkipException $e) {
            return $this->fail($item, ItemStatus::Skipped, $e->reason, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($item, ItemStatus::Error, ReasonCode::UnknownError, $e->getMessage());
        }
    }

    /**
     * @return array{0: list<int>, 1: list<bool>, 2: list<\Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta>}
     * @throws SourceException
     */
    private function resolveMedia(Job $job, Item $item, ConverterInterface $converter, Candidate $candidate): array
    {
        $metas = $converter->metas($candidate, $job->options);
        $mediaIds = [];
        $newFlags = [];
        $failure = null;
        $succeeded = 0;
        foreach ($item->sourcePaths as $i => $path) {
            if ($path === '') {
                $mediaIds[] = 0;
                $newFlags[] = false;
                continue;
            }
            try {
                $resolved = $this->resolver->resolve($path, $item->blogId, $job->id, $metas[$i] ?? new \Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta());
                $mediaIds[] = $resolved->mediaId;
                $newFlags[] = $resolved->isNew;
                $succeeded++;
            } catch (SourceException $e) {
                $failure ??= $e;
            }
        }
        if ($failure !== null) {
            if ($succeeded > 0) {
                throw new SourceException(ReasonCode::PartialSource, $failure->getMessage(), $failure);
            }
            throw $failure;
        }
        return [$mediaIds, $newFlags, $metas];
    }

    /**
     * 再利用した既存メディアの alt が、元のフィールドの alt と違えば警告（値は書き換えない）
     *
     * @param list<int> $mediaIds
     * @param list<bool> $newFlags
     * @param list<\Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta> $metas
     */
    private function altMismatch(Item $item, array $mediaIds, array $newFlags, array $metas): ?ReasonCode
    {
        if (!$item->target->isField()) {
            return null;
        }
        foreach ($mediaIds as $i => $mediaId) {
            $alt = $metas[$i]->alt ?? '';
            if ($mediaId <= 0 || ($newFlags[$i] ?? true) || $alt === '') {
                continue;
            }
            $current = Cast::str($this->db->one('SELECT media_field_3 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$mediaId]));
            if ($current !== $alt) {
                return ReasonCode::AltMismatch;
            }
        }
        return null;
    }

    /**
     * @return array{status: 'skipped'|'error', reason: string, message: string}
     */
    private function fail(Item $item, ItemStatus $status, ReasonCode $reason, ?string $message = null): array
    {
        $message ??= $reason->label();
        $this->items->markStatus($item->id, $status, $reason, $message);
        return [
            'status' => $status === ItemStatus::Error ? 'error' : 'skipped',
            'reason' => $reason->value,
            'message' => $message,
        ];
    }

    private function countPending(int $jobId): int
    {
        return $this->items->countByStatus($jobId)[ItemStatus::Pending->value] ?? 0;
    }

    private function converterFor(\Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType $target): ?ConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($target)) {
                return $converter;
            }
        }
        return null;
    }
}
