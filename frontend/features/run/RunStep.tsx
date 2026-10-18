import Alert from '@ablogcms/components/alert';
import ButtonV2 from '@ablogcms/components/button-v2';
import ProgressBar from '@ablogcms/components/progress-bar';
import { useEffect, useRef, useState } from 'react';
import { useApi } from '../../api/context';
import { useChunkRunner } from '../../hooks/useChunkRunner';
import type { ChunkResult, LogEntry } from '../../types';

interface Props {
  jobId: number;
  /** 開始時点で処理対象のアイテム数 */
  total: number;
  onFinished: () => void;
  /** 繰り返し呼ぶ操作（既定は変換の実行） */
  action?: 'run' | 'rollback';
  extraParams?: Record<string, unknown>;
}

const MAX_LOG = 200;

export function RunStep({ jobId, total, onFinished, action = 'run', extraParams }: Props) {
  const api = useApi();
  const [counts, setCounts] = useState({ converted: 0, skipped: 0, error: 0 });
  const [log, setLog] = useState<LogEntry[]>([]);

  const runner = useChunkRunner<ChunkResult>(
    () => api.call<ChunkResult>(action, { jobId, ...extraParams }),
    {
      onResult: (r) => {
        setCounts((c) => ({
          converted: c.converted + r.converted,
          skipped: c.skipped + r.skipped,
          error: c.error + r.error,
        }));
        setLog((cur) => [...cur, ...r.log].slice(-MAX_LOG));
      },
    },
  );

  const started = useRef(false);
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    void runner.start();
  }, [runner]);

  // 一時停止はサーバーにも記録する。記録に失敗しても画面は止まっているので、エラーにはしない。
  const pausedSent = useRef(false);
  useEffect(() => {
    if (runner.paused && action === 'run' && !pausedSent.current) {
      pausedSent.current = true;
      api.call('pause', { jobId }).catch(() => undefined);
    }
    if (!runner.paused) pausedSent.current = false;
  }, [runner.paused, action, api, jobId]);

  const finishedRef = useRef(false);
  useEffect(() => {
    if (runner.finished && !finishedRef.current) {
      finishedRef.current = true;
      onFinished();
    }
  }, [runner.finished, onFinished]);

  const done = counts.converted + counts.skipped + counts.error;
  const denominator = Math.max(total, done);
  const percent = denominator === 0 ? 100 : Math.round((done / denominator) * 100);

  return (
    <section className="a2m-step" aria-labelledby="a2m-run-title">
      <h2 id="a2m-run-title" className="acms-admin-admin-title2">
        {action === 'run' ? '3. 変換の実行' : 'ロールバック'}
      </h2>

      <ProgressBar value={percent} striped animated={runner.running} label={`${percent}%`} />
      <p className="a2m-count">
        {done} / {denominator} 件
      </p>
      <p className="a2m-stats">
        <span>
          {action === 'run' ? '変換済み' : '復元済み'} {counts.converted}
        </span>{' '}
        <span>スキップ {counts.skipped}</span> <span>エラー {counts.error}</span>
      </p>

      {runner.paused && (
        <Alert variant="warning" role="status">
          一時停止中です。タブを閉じても、ジョブの画面から再開できます。
        </Alert>
      )}
      {runner.error !== null && (
        <Alert variant="danger" role="alert">
          {runner.error.message}{' '}
          <ButtonV2 size="small" onClick={() => void runner.start()}>
            再試行
          </ButtonV2>
        </Alert>
      )}

      <div className="a2m-actions">
        {runner.running && <ButtonV2 onClick={runner.pause}>一時停止</ButtonV2>}
        {runner.paused && (
          <ButtonV2 variant="filled" onClick={() => void runner.start()}>
            再開
          </ButtonV2>
        )}
      </div>

      <ol className="a2m-log" aria-label="処理ログ">
        {log.map((l, i) => (
          <li key={`${l.itemId}-${i}`} className={`a2m-log-item a2m-log-item--${l.status}`}>
            #{l.itemId} {l.status}
            {l.reason !== '' && (
              <>
                {' '}
                <code>{l.reason}</code> {l.message}
              </>
            )}
          </li>
        ))}
      </ol>
    </section>
  );
}
