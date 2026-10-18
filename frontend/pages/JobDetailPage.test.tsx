import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { DefinitionsProvider } from '../definitions';
import { defaultFormState, toJobOptions } from '../features/settings/options';
import { fakeClient, renderWithApi } from '../test/helpers';
import type { Definitions, JobDetail } from '../types';
import { JobDetailPage } from './JobDetailPage';

const defs: Definitions = {
  mediaUnitTypes: ['media'],
  sourceUnitTypes: [],
  fieldKeys: { image: [], file: [] },
};
const detail: JobDetail = {
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
  },
  summary: [],
  counts: { converted: 3 },
};

function mount(
  client = fakeClient({ job: detail, items: { items: [], total: 0 }, delete: { deleted: true } }),
) {
  renderWithApi(
    <DefinitionsProvider value={defs}>
      <JobDetailPage jobId={7} />
    </DefinitionsProvider>,
    client,
  );
  return client;
}

describe('JobDetailPage', () => {
  it('概要・アイテム・ログのタブを切り替えられる', async () => {
    const client = mount();
    expect(await screen.findByRole('heading', { name: 'ジョブ #7' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: '概要', selected: true })).toBeInTheDocument();
    expect(await screen.findByText('変換済み')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('tab', { name: 'アイテム' }));
    expect(await screen.findByText('該当するアイテムはありません。')).toBeInTheDocument();
    expect(client.calls.find((c) => c.action === 'items')?.params).not.toHaveProperty('status');

    await userEvent.click(screen.getByRole('tab', { name: 'ログ' }));
    await waitFor(() => expect(client.calls.filter((c) => c.action === 'items')).toHaveLength(2));
    expect(client.calls.filter((c) => c.action === 'items')[1]?.params).toMatchObject({
      status: 'skipped',
    });
  });

  it('一覧へ戻るリンクがある', async () => {
    mount();
    expect(await screen.findByRole('link', { name: '過去の実行に戻る' })).toHaveAttribute(
      'href',
      '#/jobs',
    );
  });

  it('削除すると一覧へ移動する', async () => {
    mount();
    await userEvent.click(await screen.findByRole('button', { name: 'ジョブを削除' }));
    await userEvent.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: '削除する' }),
    );
    await waitFor(() => expect(window.location.hash).toBe('#/jobs'));
  });
});
