import Alert from '@ablogcms/components/alert';
import Button from '@ablogcms/components/button';
import { Loading } from '../components/Loading';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '../api/client';
import { useApi } from '../api/context';
import { JobOverview } from '../features/result/JobOverview';
import { CleanupModal } from '../features/result/CleanupModal';
import { DeleteJobModal } from '../features/result/DeleteJobModal';
import { FinalizeModal } from '../features/result/FinalizeModal';
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
  | { type: 'rollback'; total: number; deleteMedia: boolean }
  | { type: 'cleanup'; total: number };

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
  const [cleanupOpen, setCleanupOpen] = useState(false);
  const [finalizeOpen, setFinalizeOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const requeuing = useRef(false);

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
    if (requeuing.current) return; // 実行中の連打は無視する（並行して繰り返さない）
    requeuing.current = true;
    setError(null);
    setNotice(null);
    try {
      // 件数が多いと 1 回では終わらない。サーバーが done を返すまで繰り返す
      // 最初の呼び出しだけ、前回途中のカーソルを捨てて最初から読ませる
      let requeued = 0;
      for (let first = true; ; first = false) {
        const res = await api.call<{ requeued: number; done?: boolean }>('requeue', {
          jobId,
          restart: first,
        });
        requeued += res.requeued;
        if (res.done !== false) break;
      }
      if (requeued === 0) {
        setNotice('再実行できるアイテムはありませんでした。');
        return;
      }
      const fresh = await load();
      setMode({ type: 'run', total: fresh?.counts.pending ?? requeued });
    } catch (e) {
      setError(messageOf(e));
    } finally {
      requeuing.current = false;
    }
  };

  const finalize = async (keepLegacyKeys: boolean) => {
    setError(null);
    try {
      // 件数が多いと 1 回では回収し切れない。サーバーが done を返すまで繰り返す
      for (;;) {
        const res = await api.call<{ done?: boolean }>('finalize', { jobId, keepLegacyKeys });
        if (res.done !== false) break;
      }
      await finish();
    } catch (e) {
      setError(messageOf(e));
      await load();
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
      <Loading size="1.5em" />
    );
  }

  const status = detail.job.status;
  const converted = detail.counts.converted ?? 0;

  return (
    <div className="acms-admin-a2m-panel">
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
            onCleanup={() => setCleanupOpen(true)}
            onFinalize={() =>
              status === 'finalized' ? void finalize(false) : setFinalizeOpen(true)
            }
          />
          {NOT_SCANNED.has(status) && (
            <Alert variant="warning" role="note">
              スキャンが完了していません。<a href="#/">新しい変換</a>からやり直してください。
            </Alert>
          )}
          <div className="acms-admin-a2m-actions">
            {RUNNABLE.has(status) && (status === 'scanned' || (detail.counts.pending ?? 0) > 0) && (
              <Button
                variant="primary"
                onClick={() => setMode({ type: 'run', total: detail.counts.pending ?? 0 })}
              >
                実行を再開
              </Button>
            )}
            {allowDelete && <Button onClick={() => setDeleteOpen(true)}>ジョブを削除</Button>}
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

      {mode.type === 'cleanup' && (
        <RunStep
          jobId={jobId}
          total={mode.total}
          action="cleanup"
          onFinished={() => void finish()}
        />
      )}

      <CleanupModal
        isOpen={cleanupOpen}
        count={detail.cleanup?.cleanable ?? 0}
        onClose={() => setCleanupOpen(false)}
        onConfirm={() => {
          setCleanupOpen(false);
          setMode({ type: 'cleanup', total: detail.cleanup?.cleanable ?? 0 });
        }}
      />

      <DeleteJobModal
        isOpen={deleteOpen}
        jobId={jobId}
        onClose={() => setDeleteOpen(false)}
        onConfirm={() => {
          setDeleteOpen(false);
          void remove();
        }}
      />

      <FinalizeModal
        isOpen={finalizeOpen}
        count={converted}
        legacyCount={detail.cleanup?.cleanable ?? 0}
        onClose={() => setFinalizeOpen(false)}
        onConfirm={(keepLegacyKeys) => {
          setFinalizeOpen(false);
          void finalize(keepLegacyKeys);
        }}
      />

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
