import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/client';
import { fakeClient, renderWithApi } from '../../test/helpers';
import type { ChunkResult } from '../../types';
import { RunStep } from './RunStep';

function chunk(over: Partial<ChunkResult>): ChunkResult {
  return {
    status: 'running',
    processed: 2,
    converted: 2,
    skipped: 0,
    error: 0,
    done: false,
    remaining: 2,
    log: [],
    ...over,
  };
}

describe('RunStep', () => {
  it('完了まで繰り返し、進捗とログを表示して onFinished を呼ぶ', async () => {
    const onFinished = vi.fn();
    const client = fakeClient({
      run: [
        chunk({
          log: [
            { itemId: 1, status: 'converted', reason: '', message: '' },
            {
              itemId: 2,
              status: 'skipped',
              reason: 'ENTRY_LOCKED',
              message: 'エントリーが編集ロック中です',
            },
          ],
          skipped: 1,
          converted: 1,
        }),
        chunk({
          status: 'completed',
          done: true,
          remaining: 0,
          processed: 2,
          converted: 2,
          log: [{ itemId: 3, status: 'converted', reason: '', message: '' }],
        }),
      ],
    });
    renderWithApi(<RunStep jobId={4} total={4} onFinished={onFinished} />, client);

    await waitFor(() => expect(onFinished).toHaveBeenCalledTimes(1));
    expect(client.calls.filter((c) => c.action === 'run')).toHaveLength(2);
    expect(screen.getByText('4 / 4 件')).toBeInTheDocument();
    expect(screen.getByText(/変換済み 3/)).toBeInTheDocument();
    expect(screen.getByText(/スキップ 1/)).toBeInTheDocument();
    expect(screen.getByText(/#2/)).toBeInTheDocument();
    expect(screen.getByText(/ENTRY_LOCKED/)).toBeInTheDocument();
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
  });

  it('一時停止して再開できる', async () => {
    let release!: (v: ChunkResult) => void;
    const first = new Promise<ChunkResult>((r) => {
      release = r;
    });
    const client = fakeClient({
      run: [() => first, chunk({ status: 'completed', done: true, remaining: 0 })],
      pause: { job: {} },
    });
    const onFinished = vi.fn();
    renderWithApi(<RunStep jobId={4} total={4} onFinished={onFinished} />, client);

    await userEvent.click(await screen.findByRole('button', { name: '一時停止' }));
    release(chunk({ remaining: 2 }));

    expect(
      await screen.findByText('一時停止中です。タブを閉じても、ジョブの画面から再開できます。'),
    ).toBeInTheDocument();
    expect(onFinished).not.toHaveBeenCalled();
    // 一時停止はサーバーにも記録する（タブを閉じても「実行中」のままにしない）
    await waitFor(() =>
      expect(client.calls.filter((c) => c.action === 'pause')).toEqual([
        { action: 'pause', params: { jobId: 4 } },
      ]),
    );

    await userEvent.click(screen.getByRole('button', { name: '再開' }));
    await waitFor(() => expect(onFinished).toHaveBeenCalledTimes(1));
  });

  it('エラーで止まり、再試行で続きから実行する', async () => {
    const client = fakeClient({
      run: [
        new ApiError('同じブログで別の処理が実行中です。', 409, 'busy'),
        chunk({ status: 'completed', done: true, remaining: 0 }),
      ],
    });
    const onFinished = vi.fn();
    renderWithApi(<RunStep jobId={4} total={2} onFinished={onFinished} />, client);

    expect(await screen.findByRole('alert')).toHaveTextContent('別の処理が実行中');
    await userEvent.click(screen.getByRole('button', { name: '再試行' }));
    await waitFor(() => expect(onFinished).toHaveBeenCalledTimes(1));
  });

  it('一時停止の記録に失敗しても画面は止まったままで、エラーにしない', async () => {
    let release!: (v: ChunkResult) => void;
    const first = new Promise<ChunkResult>((r) => {
      release = r;
    });
    const client = fakeClient({ run: [() => first], pause: new ApiError('x', 500, 'internal') });
    renderWithApi(<RunStep jobId={4} total={4} onFinished={vi.fn()} />, client);
    await userEvent.click(await screen.findByRole('button', { name: '一時停止' }));
    release(chunk({ remaining: 2 }));
    expect(await screen.findByRole('button', { name: '再開' })).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('ロールバックの一時停止は pause を呼ばない', async () => {
    let release!: (v: ChunkResult) => void;
    const first = new Promise<ChunkResult>((r) => {
      release = r;
    });
    const client = fakeClient({ rollback: [() => first] });
    renderWithApi(<RunStep jobId={4} total={4} action="rollback" onFinished={vi.fn()} />, client);
    await userEvent.click(await screen.findByRole('button', { name: '一時停止' }));
    release(chunk({ status: 'rolling_back', remaining: 2 }));
    expect(await screen.findByRole('button', { name: '再開' })).toBeInTheDocument();
    expect(client.calls.some((c) => c.action === 'pause')).toBe(false);
  });

  it('ロールバックでは rollback を呼び、追加パラメーターを渡す', async () => {
    const onFinished = vi.fn();
    const client = fakeClient({
      rollback: chunk({ status: 'rolled_back', done: true, remaining: 0, converted: 3 }),
    });
    renderWithApi(
      <RunStep
        jobId={4}
        total={3}
        action="rollback"
        extraParams={{ deleteCreatedMedia: true }}
        onFinished={onFinished}
      />,
      client,
    );
    await waitFor(() => expect(onFinished).toHaveBeenCalledTimes(1));
    expect(client.calls[0]).toEqual({
      action: 'rollback',
      params: { jobId: 4, deleteCreatedMedia: true },
    });
    expect(screen.getByRole('heading', { name: 'ロールバック' })).toBeInTheDocument();
    expect(screen.getByText(/復元済み 3/)).toBeInTheDocument();
  });

  it('total が 0 でも壊れない', async () => {
    const client = fakeClient({
      run: chunk({ status: 'completed', done: true, remaining: 0, processed: 0, converted: 0 }),
    });
    renderWithApi(<RunStep jobId={4} total={0} onFinished={vi.fn()} />, client);
    expect(await screen.findByText('0 / 0 件')).toBeInTheDocument();
  });
});
