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

  it('削除できる（削除を許可した画面だけ）', async () => {
    const onDeleted = vi.fn();
    const client = fakeClient({
      job: detail({ status: 'rolled_back' }, { rolled_back: 3 }),
      delete: { deleted: true },
    });
    mount(client, { allowDelete: true, onDeleted });
    await userEvent.click(await screen.findByRole('button', { name: 'ジョブを削除' }));
    await waitFor(() => expect(onDeleted).toHaveBeenCalled());
  });

  it('削除に失敗したらエラーを表示する', async () => {
    const client = fakeClient({
      job: detail({ status: 'rolled_back' }, { rolled_back: 3 }),
      delete: new ApiError('先にロールバックしてください', 409, 'state'),
    });
    mount(client, { allowDelete: true });
    await userEvent.click(await screen.findByRole('button', { name: 'ジョブを削除' }));
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
});
