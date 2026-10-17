<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Job;

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

/**
 * スキャナーを順に回し、候補ごとにスナップショットとドライランの見込みを取って a2m_item を作る。
 * 何も書き換えない（メディアも登録しない）。カーソルはジョブに保存するので、途中から再開できる。
 */
class ScanCoordinator
{
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
            throw new \LogicException('このジョブはスキャンできない状態です: ' . $job->status->value);
        }
        if ($job->status !== JobStatus::Scanning) {
            $this->jobs->setStatus($job->id, JobStatus::Scanning);
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

        while ($index < count($active) && $scanned < $limit && (microtime(true) - $started) < $timeBudget) {
            $res = $active[$index]->scan($options, $job->blogId, $cursor, $limit - $scanned);
            foreach ($res['candidates'] as $candidate) {
                $this->register($job, $candidate);
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
            $this->jobs->setStatus($job->id, JobStatus::Scanned);
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
            throw new \LogicException('このジョブは再スキャンできない状態です: ' . $job->status->value);
        }
        $this->items->deleteByJob($job->id);
        $this->jobs->setCursor($job->id, null);
        $this->jobs->setStatus($job->id, JobStatus::Draft);
        $this->jobs->refreshCounts($job->id);
    }

    private function register(Job $job, Candidate $candidate): void
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
                $previews[] = $this->resolver->preview($path, $candidate->blogId, $isImage);
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
        $preview = count($unique) === 1 ? Cast::str($unique[0]) : 'mixed';
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
