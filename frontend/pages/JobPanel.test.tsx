import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import { DefinitionsProvider } from '../definitions';
import { defaultFormState, toJobOptions } from '../features/settings/options';
import { fakeClient, renderWithApi } from '../test/helpers';
import type { ChunkResult, Definitions, Job, JobDetail } from '../types';
import { JobPanel } from './JobPanel';

const defs: Definitions = {
  mediaUnitTypes: ['media'],
  sourceUnitTypes: [],
  fieldKeys: { image: ['hero'], file: [] },
};

function detail(
  over: Partial<Job> = {},
  counts: Record<string, number> = { converted: 3 },
): JobDetail {
  return {
    job: {
      id: 7,
      status: 'completed',
      options: toJobOptions(defaultFormState(defs)),
      total: 3,
      converted: 3,
      skipped: 0,
      error: 0,
      message: null,
      userId: 1,
      blogId: 2,
      createdAt: '2026-10-18 05:00:00',
      startedAt: null,
      finishedAt: null,
      ...over,
    },
    summary: [],
    counts,
  };
}

const done = (over: Partial<ChunkResult> = {}): ChunkResult => ({
  status: 'completed',
  processed: 1,
  converted: 1,
  skipped: 0,
  error: 0,
  done: true,
  remaining: 0,
  log: [],
  ...over,
});

function mount(
  client: ReturnType<typeof fakeClient>,
  props: Partial<React.ComponentProps<typeof JobPanel>> = {},
) {
  return renderWithApi(
    <DefinitionsProvider value={defs}>
      <JobPanel jobId={7} {...props} />
    </DefinitionsProvider>,
    client,
  );
}

describe('JobPanel', () => {
  it('ジョブの概要を読み込んで表示する', async () => {
    const client = fakeClient({ job: detail() });
    mount(client);
    expect(await screen.findByText('完了')).toBeInTheDocument();
    expect(client.calls[0]).toEqual({ action: 'job', params: { jobId: 7 } });
  });

  it('読み込みに失敗したらエラーを表示する', async () => {
    mount(fakeClient({ job: new ApiError('ジョブが見つかりません。', 404, 'not_found') }));
    expect(await screen.findByRole('alert')).toHaveTextContent('ジョブが見つかりません。');
  });

  it('一時停止中のジョブは再開でき、終わったら概要を読み直す', async () => {
    const onChanged = vi.fn();
    const client = fakeClient({
      job: [detail({ status: 'paused', converted: 1 }, { converted: 1, pending: 2 }), detail()],
      run: done(),
    });
    mount(client, { onChanged });

    await userEvent.click(await screen.findByRole('button', { name: '実行を再開' }));
    expect(await screen.findByText('完了')).toBeInTheDocument();
    expect(client.calls.some((c) => c.action === 'run')).toBe(true);
    expect(onChanged).toHaveBeenCalled();
  });

  it('スキャン済みのジョブは実行できる', async () => {
    const client = fakeClient({
      job: [detail({ status: 'scanned' }, { pending: 3 }), detail()],
      run: done(),
    });
    mount(client);
    await userEvent.click(await screen.findByRole('button', { name: '実行を再開' }));
    await waitFor(() => expect(client.calls.some((c) => c.action === 'run')).toBe(true));
  });

  it('ロールバック: 確認ダイアログでメディア削除を選ぶと rollback を呼ぶ', async () => {
    const client = fakeClient({
      job: [detail(), detail({ status: 'rolled_back' }, { rolled_back: 3 })],
      rollback: done({ status: 'rolled_back', converted: 3 }),
    });
    mount(client);

    await userEvent.click(await screen.findByRole('button', { name: 'ロールバック' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(
      within(dialog).getByLabelText(
        'このジョブで新規作成したメディアのうち、参照が無いものを削除する',
      ),
    );
    await userEvent.click(within(dialog).getByRole('button', { name: 'ロールバックする' }));

    expect((await screen.findAllByText('ロールバック済み')).length).toBeGreaterThan(0);
    expect(client.calls.find((c) => c.action === 'rollback')?.params).toEqual({
      jobId: 7,
      deleteCreatedMedia: true,
    });
  });

  it('スキップ分の再実行: requeue してから run する', async () => {
    const client = fakeClient({
      job: [
        detail({ skipped: 1 }, { converted: 2, skipped: 1 }),
        detail({ status: 'paused' }, { converted: 2, pending: 1 }),
        detail(),
      ],
      requeue: { requeued: 1, job: detail({ status: 'paused' }).job },
      run: done(),
    });
    mount(client);
    await userEvent.click(
      await screen.findByRole('button', { name: 'スキップ・エラー分を再実行' }),
    );
    await waitFor(() => expect(client.calls.map((c) => c.action)).toContain('run'));
    expect(client.calls.map((c) => c.action).slice(0, 3)).toEqual(['job', 'requeue', 'job']);
  });

  it('スキップ分が多いときは_done になるまで requeue を繰り返してから run する', async () => {
    const client = fakeClient({
      job: [
        detail({ skipped: 3 }, { converted: 2, skipped: 3 }),
        detail({ status: 'paused' }, { converted: 2, pending: 3 }),
        detail(),
      ],
      requeue: [
        { requeued: 2, done: false, job: detail({ status: 'paused' }).job },
        { requeued: 1, done: true, job: detail({ status: 'paused' }).job },
      ],
      run: done(),
    });
    mount(client);
    await userEvent.click(
      await screen.findByRole('button', { name: 'スキップ・エラー分を再実行' }),
    );
    await waitFor(() => expect(client.calls.map((c) => c.action)).toContain('run'));
    expect(client.calls.map((c) => c.action).slice(0, 4)).toEqual([
      'job',
      'requeue',
      'requeue',
      'job',
    ]);
  });

  it('再実行するものが無ければお知らせだけ出す', async () => {
    const client = fakeClient({
      job: detail({ skipped: 1 }, { converted: 2, skipped: 1 }),
      requeue: { requeued: 0, job: detail().job },
    });
    mount(client);
    await userEvent.click(
      await screen.findByRole('button', { name: 'スキップ・エラー分を再実行' }),
    );
    expect(await screen.findByText('再実行できるアイテムはありませんでした。')).toBeInTheDocument();
  });

  it('再実行に失敗したらエラーを表示する', async () => {
    const client = fakeClient({
      job: detail({ skipped: 1 }, { converted: 2, skipped: 1 }),
      requeue: new ApiError('このジョブはスキップ分を再実行できない状態です', 409, 'state'),
    });
    mount(client);
    await userEvent.click(
      await screen.findByRole('button', { name: 'スキップ・エラー分を再実行' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('再実行できない状態');
  });

  it('CSV をダウンロードできる', async () => {
    const client = fakeClient({ job: detail() });
    mount(client);
    await userEvent.click(await screen.findByRole('button', { name: '結果を CSV でダウンロード' }));
    expect(client.exportCsv).toHaveBeenCalledWith(7);
  });

  it('削除できる（削除を許可した画面だけ）: 確認ダイアログで同意してから delete を呼ぶ', async () => {
    const onDeleted = vi.fn();
    const client = fakeClient({
      job: detail({ status: 'rolled_back' }, { rolled_back: 3 }),
      delete: { deleted: true },
    });
    mount(client, { allowDelete: true, onDeleted });
    await userEvent.click(await screen.findByRole('button', { name: 'ジョブを削除' }));
    const dialog = await screen.findByRole('dialog');
    expect(client.calls.map((c) => c.action)).not.toContain('delete');
    await userEvent.click(within(dialog).getByRole('button', { name: '削除する' }));
    await waitFor(() => expect(onDeleted).toHaveBeenCalled());
    expect(client.calls.find((c) => c.action === 'delete')?.params).toEqual({ jobId: 7 });
  });

  it('削除の確認をキャンセルしたら何もしない', async () => {
    const client = fakeClient({ job: detail({ status: 'rolled_back' }, { rolled_back: 3 }) });
    mount(client, { allowDelete: true });
    await userEvent.click(await screen.findByRole('button', { name: 'ジョブを削除' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'キャンセル' }));
    expect(client.calls.map((c) => c.action)).not.toContain('delete');
  });

  it('削除に失敗したらエラーを表示する', async () => {
    const client = fakeClient({
      job: detail({ status: 'rolled_back' }, { rolled_back: 3 }),
      delete: new ApiError('先にロールバックしてください', 409, 'state'),
    });
    mount(client, { allowDelete: true });
    await userEvent.click(await screen.findByRole('button', { name: 'ジョブを削除' }));
    await userEvent.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: '削除する' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('先にロールバック');
  });

  it('削除ボタンは許可していない画面では出さない', async () => {
    mount(fakeClient({ job: detail() }));
    await screen.findByText('完了');
    expect(screen.queryByRole('button', { name: 'ジョブを削除' })).not.toBeInTheDocument();
  });

  it('スキャンが終わっていないジョブには、ウィザードでやり直す案内を出す', async () => {
    mount(fakeClient({ job: detail({ status: 'scanning' }, {}) }));
    expect(await screen.findByText(/スキャンが完了していません/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: '実行を再開' })).not.toBeInTheDocument();
  });

  it('旧データのクリーンアップ: 確認ダイアログで同意すると cleanup を呼び、終わったら概要を読み直す', async () => {
    const client = fakeClient({
      job: [
        { ...detail({}, { converted: 3 }), cleanup: { cleanable: 3, cleaned: 0 } },
        { ...detail({}, { converted: 3 }), cleanup: { cleanable: 0, cleaned: 3 } },
      ],
      cleanup: done({ converted: 3 }),
    });
    mount(client);

    await userEvent.click(
      await screen.findByRole('button', { name: '旧データを削除（クリーンアップ）' }),
    );
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByLabelText('テンプレートの移行は完了しています'));
    await userEvent.click(within(dialog).getByRole('button', { name: '旧データを削除する' }));

    expect(await screen.findByText('旧データ削除済み: 3 件')).toBeInTheDocument();
    expect(client.calls.find((c) => c.action === 'cleanup')?.params).toEqual({ jobId: 7 });
  });

  it('変換の確定: 理解したことを確認してから finalize を呼び、概要を読み直す', async () => {
    const client = fakeClient({
      job: [detail({}, { converted: 3 }), detail({ status: 'finalized' }, { converted: 3 })],
      finalize: { done: true, job: detail({ status: 'finalized' }).job },
    });
    mount(client);

    await userEvent.click(await screen.findByRole('button', { name: '変換を確定する' }));
    const dialog = await screen.findByRole('dialog');
    const ok = within(dialog).getByRole('button', { name: '確定する' });
    expect(ok).toBeDisabled();
    await userEvent.click(
      within(dialog).getByLabelText('ロールバックできなくなることを理解しました'),
    );
    await userEvent.click(ok);

    expect(await screen.findByText('確定済み')).toBeInTheDocument();
    expect(client.calls.find((c) => c.action === 'finalize')?.params).toEqual({
      jobId: 7,
      keepLegacyKeys: false,
    });
  });

  it('確定は、サーバーが done を返すまで繰り返す', async () => {
    const client = fakeClient({
      job: [detail({}, { converted: 3 }), detail({ status: 'finalized' }, { converted: 3 })],
      finalize: [
        { done: false, job: detail({ status: 'finalized' }).job },
        { done: true, job: detail({ status: 'finalized' }).job },
      ],
    });
    mount(client);
    await userEvent.click(await screen.findByRole('button', { name: '変換を確定する' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(
      within(dialog).getByLabelText('ロールバックできなくなることを理解しました'),
    );
    await userEvent.click(within(dialog).getByRole('button', { name: '確定する' }));
    expect(await screen.findByText('確定済み')).toBeInTheDocument();
    expect(client.calls.filter((c) => c.action === 'finalize')).toHaveLength(2);
  });

  it('旧データが残っているときは、残したまま確定することの確認も求め、keepLegacyKeys を送る', async () => {
    const client = fakeClient({
      job: [
        { ...detail({}, { converted: 3 }), cleanup: { cleanable: 3, cleaned: 0 } },
        detail({ status: 'finalized' }, { converted: 3 }),
      ],
      finalize: { done: true, job: detail({ status: 'finalized' }).job },
    });
    mount(client);
    await userEvent.click(await screen.findByRole('button', { name: '変換を確定する' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/旧データのクリーンアップが済んでいない/)).toBeInTheDocument();
    await userEvent.click(
      within(dialog).getByLabelText('ロールバックできなくなることを理解しました'),
    );
    expect(within(dialog).getByRole('button', { name: '確定する' })).toBeDisabled();
    await userEvent.click(
      within(dialog).getByLabelText(
        '旧データを残したまま確定し、あとからクリーンアップできなくなることを理解しました',
      ),
    );
    await userEvent.click(within(dialog).getByRole('button', { name: '確定する' }));
    await screen.findByText('確定済み');
    expect(client.calls.find((c) => c.action === 'finalize')?.params).toEqual({
      jobId: 7,
      keepLegacyKeys: true,
    });
  });

  it('再実行の最初の呼び出しだけ restart を付け、再実行中のボタンの連打は無視する', async () => {
    let release: () => void = () => undefined;
    const gate = new Promise<void>((r) => {
      release = r;
    });
    const client = fakeClient({
      job: [
        detail({ skipped: 3 }, { converted: 2, skipped: 3 }),
        detail({ status: 'paused' }, { converted: 2, pending: 3 }),
        detail(),
      ],
      requeue: async (params: Record<string, unknown>) => {
        await gate;
        return params.restart === true
          ? { requeued: 2, done: false, job: detail({ status: 'paused' }).job }
          : { requeued: 1, done: true, job: detail({ status: 'paused' }).job };
      },
      run: done(),
    });
    mount(client);
    const button = await screen.findByRole('button', { name: 'スキップ・エラー分を再実行' });
    await userEvent.click(button);
    await userEvent.click(button);
    release();
    await waitFor(() => expect(client.calls.map((c) => c.action)).toContain('run'));
    const requeues = client.calls.filter((c) => c.action === 'requeue');
    expect(requeues.map((c) => c.params.restart)).toEqual([true, false]);
  });

  it('失敗したジョブでも、実行待ちが無ければ「実行を再開」は出さない（ロールバック途中の失敗など）', async () => {
    mount(fakeClient({ job: detail({ status: 'failed' }, { rolled_back: 3 }) }));
    await screen.findByText('失敗');
    expect(screen.queryByRole('button', { name: '実行を再開' })).not.toBeInTheDocument();
  });

  it('確定の続き: 確認なしで finalize を done まで繰り返す', async () => {
    const client = fakeClient({
      job: [
        { ...detail({ status: 'finalized' }, { converted: 3 }), finalizing: true },
        { ...detail({ status: 'finalized' }, { converted: 3 }), finalizing: false },
      ],
      finalize: [
        { done: false, job: detail({ status: 'finalized' }).job },
        { done: true, job: detail({ status: 'finalized' }).job },
      ],
    });
    mount(client);
    await userEvent.click(await screen.findByRole('button', { name: '記録の回収を続ける' }));
    await waitFor(() =>
      expect(client.calls.filter((c) => c.action === 'finalize')).toHaveLength(2),
    );
  });

  it('確定の確認ダイアログは、スキップ分の再実行もできなくなることを伝える', async () => {
    mount(fakeClient({ job: detail({}, { converted: 3, skipped: 1 }) }));
    await userEvent.click(await screen.findByRole('button', { name: '変換を確定する' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/スキップ・エラー分の再実行/)).toBeInTheDocument();
  });
});
