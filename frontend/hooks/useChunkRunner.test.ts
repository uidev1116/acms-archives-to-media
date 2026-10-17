import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import { useChunkRunner } from './useChunkRunner';

interface R {
  done: boolean;
  n: number;
}

function deferred<T>() {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

describe('useChunkRunner', () => {
  it('done になるまでチャンクを繰り返し呼び、結果を通知する', async () => {
    const step = vi
      .fn<() => Promise<R>>()
      .mockResolvedValueOnce({ done: false, n: 1 })
      .mockResolvedValueOnce({ done: false, n: 2 })
      .mockResolvedValueOnce({ done: true, n: 3 });
    const onResult = vi.fn();
    const { result } = renderHook(() => useChunkRunner<R>(step, { onResult }));

    await act(async () => {
      await result.current.start();
    });

    expect(step).toHaveBeenCalledTimes(3);
    expect(onResult.mock.calls.map((c) => (c[0] as R).n)).toEqual([1, 2, 3]);
    expect(result.current.running).toBe(false);
    expect(result.current.finished).toBe(true);
    expect(result.current.error).toBeNull();
  });

  it('一時停止は実行中のチャンクが終わってから止まり、再開できる', async () => {
    const first = deferred<R>();
    const step = vi
      .fn<() => Promise<R>>()
      .mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce({ done: true, n: 2 });
    const { result } = renderHook(() => useChunkRunner<R>(step));

    let started!: Promise<void>;
    act(() => {
      started = result.current.start();
    });
    expect(result.current.running).toBe(true);

    act(() => {
      result.current.pause();
    });
    await act(async () => {
      first.resolve({ done: false, n: 1 });
      await started;
    });

    expect(step).toHaveBeenCalledTimes(1);
    expect(result.current.running).toBe(false);
    expect(result.current.paused).toBe(true);
    expect(result.current.finished).toBe(false);

    await act(async () => {
      await result.current.start();
    });
    expect(step).toHaveBeenCalledTimes(2);
    expect(result.current.paused).toBe(false);
    expect(result.current.finished).toBe(true);
  });

  it('エラーで止まり、error に入る。再度 start すると再試行する', async () => {
    const step = vi
      .fn<() => Promise<R>>()
      .mockRejectedValueOnce(new ApiError('busy', 409, 'busy'))
      .mockResolvedValueOnce({ done: true, n: 1 });
    const { result } = renderHook(() => useChunkRunner<R>(step));

    await act(async () => {
      await result.current.start();
    });
    expect(result.current.error?.code).toBe('busy');
    expect(result.current.running).toBe(false);
    expect(result.current.finished).toBe(false);

    await act(async () => {
      await result.current.start();
    });
    expect(result.current.error).toBeNull();
    expect(result.current.finished).toBe(true);
  });

  it('API エラー以外の例外も ApiError にそろえる', async () => {
    const step = vi.fn<() => Promise<R>>().mockRejectedValue(new Error('boom'));
    const { result } = renderHook(() => useChunkRunner<R>(step));
    await act(async () => {
      await result.current.start();
    });
    expect(result.current.error).toBeInstanceOf(ApiError);
    expect(result.current.error?.message).toBe('boom');
  });

  it('実行中の start は無視される', async () => {
    const gate = deferred<R>();
    const step = vi.fn<() => Promise<R>>().mockReturnValue(gate.promise);
    const { result } = renderHook(() => useChunkRunner<R>(step));

    let first!: Promise<void>;
    act(() => {
      first = result.current.start();
    });
    await act(async () => {
      await result.current.start();
    });
    expect(step).toHaveBeenCalledTimes(1);

    await act(async () => {
      gate.resolve({ done: true, n: 1 });
      await first;
    });
    await waitFor(() => expect(result.current.running).toBe(false));
  });

  it('reset で状態を初期化する', async () => {
    const step = vi.fn<() => Promise<R>>().mockResolvedValue({ done: true, n: 1 });
    const { result } = renderHook(() => useChunkRunner<R>(step));
    await act(async () => {
      await result.current.start();
    });
    act(() => {
      result.current.reset();
    });
    expect(result.current.finished).toBe(false);
    expect(result.current.paused).toBe(false);
    expect(result.current.error).toBeNull();
  });
});
