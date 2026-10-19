import { Alert } from '@ablogcms/components/alert';
import { Badge } from '@ablogcms/components/badge';
import { Button } from '@ablogcms/components/button';
import { Table, TableBody, TableCell, TableHead, TableRow } from '@ablogcms/components/table';
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
  onFinalize: () => void;
}

// サーバー側で「スキップ・エラー分の再実行」を受け付ける状態
const RETRYABLE = new Set(['completed', 'paused', 'running', 'failed']);
const ROLLBACKABLE = new Set(['completed', 'paused', 'running', 'failed', 'rolling_back']);
const COUNT_ORDER = ['converted', 'skipped', 'error', 'excluded', 'pending', 'rolled_back'];

export function JobOverview({
  detail,
  onRollback,
  onCsv,
  onRequeue,
  onCleanup,
  onFinalize,
}: Props) {
  const defs = useDefinitions();
  const { job, counts } = detail;
  const cleanup = detail.cleanup ?? { cleanable: 0, cleaned: 0 };
  const finalized = job.status === 'finalized';
  // 変換済みが残っているとき。ロールバック中（メディアの削除の途中など）や、ロールバックの途中で失敗したジョブ
  // （変換済みは 0 件でも、戻し済みがある）は、変換済みが 0 件でも続きをやり直せるようにする
  const canRollback =
    ROLLBACKABLE.has(job.status) &&
    ((counts.converted ?? 0) > 0 ||
      job.status === 'rolling_back' ||
      (job.status === 'failed' && (counts.rolled_back ?? 0) > 0));
  const retryable = RETRYABLE.has(job.status) && (counts.skipped ?? 0) + (counts.error ?? 0) > 0;
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

      {job.status === 'rolled_back' && (counts.error ?? 0) > 0 && (
        <Alert variant="warning" role="alert">
          ロールバックは終わりましたが、変換後に編集されていた {counts.error}{' '}
          件は戻せていません（そのまま変換後の状態です）。アイテムの一覧で「エラー」を確認してください。
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
        {canRollback && <Button onClick={onRollback}>ロールバック</Button>}
        {job.status === 'completed' && (counts.converted ?? 0) > 0 && (
          <Button onClick={onFinalize}>変換を確定する</Button>
        )}
        {finalized && detail.finalizing === true && (
          <Button onClick={onFinalize}>記録の回収を続ける</Button>
        )}
      </div>

      {finalized && (
        <p className="acms-admin-a2m-muted">
          変換は確定済みです。ロールバックできません（記録を削除して容量を回収しました）。
        </p>
      )}

      {cleanup.cleaned > 0 && (
        <p className="acms-admin-a2m-muted">旧データ削除済み: {cleanup.cleaned} 件</p>
      )}

      {hasFields && <TemplateHints fieldKeys={fieldKeys} field={job.options.field} />}
    </div>
  );
}
