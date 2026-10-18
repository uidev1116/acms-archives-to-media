<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

use Acms\Plugins\ArchivesToMedia\Services\Support\StateException;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConverterInterface;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Media\ArchiveMediaResolver;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourceException;
use Acms\Plugins\ArchivesToMedia\Services\Repository\ItemRepository;
use Acms\Plugins\ArchivesToMedia\Services\Repository\JobRepository;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\ScannerInterface;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;
use Acms\Plugins\ArchivesToMedia\Services\Support\ErrorMessage;

/**
 * スキャナーを順に回し、候補ごとにスナップショットとドライランの見込みを取って a2m_item を作る。
 * 何も書き換えない（メディアも登録しない）。カーソルはジョブに保存するので、途中から再開できる。
 */
class ScanCoordinator
{
    /** 時間を確かめる間隔（候補の件数） */
    private const BATCH = 5;

    /**
     * @param list<ScannerInterface> $scanners
     * @param list<ConverterInterface> $converters
     */
    public function __construct(
        private readonly array $scanners,
        private readonly array $converters,
        private readonly ArchiveMediaResolver $resolver,
        private readonly ItemRepository $items,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * 1 チャンク（オプションの chunkSize 件、または時間切れまで）スキャンする。
     *
     * @return array{status: string, scanned: int, total: int, done: bool}
     */
    public function scanChunk(Job $job, float $timeBudget = 15.0): array
    {
        if (!$job->status->canScan()) {
            throw new StateException('このジョブはスキャンできない状態です: ' . $job->status->value);
        }
        // 読み込んだあとに実行などが始まっていないか、状態を条件にして確かめながらスキャン中にする
        if (!$this->jobs->transition($job->id, [JobStatus::Draft, JobStatus::Scanning, JobStatus::Scanned], JobStatus::Scanning)) {
            throw new StateException('ジョブの状態が変わったため、スキャンを始められませんでした。画面を読み込み直してください。');
        }

        $options = $job->options;
        $active = array_values(array_filter(
            $this->scanners,
            static fn (ScannerInterface $s): bool => $options->hasTarget($s->target())
        ));
        [$index, $cursor] = $this->parseCursor($job->scanCursor);

        $started = microtime(true);
        $scanned = 0;
        $limit = $options->chunkSize;

        // 候補の登録（ドライランのファイル全体のハッシュ計算）は重いので、小さな単位で読んで時間を確かめる。
        // 読んだ候補は必ず登録してからカーソルを進める（途中で打ち切ると取りこぼす）ので、単位を小さく保つ。
        // 予算が尽きていても最低 1 単位は進める（進まないと同じ位置を繰り返す）。
        // スキャナは、候補が 0 件でも続きがあればカーソルを返す（歩いている間も時間を確かめられるように）ので、
        // 進んだ回数（$calls）で「最低 1 回は進める」を決める。
        $calls = 0;
        while ($index < count($active) && $scanned < $limit && ($calls === 0 || (microtime(true) - $started) < $timeBudget)) {
            $res = $active[$index]->scan($options, $job->blogId, $cursor, min($limit - $scanned, self::BATCH));
            $calls++;
            foreach ($res['candidates'] as $candidate) {
                // 1 つの所属が多数の候補（繰り返しのフィールドなど）を持つと、1 回の呼び出しで大量に返ることがある。
                // 返された候補は取りこぼさず登録するが、予算を超えたら、重い見込みの判定（ファイル全体のハッシュ計算）は
                // 後回しにする（実行時に判定する）。
                $this->register($job, $candidate, (microtime(true) - $started) < $timeBudget);
                $scanned++;
            }
            if ($res['nextCursor'] === null) {
                $index++;
                $cursor = null;
            } else {
                $cursor = $res['nextCursor'];
            }
        }

        $done = $index >= count($active);
        $this->jobs->setCursor($job->id, $done ? null : (string) json_encode(['s' => $index, 'c' => $cursor]));
        $this->jobs->refreshCounts($job->id);
        if ($done) {
            // スキャン中のままのときだけ。途中で別の操作（オプションの更新など）が状態を変えていたら、上書きしない
            $this->jobs->transition($job->id, [JobStatus::Scanning], JobStatus::Scanned);
        }
        $fresh = $this->jobs->find($job->id);
        $total = $fresh === null ? 0 : $fresh->total;

        return [
            'status' => ($done ? JobStatus::Scanned : JobStatus::Scanning)->value,
            'scanned' => $scanned,
            'total' => $total,
            'done' => $done,
        ];
    }

    /**
     * 設定を変えて再スキャンするために、アイテムとカーソルを消して下書きに戻す。
     */
    public function reset(Job $job): void
    {
        if (!$job->status->canScan()) {
            throw new StateException('このジョブは再スキャンできない状態です: ' . $job->status->value);
        }
        // 変換済みのアイテムは、ロールバックに必要な before / after を持っているので、消してはいけない
        $counts = $this->items->countByStatus($job->id);
        if (($counts[ItemStatus::Converted->value] ?? 0) + ($counts[ItemStatus::RolledBack->value] ?? 0) > 0) {
            throw new StateException('変換済みのアイテムがあるジョブは再スキャンできません。先にロールバックするか、新しいジョブを作ってください。');
        }
        // 読み込んだあとに実行などが始まっていないか、状態を条件にして確かめてから消す
        if (!$this->jobs->transition($job->id, [JobStatus::Draft, JobStatus::Scanning, JobStatus::Scanned], JobStatus::Draft)) {
            throw new StateException('ジョブの状態が変わったため、再スキャンできませんでした。画面を読み込み直してください。');
        }
        // 先にカーソルを捨てる。アイテムを消し切る前に止まっても、古いカーソルから再開して、消えた分の候補を取りこぼさない
        $this->jobs->setCursor($job->id, null);
        if (!$this->items->deleteByJob($job->id)) {
            // 続きは、もう一度再スキャンすれば消える（状態は Draft にしてあるので、そのまま呼べる）
            $this->jobs->refreshCounts($job->id);
            throw new StateException('アイテムの削除に時間がかかっています。もう一度実行してください。');
        }
        $this->jobs->refreshCounts($job->id);
    }

    private function register(Job $job, Candidate $candidate, bool $withinBudget): void
    {
        try {
            $this->registerCandidate($job, $candidate, $withinBudget);
        } catch (\Throwable $e) {
            // 1 件の予期しない失敗で、スキャン全体を止めない（同じ行を永久に再試行しないよう、エラーとして記録する）
            // 記録できない値（巨大な元ファイルのパスなど）が原因のこともあるので、パスを外した候補で記録する
            $bare = new Candidate($candidate->target, $candidate->locator, $candidate->ownerType, $candidate->ownerId, $candidate->blogId, []);
            try {
                $this->items->insert($job->id, $bare, 'skip', [], ItemStatus::Error, ReasonCode::UnknownError, ErrorMessage::of($e, 'スキャンでエラーになりました'));
            } catch (\Throwable $inner) {
                // エラーのアイテムすら記録できない候補は飛ばす（同じ位置で止まり続けないように）。詳細は監査ログに残す
                (new AuditLog())->notice('スキャンの候補を記録できませんでした', ['error' => $inner::class . ': ' . $inner->getMessage(), 'locator' => $candidate->locator->toArray()]);
            }
        }
    }

    private function registerCandidate(Job $job, Candidate $candidate, bool $withinBudget): void
    {
        $converter = $this->converterFor($candidate);
        $before = $converter?->snapshot($candidate->locator, $job->options);
        if ($converter === null || $before === null) {
            return; // スキャン直後に行が消えた
        }

        if (($candidate->context['conflict'] ?? false) === true) {
            $this->items->insert($job->id, $candidate, 'skip', $before, ItemStatus::Skipped, ReasonCode::DestConflict, ReasonCode::DestConflict->label());
            return;
        }

        $previews = [];
        $failures = [];
        $isImage = $candidate->target->value === 'unit_image' || $candidate->target->value === 'field_image';
        foreach ($candidate->sourcePaths as $path) {
            if ($path === '') {
                continue;
            }
            try {
                $previews[] = $this->resolver->preview($path, $candidate->blogId, $isImage, $withinBudget);
            } catch (SourceException $e) {
                $failures[] = $e;
            }
        }

        if ($failures !== []) {
            $reason = $previews !== [] ? ReasonCode::PartialSource : $failures[0]->reason;
            $this->items->insert($job->id, $candidate, 'skip', $before, ItemStatus::Skipped, $reason, $failures[0]->getMessage());
            return;
        }

        $unique = array_unique($previews);
        // 判定を後回しにしたパスがあれば、全体も未判定（reuse と unknown を「混在」と見せない）
        $preview = in_array('unknown', $unique, true) ? 'unknown' : (count($unique) === 1 ? Cast::str($unique[0]) : 'mixed');
        $this->items->insert($job->id, $candidate, $preview, $before);
    }

    private function converterFor(Candidate $candidate): ?ConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($candidate->target)) {
                return $converter;
            }
        }
        return null;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function parseCursor(?string $raw): array
    {
        $data = $raw === null ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['s'])) {
            return [0, null];
        }
        return [Cast::int($data['s']), isset($data['c']) ? Cast::str($data['c']) : null];
    }
}
