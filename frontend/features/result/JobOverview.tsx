import Alert from '@ablogcms/components/alert';
import Badge from '@ablogcms/components/badge';
import ButtonV2 from '@ablogcms/components/button-v2';
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
}

const ROLLBACKABLE = new Set(['completed', 'paused', 'running', 'failed', 'rolling_back']);
const COUNT_ORDER = ['converted', 'skipped', 'error', 'excluded', 'pending', 'rolled_back'];

export function JobOverview({ detail, onRollback, onCsv, onRequeue }: Props) {
  const defs = useDefinitions();
  const { job, counts } = detail;
  const retryable = (counts.skipped ?? 0) + (counts.error ?? 0) > 0;
  const hasFields =
    job.options.targets.includes('field_image') || job.options.targets.includes('field_file');
  const fieldKeys = {
    image: job.options.targets.includes('field_image') ? defs.fieldKeys.image : [],
    file: job.options.targets.includes('field_file') ? defs.fieldKeys.file : [],
  };

  return (
    <div className="a2m-overview">
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

      <div className="a2m-actions">
        <ButtonV2 onClick={onCsv}>結果を CSV でダウンロード</ButtonV2>
        {retryable && <ButtonV2 onClick={onRequeue}>スキップ・エラー分を再実行</ButtonV2>}
        {ROLLBACKABLE.has(job.status) && (counts.converted ?? 0) > 0 && (
          <ButtonV2 onClick={onRollback}>ロールバック</ButtonV2>
        )}
      </div>

      {hasFields && <TemplateHints fieldKeys={fieldKeys} field={job.options.field} />}
    </div>
  );
}
