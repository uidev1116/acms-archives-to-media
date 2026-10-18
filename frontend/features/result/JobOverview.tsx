import Alert from '@ablogcms/components/alert';
import Badge from '@ablogcms/components/badge';
import Button from '@ablogcms/components/button';
import Table, { TableBody, TableCell, TableHead, TableRow } from '@ablogcms/components/table';
import { useDefinitions } from '../../definitions';
import {
  formatDateTime,
  ITEM_STATUS_LABEL,
  JOB_STATUS_LABEL,
  jobStatusVariant,
} from '../../labels';
import type { JobDetail } from '../../types';
import { TemplateHints } from './TemplateHints';

interface Props {
  detail: JobDetail;
  onRollback: () => void;
  onCsv: () => void;
  onRequeue: () => void;
  onCleanup: () => void;
}

const ROLLBACKABLE = new Set(['completed', 'paused', 'running', 'failed', 'rolling_back']);
const COUNT_ORDER = ['converted', 'skipped', 'error', 'excluded', 'pending', 'rolled_back'];

export function JobOverview({ detail, onRollback, onCsv, onRequeue, onCleanup }: Props) {
  const defs = useDefinitions();
  const { job, counts } = detail;
  const cleanup = detail.cleanup ?? { cleanable: 0, cleaned: 0 };
  const retryable = (counts.skipped ?? 0) + (counts.error ?? 0) > 0;
  const hasFields =
    job.options.targets.includes('field_image') || job.options.targets.includes('field_file');
  const fieldKeys = {
    image: job.options.targets.includes('field_image') ? defs.fieldKeys.image : [],
    file: job.options.targets.includes('field_file') ? defs.fieldKeys.file : [],
  };

  return (
    <div className="acms-admin-a2m-overview">
      <p>
        ジョブ #{job.id}{' '}
        <Badge variant={jobStatusVariant(job.status)}>{JOB_STATUS_LABEL[job.status]}</Badge>
      </p>
      {job.message !== null && job.message !== '' && (
        <Alert variant="danger" role="alert">
          {job.message}
        </Alert>
      )}

      <Table variant="list">
        <TableBody>
          {COUNT_ORDER.filter((k) => (counts[k] ?? 0) > 0).map((k) => (
            <TableRow key={k}>
              <TableHead scope="row">{ITEM_STATUS_LABEL[k] ?? k}</TableHead>
              <TableCell>{counts[k]}</TableCell>
            </TableRow>
          ))}
          <TableRow>
            <TableHead scope="row">作成</TableHead>
            <TableCell>{formatDateTime(job.createdAt)}</TableCell>
          </TableRow>
          <TableRow>
            <TableHead scope="row">開始</TableHead>
            <TableCell>{formatDateTime(job.startedAt)}</TableCell>
          </TableRow>
          <TableRow>
            <TableHead scope="row">終了</TableHead>
            <TableCell>{formatDateTime(job.finishedAt)}</TableCell>
          </TableRow>
        </TableBody>
      </Table>

      <div className="acms-admin-a2m-actions">
        <Button onClick={onCsv}>結果を CSV でダウンロード</Button>
        {retryable && <Button onClick={onRequeue}>スキップ・エラー分を再実行</Button>}
        {job.status === 'completed' && cleanup.cleanable > 0 && (
          <Button onClick={onCleanup}>旧データを削除（クリーンアップ）</Button>
        )}
        {ROLLBACKABLE.has(job.status) && (counts.converted ?? 0) > 0 && (
          <Button onClick={onRollback}>ロールバック</Button>
        )}
      </div>

      {cleanup.cleaned > 0 && (
        <p className="acms-admin-a2m-muted">旧データ削除済み: {cleanup.cleaned} 件</p>
      )}

      {hasFields && <TemplateHints fieldKeys={fieldKeys} field={job.options.field} />}
    </div>
  );
}
