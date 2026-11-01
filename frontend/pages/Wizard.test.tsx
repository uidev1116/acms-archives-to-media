import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/client';
import { DefinitionsProvider } from '../definitions';
import { defaultFormState, toJobOptions } from '../features/settings/options';
import { fakeClient, renderWithApi } from '../test/helpers';
import type { ChunkResult, Definitions, Job, JobDetail } from '../types';
import { Wizard } from './Wizard';

const defs: Definitions = {
  mediaUnitTypes: ['media'],
  sourceUnitTypes: [{ type: 'image', count: 2 }],
  fieldKeys: { image: ['hero'], file: [] },
};

function job(over: Partial<Job> = {}): Job {
  return {
    id: 21,
    status: 'draft',
    options: toJobOptions(defaultFormState(defs)),
    total: 0,
    converted: 0,
    skipped: 0,
    error: 0,
    message: null,
    userId: 1,
    blogId: 2,
    createdAt: '2026-10-18 05:00:00',
    startedAt: null,
    finishedAt: null,
    ...over,
  };
}

const scanned: JobDetail = {
  job: job({ status: 'scanned', total: 2 }),
  summary: [{ target: 'unit_image', status: 'pending', preview: 'new', reason: '', count: 2 }],
  counts: { pending: 2 },
};
const completed: JobDetail = {
  job: job({ status: 'completed', total: 2, converted: 2 }),
  summary: [],
  counts: { converted: 2 },
};
const finished: ChunkResult = {
  status: 'completed',
  processed: 2,
  converted: 2,
  skipped: 0,
  error: 0,
  done: true,
  remaining: 0,
  log: [],
};

function mount(client: ReturnType<typeof fakeClient>) {
  return renderWithApi(
    <DefinitionsProvider value={defs}>
      <Wizard />
    </DefinitionsProvider>,
    client,
  );
}

async function goToScan() {
  await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
  // 既定は同名モードなので、確認ダイアログに同意して進む
  const dialog = screen.queryByRole('dialog');
  if (dialog !== null) {
    await userEvent.click(within(dialog).getByRole('checkbox'));
    await userEvent.click(within(dialog).getByRole('button', { name: '続ける' }));
  }
}

describe('Wizard', () => {
  it('設定 → スキャン → 実行 → 結果まで通しで進める', async () => {
    const client = fakeClient({
      create: { job: job() },
      scan: { status: 'scanned', scanned: 2, total: 2, done: true },
      job: [scanned, completed],
      items: { items: [], total: 0 },
      run: finished,
    });
    mount(client);

    expect(screen.getByRole('listitem', { current: 'step' })).toHaveTextContent('設定');
    await goToScan();

    expect(await screen.findByText(/2 件の候補が見つかりました/)).toBeInTheDocument();
    expect(screen.getByRole('listitem', { current: 'step' })).toHaveTextContent('スキャンと確認');
    const created = client.calls.find((c) => c.action === 'create');
    expect(created?.params.options).toMatchObject({
      field: { mode: 'same' },
      includeRevisions: false,
    });

    await userEvent.click(screen.getByRole('button', { name: '変換の実行へ' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByLabelText('DB のバックアップを取りました'));
    await userEvent.click(within(dialog).getByRole('button', { name: '変換を開始する' }));

    expect(await screen.findByRole('heading', { name: '4. 結果' })).toBeInTheDocument();
    expect(await screen.findByText('完了')).toBeInTheDocument();
    expect(client.calls.find((c) => c.action === 'run')?.params).toEqual({ jobId: 21 });
    expect(screen.getByRole('link', { name: '過去の実行を見る' })).toHaveAttribute(
      'href',
      '#/jobs',
    );
  });

  it('作成に失敗したら設定画面にエラーを出して留まる', async () => {
    const client = fakeClient({ create: new ApiError('管理者権限が必要です。', 403, 'forbidden') });
    mount(client);
    await goToScan();
    expect(await screen.findByRole('alert')).toHaveTextContent('管理者権限が必要です。');
    // 確認ダイアログが閉じ終わる（背景の aria-hidden が外れる）のを待つ
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'スキャンして確認へ' })).toBeEnabled(),
    );
  });

  it('スキャン画面から設定に戻って再送信すると、ジョブを更新して再スキャンする', async () => {
    const client = fakeClient({
      create: { job: job() },
      update: { job: job() },
      scan: { status: 'scanned', scanned: 2, total: 2, done: true },
      job: scanned,
      items: { items: [], total: 0 },
    });
    mount(client);
    await goToScan();
    await screen.findByText(/2 件の候補が見つかりました/);

    await userEvent.click(screen.getByRole('button', { name: '設定に戻る' }));
    expect(await screen.findByRole('button', { name: 'スキャンして確認へ' })).toBeInTheDocument();
    await goToScan();

    await waitFor(() => expect(client.calls.some((c) => c.action === 'update')).toBe(true));
    expect(client.calls.filter((c) => c.action === 'create')).toHaveLength(1);
    expect(client.calls.find((c) => c.action === 'update')?.params).toMatchObject({ jobId: 21 });
    expect(await screen.findByText(/2 件の候補が見つかりました/)).toBeInTheDocument();
  });

  it('結果画面から新しい変換を始め直せる', async () => {
    const client = fakeClient({
      create: { job: job() },
      scan: { status: 'scanned', scanned: 2, total: 2, done: true },
      job: [scanned, completed],
      items: { items: [], total: 0 },
      run: finished,
    });
    mount(client);
    await goToScan();
    await userEvent.click(await screen.findByRole('button', { name: '変換の実行へ' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByLabelText('DB のバックアップを取りました'));
    await userEvent.click(within(dialog).getByRole('button', { name: '変換を開始する' }));
    await screen.findByRole('heading', { name: '4. 結果' });

    await userEvent.click(screen.getByRole('button', { name: '新しい変換を始める' }));
    expect(await screen.findByRole('button', { name: 'スキャンして確認へ' })).toBeInTheDocument();
  });
});
