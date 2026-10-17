import Alert from '@ablogcms/components/alert';
import ButtonV2 from '@ablogcms/components/button-v2';
import Spinner from '@ablogcms/components/spinner';
import { useCallback, useEffect, useState } from 'react';
import { ApiError } from '../api/client';
import { useApi } from '../api/context';
import { JobOverview } from '../features/result/JobOverview';
import { RollbackModal } from '../features/result/RollbackModal';
import { RunStep } from '../features/run/RunStep';
import type { JobDetail } from '../types';

interface Props {
  jobId: number;
  /** ジョブの状態が変わったとき（親が一覧などを読み直すため） */
  onChanged?: () => void;
  allowDelete?: boolean;
  onDeleted?: () => void;
}

type Mode =
  | { type: 'view' }
  | { type: 'run'; total: number }
  | { type: 'rollback'; total: number; deleteMedia: boolean };

const RUNNABLE = new Set(['scanned', 'paused', 'running', 'failed']);
const NOT_SCANNED = new Set(['draft', 'scanning']);

function messageOf(e: unknown): string {
  return e instanceof ApiError ? e.message : String(e);
}

/**
 * ジョブの概要と操作（再開・スキップ分の再実行・ロールバック・CSV・削除）。
 * 結果画面とジョブ詳細画面の両方で使う。
 */
export function JobPanel({ jobId, onChanged, allowDelete = false, onDeleted }: Props) {
  const api = useApi();
  const [detail, setDetail] = useState<JobDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [mode, setMode] = useState<Mode>({ type: 'view' });
  const [rollbackOpen, setRollbackOpen] = useState(false);

  const load = useCallback(async (): Promise<JobDetail | null> => {
    try {
      const res = await api.call<JobDetail>('job', { jobId });
      setDetail(res);
      return res;
    } catch (e) {
      setError(messageOf(e));
      return null;
    }
  }, [api, jobId]);

  useEffect(() => {
    void load();
  }, [load]);

  const finish = useCallback(async () => {
    setMode({ type: 'view' });
    await load();
    onChanged?.();
  }, [load, onChanged]);

  const requeue = async () => {
    setError(null);
    setNotice(null);
    try {
      const res = await api.call<{ requeued: number }>('requeue', { jobId });
      if (res.requeued === 0) {
        setNotice('再実行できるアイテムはありませんでした。');
        return;
      }
      const fresh = await load();
      setMode({ type: 'run', total: fresh?.counts.pending ?? res.requeued });
    } catch (e) {
      setError(messageOf(e));
    }
  };

  const remove = async () => {
    setError(null);
    try {
      await api.call('delete', { jobId });
      onDeleted?.();
    } catch (e) {
      setError(messageOf(e));
    }
  };

  if (detail === null) {
    return error !== null ? (
      <Alert variant="danger" role="alert">
        {error}
      </Alert>
    ) : (
      <Spinner size="1.5em" />
    );
  }

  const status = detail.job.status;
  const converted = detail.counts.converted ?? 0;

  return (
    <div className="a2m-panel">
      {error !== null && (
        <Alert variant="danger" role="alert">
          {error}
        </Alert>
      )}
      {notice !== null && (
        <Alert variant="info" role="status">
          {notice}
        </Alert>
      )}

      {mode.type === 'view' && (
        <>
          <JobOverview
            detail={detail}
            onCsv={() => api.exportCsv(jobId)}
            onRequeue={() => void requeue()}
            onRollback={() => setRollbackOpen(true)}
          />
          {NOT_SCANNED.has(status) && (
            <Alert variant="warning" role="note">
              スキャンが完了していません。<a href="#/">新しい変換</a>からやり直してください。
            </Alert>
          )}
          <div className="a2m-actions">
            {RUNNABLE.has(status) && (
              <ButtonV2
                variant="filled"
                onClick={() => setMode({ type: 'run', total: detail.counts.pending ?? 0 })}
              >
                実行を再開
              </ButtonV2>
            )}
            {allowDelete && <ButtonV2 onClick={() => void remove()}>ジョブを削除</ButtonV2>}
          </div>
        </>
      )}

      {mode.type === 'run' && (
        <RunStep jobId={jobId} total={mode.total} onFinished={() => void finish()} />
      )}
      {mode.type === 'rollback' && (
        <RunStep
          jobId={jobId}
          total={mode.total}
          action="rollback"
          extraParams={{ deleteCreatedMedia: mode.deleteMedia }}
          onFinished={() => void finish()}
        />
      )}

      <RollbackModal
        isOpen={rollbackOpen}
        count={converted}
        onClose={() => setRollbackOpen(false)}
        onConfirm={(deleteMedia) => {
          setRollbackOpen(false);
          setMode({ type: 'rollback', total: converted, deleteMedia });
        }}
      />
    </div>
  );
}
