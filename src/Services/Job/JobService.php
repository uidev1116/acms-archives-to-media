<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\StateException;
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
use Acms\Plugins\ArchivesToMedia\Services\Support\InvalidInputException;

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
        /** @var (\Closure(): list<string>)|null サイトで使えるメディアユニットの種類（config の column_add_type）。null なら確かめない */
        private readonly ?\Closure $mediaUnitTypes = null,
    ) {
    }

    /**
     * @param array<mixed> $options
     * @throws \InvalidArgumentException
     */
    public function create(int $blogId, int $userId, array $options): Job
    {
        $parsed = JobOptions::fromArray($options, true);
        $this->assertMediaUnitTypes($parsed);
        $this->assertCanAdminister($blogId, $parsed);
        $id = $this->jobs->create($blogId, $userId, $parsed);
        $this->log->info('ジョブを作成しました', ['jobId' => $id, 'blogId' => $blogId, 'userId' => $userId, 'options' => $parsed->toArray()]);
        return $this->jobFor($id, $blogId);
    }

    /**
     * @return array{job: array<string, mixed>, summary: list<array<string, mixed>>, counts: array<string, int>, finalizing: bool, cleanup: array{cleanable: int, cleaned: int}}
     */
    public function get(int $jobId, int $blogId): array
    {
        $job = $this->jobFor($jobId, $blogId);
        return [
            'job' => $job->toArray(),
            'summary' => $this->items->summary($jobId),
            'counts' => $this->items->countByStatus($jobId),
            // 同名モードは変換時に旧キーを置き換え済みなので、クリーンアップの対象は無い
            // 確定済みで、スナップショットの回収が途中のとき（続きを実行できる）
            'finalizing' => $job->status === JobStatus::Finalized && str_starts_with((string) $job->scanCursor, 'finalize:'),
            'cleanup' => $job->options->fieldMode === 'same' || $job->status === JobStatus::Finalized ? ['cleanable' => 0, 'cleaned' => 0] : $this->items->cleanupCounts($jobId),
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
        $parsed = JobOptions::fromArray($options, true);
        $this->assertMediaUnitTypes($parsed);
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
        // アイテムの状態を変える操作なので、書き換え系と同じく、子ブログを含むジョブは全子孫ブログの権限を確かめる
        // （権限の無い子ブログの行に効いたり、絞り込みの件数から元ファイルのパスの有無を探られたりしないように）
        $this->jobToOperate($jobId, $blogId);
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

    /**
     * 時間の予算の範囲でスキップ・エラー分を戻す。done が false の間は、続けて呼ぶ。
     *
     * @return array{requeued: int, done: bool}
     */
    public function requeueChunk(int $jobId, int $blogId, bool $restart = false): array
    {
        return $this->runner->requeueChunk($this->jobToOperate($jobId, $blogId), 15.0, $restart);
    }

    public function rollback(int $jobId, int $blogId, bool $deleteCreatedMedia): ChunkResult
    {
        $job = $this->jobToOperate($jobId, $blogId);
        if ($job->status !== JobStatus::RollingBack) {
            $this->log->info('ロールバックを開始しました', ['jobId' => $jobId, 'deleteCreatedMedia' => $deleteCreatedMedia]);
        }
        $result = $this->rollbacker->rollbackChunk($job, $deleteCreatedMedia);
        if ($result->status === JobStatus::RolledBack) {
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
     * 変換を確定する。ロールバック用のスナップショットを捨てて DB の容量を回収する（元には戻せなくなる）。
     * 結果の件数と CSV は残る。確定したジョブは削除できる。
     *
     * 件数が多いと 1 回では終わらないので、時間の予算で区切る。done が false の間は、続けて呼ぶ
     * （確定済み = Finalized のジョブでも、残りの回収を続けられる）。
     *
     * 別名モードで旧データのクリーンアップが済んでいないジョブは、確定するとクリーンアップできなくなるので、
     * $keepLegacyKeys（旧データを残したまま確定する）の確認が無ければ拒否する。
     *
     * @return array{done: bool}
     * @throws StateException 完了済み（Completed）でも確定済み（Finalized）でもないジョブ、旧データの確認が無い
     */
    public function finalize(int $jobId, int $blogId, bool $keepLegacyKeys = false, int $limit = 2000, float $timeBudget = 15.0): array
    {
        $job = $this->jobToOperate($jobId, $blogId);
        if ($job->status === JobStatus::Completed) {
            $cleanable = $this->items->cleanupCounts($jobId)['cleanable'];
            if ($job->options->fieldMode !== 'same' && $cleanable > 0 && !$keepLegacyKeys) {
                throw new StateException('旧データのクリーンアップが済んでいないフィールドが ' . $cleanable . ' 件あります。確定すると、あとからクリーンアップできなくなります。先にクリーンアップするか、旧データを残したまま確定することを確認してください。');
            }
            // 回収が済むまでの目印（画面の「記録の回収を続ける」を出す条件）。済んだら消す。
            // 遷移の前に置く（遷移の直後に落ちても、続きを実行できるように）
            $this->jobs->setCursor($jobId, 'finalize:0');
            if (!$this->jobs->transition($job->id, [JobStatus::Completed], JobStatus::Finalized)) {
                $this->jobs->setCursor($jobId, null);
                throw new StateException('ジョブの状態が変わったため、確定できませんでした。画面を読み込み直してください。');
            }
            $this->log->info('変換を確定しました（ロールバックできなくなりました）', ['jobId' => $jobId]);
        } elseif ($job->status !== JobStatus::Finalized) {
            throw new StateException('確定できるのは、変換が完了したジョブだけです: ' . $job->status->value);
        }
        // 位置はジョブのカーソルに持つ（確定済みのジョブに呼び直したとき、続きから回収する）
        $cursor = (string) $this->jobs->find($jobId)?->scanCursor;
        $afterId = str_starts_with($cursor, 'finalize:') ? (int) substr($cursor, 9) : 0;
        $started = microtime(true);
        do {
            $step = $this->items->purgeSnapshots($jobId, $afterId, $limit);
            if ($step['count'] === 0) {
                $this->jobs->setCursor($jobId, null);
                return ['done' => true];
            }
            $afterId = $step['last'];
            $this->jobs->setCursor($jobId, 'finalize:' . $afterId);
        } while ((microtime(true) - $started) < $timeBudget);
        return ['done' => false];
    }

    /**
     * 変換済みのアイテムが残っているジョブや、処理中のジョブは削除できない。
     */
    public function delete(int $jobId, int $blogId): void
    {
        $job = $this->jobFor($jobId, $blogId);
        $active = [JobStatus::Scanning, JobStatus::Running, JobStatus::RollingBack];
        // 確定済みのジョブは、ロールバックできないので変換済みが残っていても削除できる
        $hasConverted = $job->status !== JobStatus::Finalized
            && ($this->items->countByStatus($jobId)[ItemStatus::Converted->value] ?? 0) > 0;
        if (in_array($job->status, $active, true) || $hasConverted) {
            throw new StateException('処理中、または変換済みのアイテムが残っているジョブは削除できません。先にロールバックしてください。');
        }
        // アイテムが多いと 1 回では消し切れない。途中で止まってもジョブは残るので、もう一度削除すれば続きから消える
        if (!$this->items->deleteByJob($jobId)) {
            throw new StateException('アイテムの削除に時間がかかっています。もう一度「ジョブを削除」を実行してください。');
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
        $job = $this->jobFor($jobId, $blogId);
        $res = $this->items->search($jobId, $filters, $sort, $desc, $page, $perPage, $job->total, $this->visibleBlogIds($job, $blogId));
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
        $visible = $this->visibleBlogIds($this->jobFor($jobId, $blogId), $blogId);
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['item_id', 'target', 'owner_type', 'owner_id', 'status', 'reason', 'source_path', 'media_ids', 'message'], ',', '"', '\\');
        $afterId = 0;
        do {
            $rows = $this->items->exportRows($jobId, $afterId, 200, $visible);
            foreach ($rows as $row) {
                $afterId = Cast::int($row['item_id']);
                $r = $this->presentRow($row);
                fputcsv($stream, array_map($this->csvCell(...), [
                    $r['id'], $r['target'], $r['ownerType'], $r['ownerId'], $r['status'], $r['reason'],
                    implode(' | ', $r['sourcePaths']), implode(',', $r['mediaIds']), Cast::str($r['message']),
                ]), ',', '"', '\\');
            }
        } while (count($rows) === 200);
    }

    /**
     * 変換先のメディアユニットが、サイトのエディター設定にある種類か。無い種類に書き換えると、
     * 変換後のユニットが編集画面で表示できなくなる。
     *
     * @throws InvalidInputException
     */
    private function assertMediaUnitTypes(JobOptions $options): void
    {
        if ($this->mediaUnitTypes === null) {
            return;
        }
        $allowed = [...($this->mediaUnitTypes)(), 'media'];
        foreach ($options->unitTypeMap as $to) {
            if (!in_array($to, $allowed, true)) {
                throw new InvalidInputException('変換先のユニット種類「' . $to . '」は、このサイトのエディター設定にありません。');
            }
        }
    }

    /**
     * 読み取りで見せてよいブログ。子ブログを含むジョブで、操作する人が管理権限を持たない子ブログがあれば、
     * そのブログの行（元ファイルのパス・所属 ID など）は見せない。全ブログを見せてよければ null。
     *
     * @return list<int>|null
     */
    private function visibleBlogIds(Job $job, int $blogId): ?array
    {
        if (!$job->options->includeChildBlogs) {
            return null;
        }
        $all = $this->scope->blogIds($blogId, true);
        $allowed = array_values(array_filter($all, fn (int $id): bool => ($this->canAdminister)($id)));
        return count($allowed) === count($all) ? null : $allowed;
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
