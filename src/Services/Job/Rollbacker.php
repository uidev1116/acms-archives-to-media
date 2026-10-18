<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\StateException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConflictException;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
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
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Support\CacheInvalidator;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Support\ErrorMessage;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
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
    /** 「作成メディアを削除」で 1 回に調べて削除するメディアの数 */
    private const MEDIA_BATCH = 200;

    /** 削除できなかったメディアの ID を、カーソルに持つ上限 */
    private const MAX_FAILED_IDS = 100;

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
        private readonly FieldStore $fields,
        private readonly EntrySerializer $entries = new EntrySerializer(),
        private readonly ?EntryLockGuard $guard = null,
    ) {
    }

    /**
     * 1 チャンク分をロールバックする。位置はジョブのカーソルに持つ。
     *
     *   items フェーズ: 変換済みのアイテムを新しい順に戻す。別の処理がそのエントリーを更新中で戻せなかったアイテムは、
     *                  変換済みのまま残し（エラーにしない）、カーソルで飛ばして先へ進む。
     *   media フェーズ: 「作成メディアを削除」のとき、このジョブが作ったメディアのうち参照が無いものを 200 件ずつ削除する。
     *
     * 全部戻せたら RolledBack。更新中で戻せなかったアイテムが残ったら RollingBack のまま（メッセージつき）で区切り、あとでもう一度ロールバックできるようにする。
     *
     * @throws StateException ロールバックできない状態
     * @throws JobBusyException 同じブログで別の処理が実行中
     */
    public function rollbackChunk(Job $job, bool $deleteCreatedMedia, float $timeBudget = 20.0): ChunkResult
    {
        if (!$job->status->canRollback()) {
            throw new StateException('このジョブはロールバックできない状態です: ' . $job->status->value);
        }
        $lock = ($this->lockFactory)();
        if (!$lock->tryLock()) {
            throw new JobBusyException('同じブログで別の処理が実行中です。');
        }

        $started = microtime(true);
        $restored = 0;
        $conflicts = 0;
        $busy = 0;
        $log = [];
        $processed = 0;
        $finalStatus = JobStatus::RollingBack;
        $leftoverStop = false;

        try {
            if (!$this->jobs->transition($job->id, [JobStatus::Completed, JobStatus::Paused, JobStatus::Running, JobStatus::RollingBack, JobStatus::Failed], JobStatus::RollingBack)) {
                throw new StateException('ジョブの状態が変わったため、ロールバックを始められませんでした。画面を読み込み直してください。');
            }
            $cursor = $this->cursor($job);
            // 前のロールバックが media フェーズで止まったあとに変換済みが増えていたら（再実行など）、先にそれを戻す。
            // media フェーズのままだと、変換済みが残っているという理由で何度やっても進まない。
            if ($cursor['phase'] === 'media' && ($this->items->countByStatus($job->id)[ItemStatus::Converted->value] ?? 0) > 0) {
                $cursor = ['phase' => 'items', 'before' => PHP_INT_MAX];
                $this->jobs->setCursor($job->id, null);
            }
            $done = false;

            if ($cursor['phase'] === 'items') {
                $beforeId = $cursor['before'];
                foreach ($this->items->nextConvertedBefore($job->id, $beforeId, $job->options->chunkSize) as $item) {
                    if ($processed > 0 && (microtime(true) - $started) > $timeBudget) {
                        break;
                    }
                    $beforeId = $item->id;
                    $entry = $this->restore($job, $item);
                    match ($entry['status']) {
                        'converted' => $restored++,
                        'skipped' => $busy++,
                        default => $conflicts++,
                    };
                    $log[] = ['itemId' => $item->id, ...$entry];
                    $processed++;
                }
                if ($this->items->nextConvertedBefore($job->id, $beforeId, 1) !== []) {
                    $this->jobs->setCursor($job->id, $this->encodeCursor(['phase' => 'items', 'before' => $beforeId]));
                } else {
                    $cursor = ['phase' => 'media', 'after' => 0, 'failed' => [], 'extra' => 0];
                    $this->jobs->setCursor($job->id, null);
                    $done = true;
                }
            } else {
                $done = true;
            }

            $failedMedia = [];
            $failedExtra = 0;
            if ($done) {
                $leftover = $this->items->countByStatus($job->id)[ItemStatus::Converted->value] ?? 0;
                if ($leftover > 0) {
                    // 別の処理が更新中で戻せなかったものが残っている。ロールバック済みにはせず、あとでやり直せるようにする。
                    $this->jobs->refreshCounts($job->id);
                    // 状態は RollingBack のまま（Paused だと、変換の一時停止と区別できず「実行を再開」が通ってしまう）
                    $this->jobs->setStatus($job->id, JobStatus::RollingBack, $leftover . ' 件は、エントリーが別の処理で更新中だったため戻せませんでした。しばらくしてから、もう一度ロールバックしてください。');
                    $finalStatus = JobStatus::RollingBack;
                    $leftoverStop = true;
                } elseif ($deleteCreatedMedia) {
                    $step = $this->deleteMediaBatch(
                        $started,
                        $timeBudget,
                        $job->id,
                        $cursor['phase'] === 'media' ? $cursor['after'] : 0,
                        $cursor['phase'] === 'media' ? $cursor['failed'] : [],
                        $cursor['phase'] === 'media' ? $cursor['extra'] : 0,
                    );
                    $failedMedia = $step['failed'];
                    $failedExtra = $step['extra'];
                    if ($step['more']) {
                        $done = false;
                        $this->jobs->setCursor($job->id, $this->encodeCursor(['phase' => 'media', 'after' => $step['after'], 'failed' => $failedMedia, 'extra' => $failedExtra]));
                    }
                }
                if ($done && !$leftoverStop) {
                    $this->jobs->setCursor($job->id, null);
                    $this->jobs->refreshCounts($job->id);
                    $this->jobs->setStatus(
                        $job->id,
                        JobStatus::RolledBack,
                        $failedMedia === [] ? null : 'メディアを削除できませんでした（media_id: ' . implode(', ', $failedMedia) . ($failedExtra > 0 ? '、ほか ' . $failedExtra . ' 件' : '') . '、合計 ' . (count($failedMedia) + $failedExtra) . ' 件）。メディア管理から手動で整理してください。'
                    );
                    $finalStatus = JobStatus::RolledBack;
                }
            }
            if (!$done || $leftoverStop) {
                $this->jobs->refreshCounts($job->id);
            }
        } catch (StateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Failed は「変換の実行が失敗した」だけの意味にする（Failed だと「実行を再開」が通り、ロールバックの途中で変換が再開される）。
            // ロールバック中のまま理由を残せば、もう一度ロールバックを実行できる。
            $this->jobs->setStatus($job->id, JobStatus::RollingBack, ErrorMessage::of($e, 'ロールバックのチャンクが失敗しました'));
            throw $e;
        } finally {
            $this->cache->flush();
            $lock->release();
        }

        return new ChunkResult(
            $done ? $finalStatus : JobStatus::RollingBack,
            $processed,
            $restored,
            $busy,
            $conflicts,
            $done,
            $log,
            $done ? 0 : ($this->items->countByStatus($job->id)[ItemStatus::Converted->value] ?? 0),
        );
    }

    /**
     * @return array{phase: 'items', before: int}|array{phase: 'media', after: int, failed: list<int>, extra: int}
     */
    private function cursor(Job $job): array
    {
        $data = $job->scanCursor === null ? null : json_decode($job->scanCursor, true);
        if (is_array($data) && ($data['p'] ?? null) === 'items') {
            return ['phase' => 'items', 'before' => Cast::int($data['before'] ?? PHP_INT_MAX)];
        }
        if (is_array($data) && ($data['p'] ?? null) === 'media') {
            $failed = is_array($data['failed'] ?? null) ? array_values(array_map(static fn (mixed $v): int => Cast::int($v), $data['failed'])) : [];
            return ['phase' => 'media', 'after' => Cast::int($data['after'] ?? 0), 'failed' => $failed, 'extra' => Cast::int($data['extra'] ?? 0)];
        }
        return ['phase' => 'items', 'before' => PHP_INT_MAX];
    }

    /**
     * @param array{phase: 'items', before: int}|array{phase: 'media', after: int, failed: list<int>, extra: int} $cursor
     */
    private function encodeCursor(array $cursor): string
    {
        return (string) json_encode($cursor['phase'] === 'items'
            ? ['p' => 'items', 'before' => $cursor['before']]
            : ['p' => 'media', 'after' => $cursor['after'], 'failed' => $cursor['failed'], 'extra' => $cursor['extra']]);
    }

    /**
     * @return array{status: 'converted'|'skipped'|'error', reason: string, message: string}
     */
    private function restore(Job $job, Item $item): array
    {
        $converter = $this->converterFor($item);
        if ($converter === null || $item->before === null || $item->after === null) {
            return $this->conflict($item, ReasonCode::UnknownError, '変換器またはスナップショットがありません。');
        }
        try {
            $this->entries->run($this->guard?->entryIdOf($item->locator), function () use ($job, $item, $converter): void {
                $this->restoreInTransaction($job, $item, $converter);
            });
        } catch (EntryBusyException) {
            // 一時的な失敗。変換済みのまま残し（エラーにしない）、あとでもう一度ロールバックできるようにする
            return ['status' => 'skipped', 'reason' => ReasonCode::EntryLocked->value, 'message' => ReasonCode::EntryLocked->label()];
        } catch (ConflictException $e) {
            return $this->conflict($item, ReasonCode::RollbackConflict, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->conflict($item, ReasonCode::UnknownError, ErrorMessage::of($e, 'ロールバックでエラーになりました（アイテム ' . $item->id . '）'));
        }
        $this->cache->collect($item->locator, $item->blogId);
        return ['status' => 'converted', 'reason' => '', 'message' => ''];
    }

    /**
     * 行の復元とステータス更新を、1 つのトランザクションで行う。
     *
     * @throws ConflictException 変換後に編集されていて戻せない
     */
    private function restoreInTransaction(Job $job, Item $item, ConverterInterface $converter): void
    {
        \Acms\Services\Facades\Database::beginTransaction();
        try {
            $this->undoCleanup($item);
            try {
                $converter->restore($item->locator, $item->before ?? [], $item->after ?? [], $job->options);
            } catch (ConflictException $e) {
                // コミット後・ステータス更新前に中断した場合は、既に元に戻っている（現在行 = before）。冪等に扱う。
                $current = $converter->snapshot($item->locator, $job->options);
                if ($current === null || !\Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot::equals($current, $item->before ?? [])) {
                    throw $e;
                }
            }
            $this->items->markStatus($item->id, ItemStatus::RolledBack);
            if ($item->cleanup !== null) {
                $this->items->clearCleanup($item->id);
            }
            \Acms\Services\Facades\Database::commit();
        } catch (\Throwable $e) {
            \Acms\Services\Facades\Database::rollBack();
            throw $e;
        }
    }

    /**
     * クリーンアップで消した旧キーの行を戻す（変換前の全行に戻すため）。既に戻っている場合は何もしない。
     */
    private function undoCleanup(Item $item): void
    {
        $loc = $item->locator;
        if ($item->cleanup === null || $item->cleanup === [] || !$loc instanceof FieldLocator) {
            return;
        }
        if ($this->fields->family($loc, $loc->key) !== []) {
            return;
        }
        foreach ($item->cleanup as $row) {
            $this->fields->insertRow($loc->table(), $row);
        }
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
     * このジョブが作ったメディアのうち参照が無いものを、$afterId の続きから 200 件分削除する。
     * 削除できなかったメディアがあっても残りを続ける（ロールバック自体はもう終わっている）。
     *
     * @param list<int> $failed ここまでに削除できなかった media_id（上限まで）
     * @param int $failedExtra 上限を超えて、ID を持たずに数えただけの件数
     * @return array{more: bool, after: int, failed: list<int>, extra: int}
     */
    private function deleteMediaBatch(float $started, float $timeBudget, int $jobId, int $afterId, array $failed, int $failedExtra): array
    {
        $created = $this->pathMap->mediaIdsCreatedByJob($jobId, $afterId, self::MEDIA_BATCH);
        $referenced = array_fill_keys($this->references->referencedAmong($created), true);
        $last = $afterId;
        $stoppedEarly = false;
        foreach ($created as $mediaId) {
            // ファイルの削除は共用サーバーでは遅いことがある。予算が尽きたら途中で区切り、続きは次の呼び出しで
            if ($last !== $afterId && (microtime(true) - $started) > $timeBudget) {
                $stoppedEarly = true;
                break;
            }
            $last = $mediaId;
            if (isset($referenced[$mediaId])) {
                continue;
            }
            try {
                $this->media->deleteItem($mediaId);
            } catch (\Throwable) {
                // カーソル（text 64KB）に収まるよう、ID を持つのは上限まで。超えた分は件数だけ数える
                if (count($failed) < self::MAX_FAILED_IDS) {
                    $failed[] = $mediaId;
                } else {
                    $failedExtra++;
                }
                continue;
            }
            $this->pathMap->deleteByMedia($mediaId);
        }
        return [
            'more' => $stoppedEarly || count($created) === self::MEDIA_BATCH,
            'after' => $last,
            'failed' => $failed,
            'extra' => $failedExtra,
        ];
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
