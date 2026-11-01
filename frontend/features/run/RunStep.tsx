import { Alert } from '@ablogcms/components/alert';
import { Badge } from '@ablogcms/components/badge';
import { Button } from '@ablogcms/components/button';
import { ProgressBar } from '@ablogcms/components/progress-bar';
import { useEffect, useRef, useState } from 'react';
import { itemStatusVariant } from '../../labels';
import { useApi } from '../../api/context';
import { useChunkRunner } from '../../hooks/useChunkRunner';
import type { ChunkResult, LogEntry } from '../../types';

interface Props {
  jobId: number;
  /** 開始時点で処理対象のアイテム数 */
  total: number;
  onFinished: () => void;
  /** 繰り返し呼ぶ操作（既定は変換の実行） */
  action?: 'run' | 'rollback' | 'cleanup';
  extraParams?: Record<string, unknown>;
}

const MAX_LOG = 200;

/** これまでの速さから残り時間の目安を作る。進みが少ないうちは出さない */
export function estimateRemaining(done: number, total: number, elapsedMs: number): string | null {
  if (done < 5 || done >= total || elapsedMs < 3000) return null;
  const seconds = Math.ceil(((total - done) * elapsedMs) / done / 1000);
  return seconds < 60 ? `${seconds} 秒` : `${Math.ceil(seconds / 60)} 分`;
}

export function RunStep({ jobId, total, onFinished, action = 'run', extraParams }: Props) {
  const api = useApi();
  const [counts, setCounts] = useState({ converted: 0, skipped: 0, error: 0 });
  const [log, setLog] = useState<LogEntry[]>([]);
  const startedAt = useRef<number | null>(null);
  const doneRef = useRef(0);
  const [remaining, setRemaining] = useState<string | null>(null);

  const runner = useChunkRunner<ChunkResult>(
    () => api.call<ChunkResult>(action, { jobId, ...extraParams }),
    {
      onResult: (r) => {
        const now = Date.now();
        startedAt.current ??= now;
        setCounts((c) => ({
          converted: c.converted + r.converted,
          skipped: c.skipped + r.skipped,
          error: c.error + r.error,
        }));
        doneRef.current += r.converted + r.skipped + r.error;
        const done = doneRef.current;
        setRemaining(estimateRemaining(done, Math.max(total, done), now - startedAt.current));
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
    <section className="acms-admin-a2m-step" aria-labelledby="a2m-run-title">
      <h2 id="a2m-run-title" className="acms-admin-admin-title2">
        {action === 'run'
          ? '3. 変換の実行'
          : action === 'rollback'
            ? 'ロールバック'
            : '旧データのクリーンアップ'}
      </h2>

      <ProgressBar value={percent} striped animated={runner.running} label={`${percent}%`} />
      <p className="acms-admin-a2m-count">
        {done} / {denominator} 件{remaining !== null && `（残り約 ${remaining}）`}
      </p>
      <p className="acms-admin-a2m-stats">
        <Badge variant={itemStatusVariant('converted')}>
          {action === 'run' ? '変換済み' : action === 'rollback' ? '復元済み' : '削除済み'}{' '}
          {counts.converted}
        </Badge>{' '}
        <Badge variant={itemStatusVariant('skipped')}>スキップ {counts.skipped}</Badge>{' '}
        <Badge variant={itemStatusVariant('error')}>エラー {counts.error}</Badge>
      </p>

      {runner.paused && (
        <Alert variant="warning" role="status">
          一時停止中です。タブを閉じても、ジョブの画面から再開できます。
        </Alert>
      )}
      {runner.error !== null && (
        <Alert variant="danger" role="alert">
          {runner.error.message}{' '}
          <Button size="small" onClick={() => void runner.start()}>
            再試行
          </Button>
        </Alert>
      )}

      <div className="acms-admin-a2m-actions">
        {runner.running && <Button onClick={runner.pause}>一時停止</Button>}
        {runner.paused && (
          <Button variant="primary" onClick={() => void runner.start()}>
            再開
          </Button>
        )}
      </div>

      <ol className="acms-admin-a2m-log" aria-label="処理ログ">
        {log.map((l, i) => (
          <li
            key={`${l.itemId}-${i}`}
            className={`acms-admin-a2m-log-item acms-admin-a2m-log-item--${l.status}`}
          >
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
