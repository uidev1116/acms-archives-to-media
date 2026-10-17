import Alert from '@ablogcms/components/alert';
import Badge from '@ablogcms/components/badge';
import ButtonV2 from '@ablogcms/components/button-v2';
import Table, {
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@ablogcms/components/table';
import { useEffect, useState } from 'react';
import { ApiError } from '../api/client';
import { useApi } from '../api/context';
import { formatDateTime, JOB_STATUS_LABEL, jobStatusVariant } from '../labels';
import type { Job } from '../types';

const PER_PAGE = 20;

export function JobList() {
  const api = useApi();
  const [page, setPage] = useState(1);
  const [data, setData] = useState<{ jobs: Job[]; total: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    api
      .call<{ jobs: Job[]; total: number }>('jobs', { page })
      .then((res) => {
        if (alive) {
          setData(res);
          setError(null);
        }
      })
      .catch((e: unknown) => {
        if (alive) setError(e instanceof ApiError ? e.message : String(e));
      });
    return () => {
      alive = false;
    };
  }, [api, page]);

  const pages = Math.max(1, Math.ceil((data?.total ?? 0) / PER_PAGE));

  return (
    <section className="a2m-step" aria-labelledby="a2m-jobs-title">
      <h2 id="a2m-jobs-title" className="acms-admin-admin-title2">
        過去の実行
      </h2>
      {error !== null && (
        <Alert variant="danger" role="alert">
          {error}
        </Alert>
      )}
      {data !== null && data.jobs.length === 0 && (
        <p className="a2m-muted">まだ変換ジョブはありません。</p>
      )}
      {data !== null && data.jobs.length > 0 && (
        <>
          <Table variant="list">
            <TableHeader>
              <TableRow>
                <TableHead>ジョブ</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>作成日時</TableHead>
                <TableHead align="right">合計</TableHead>
                <TableHead align="right">変換済み</TableHead>
                <TableHead align="right">スキップ</TableHead>
                <TableHead align="right">エラー</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.jobs.map((j) => (
                <TableRow key={j.id}>
                  <TableCell>
                    <a href={`#/jobs/${j.id}`}>#{j.id}</a>
                  </TableCell>
                  <TableCell>
                    <Badge variant={jobStatusVariant(j.status)}>{JOB_STATUS_LABEL[j.status]}</Badge>
                  </TableCell>
                  <TableCell>{formatDateTime(j.createdAt)}</TableCell>
                  <TableCell>{j.total}</TableCell>
                  <TableCell>{j.converted}</TableCell>
                  <TableCell>{j.skipped}</TableCell>
                  <TableCell>{j.error}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <div className="a2m-pager">
            <ButtonV2 size="small" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              前のページ
            </ButtonV2>
            <span>
              {page} / {pages} ページ
            </span>
            <ButtonV2 size="small" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
              次のページ
            </ButtonV2>
          </div>
        </>
      )}
    </section>
  );
}
