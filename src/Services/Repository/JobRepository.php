<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Repository;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Job;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

class JobRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(int $blogId, int $userId, JobOptions $options): int
    {
        $this->db->exec(
            'INSERT INTO ' . $this->db->t('a2m_job') . '
             (job_status, job_options, job_user_id, job_blog_id, job_created_datetime)
             VALUES (?, ?, ?, ?, ?)',
            [JobStatus::Draft->value, $options->toJson(), $userId, $blogId, $this->now()]
        );
        return $this->db->lastInsertId();
    }

    public function find(int $id): ?Job
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->t('a2m_job') . ' WHERE job_id = ?', [$id]);
        return $row === null ? null : Job::fromRow($row);
    }

    /**
     * @return array{jobs: list<Job>, total: int}
     */
    public function paginate(int $blogId, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->db->t('a2m_job') . ' WHERE job_blog_id = ? ORDER BY job_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$blogId]
        );
        $total = Cast::int($this->db->one('SELECT COUNT(*) FROM ' . $this->db->t('a2m_job') . ' WHERE job_blog_id = ?', [$blogId]));
        return ['jobs' => array_map(static fn (array $r): Job => Job::fromRow($r), $rows), 'total' => $total];
    }

    /**
     * 状態を無条件で更新する
     */
    public function setStatus(int $id, JobStatus $status, ?string $message = null): void
    {
        $sets = ['job_status = ?', 'job_message = ?'];
        $params = [$status->value, $message];
        if ($status === JobStatus::Running) {
            $sets[] = 'job_started_datetime = COALESCE(job_started_datetime, ?)';
            $params[] = $this->now();
        }
        if (in_array($status, [JobStatus::Completed, JobStatus::RolledBack, JobStatus::Failed], true)) {
            $sets[] = 'job_finished_datetime = ?';
            $params[] = $this->now();
        }
        $params[] = $id;
        $this->db->exec('UPDATE ' . $this->db->t('a2m_job') . ' SET ' . implode(', ', $sets) . ' WHERE job_id = ?', $params);
    }

    /**
     * 現在の状態が $from のいずれかのときだけ $to にする（多重実行・競合の防止）。
     *
     * @param list<JobStatus> $from
     */
    public function transition(int $id, array $from, JobStatus $to): bool
    {
        $values = array_map(static fn (JobStatus $s): string => $s->value, $from);
        // UPDATE の影響行数は「実際に変わった行数」なので、同じ状態への遷移（Running → Running など）では 0 になる。
        // 行ロックを取って現在の状態を確かめてから更新する。
        return $this->db->transaction(function () use ($id, $values, $to): bool {
            $current = $this->db->row('SELECT job_status FROM ' . $this->db->t('a2m_job') . ' WHERE job_id = ?', [$id], ' FOR UPDATE');
            if ($current === null || !in_array(Cast::str($current['job_status']), $values, true)) {
                return false;
            }
            $started = $to === JobStatus::Running ? ', job_started_datetime = COALESCE(job_started_datetime, ?)' : '';
            $this->db->exec(
                'UPDATE ' . $this->db->t('a2m_job') . ' SET job_status = ?, job_message = NULL' . $started . ' WHERE job_id = ?',
                [$to->value, ...($started === '' ? [] : [$this->now()]), $id]
            );
            return true;
        });
    }

    public function setOptions(int $id, JobOptions $options): void
    {
        $this->db->exec('UPDATE ' . $this->db->t('a2m_job') . ' SET job_options = ? WHERE job_id = ?', [$options->toJson(), $id]);
    }

    public function setCursor(int $id, ?string $cursor): void
    {
        $this->db->exec('UPDATE ' . $this->db->t('a2m_job') . ' SET job_scan_cursor = ? WHERE job_id = ?', [$cursor, $id]);
    }

    /**
     * 集計値（表示用キャッシュ）をアイテムから再計算する
     */
    public function refreshCounts(int $id): void
    {
        $rows = $this->db->all(
            'SELECT item_status, COUNT(*) AS c FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ? GROUP BY item_status',
            [$id]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[Cast::str($row['item_status'])] = Cast::int($row['c']);
        }
        $this->db->exec(
            'UPDATE ' . $this->db->t('a2m_job') . ' SET job_total = ?, job_converted = ?, job_skipped = ?, job_error = ? WHERE job_id = ?',
            [array_sum($counts), $counts['converted'] ?? 0, $counts['skipped'] ?? 0, $counts['error'] ?? 0, $id]
        );
    }

    /**
     * ジョブの行とアイテムを消す。アイテムが多いときは、先に ItemRepository::deleteByJob() で
     * 区切って消しておく（ここでは残りを消すだけ）。
     */
    public function delete(int $id): void
    {
        $this->db->exec('DELETE FROM ' . $this->db->t('a2m_item') . ' WHERE item_job_id = ?', [$id]);
        $this->db->exec('DELETE FROM ' . $this->db->t('a2m_job') . ' WHERE job_id = ?', [$id]);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
