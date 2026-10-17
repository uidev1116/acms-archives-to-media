import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '../api/client';

export interface ChunkRunnerOptions<R> {
  /** チャンクごとの結果の通知 */
  onResult?: (result: R) => void;
}

export interface ChunkRunner {
  running: boolean;
  paused: boolean;
  finished: boolean;
  error: ApiError | null;
  /** 続きから実行する（実行中は無視） */
  start: () => Promise<void>;
  /** 実行中のチャンクが終わったら止める */
  pause: () => void;
  reset: () => void;
}

/**
 * done になるまでチャンクの POST を繰り返す。タブを閉じると止まるが、サーバー側の状態は
 * 保たれるので、画面を開き直して start() すれば続きから再開できる。
 */
export function useChunkRunner<R extends { done: boolean }>(
  step: () => Promise<R>,
  options: ChunkRunnerOptions<R> = {},
): ChunkRunner {
  const [running, setRunning] = useState(false);
  const [paused, setPaused] = useState(false);
  const [finished, setFinished] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const runningRef = useRef(false);
  const stopRef = useRef(false);
  const mountedRef = useRef(true);
  const stepRef = useRef(step);
  const onResultRef = useRef(options.onResult);
  stepRef.current = step;
  onResultRef.current = options.onResult;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      stopRef.current = true;
    };
  }, []);

  const start = useCallback(async () => {
    if (runningRef.current) return;
    runningRef.current = true;
    stopRef.current = false;
    setRunning(true);
    setPaused(false);
    setError(null);
    try {
      while (!stopRef.current) {
        const result = await stepRef.current();
        onResultRef.current?.(result);
        if (result.done) {
          if (mountedRef.current) setFinished(true);
          return;
        }
      }
      if (mountedRef.current) setPaused(true);
    } catch (e) {
      if (mountedRef.current) {
        setError(
          e instanceof ApiError
            ? e
            : new ApiError(e instanceof Error ? e.message : String(e), 0, 'unknown'),
        );
      }
    } finally {
      runningRef.current = false;
      if (mountedRef.current) setRunning(false);
    }
  }, []);

  const pause = useCallback(() => {
    stopRef.current = true;
  }, []);

  const reset = useCallback(() => {
    stopRef.current = false;
    setPaused(false);
    setFinished(false);
    setError(null);
  }, []);

  return { running, paused, finished, error, start, pause, reset };
}
