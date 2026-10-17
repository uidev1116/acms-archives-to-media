import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/client';
import { defaultFormState, toJobOptions } from '../features/settings/options';
import { fakeClient, renderWithApi } from '../test/helpers';
import type { Job } from '../types';
import { JobList } from './JobList';

function job(id: number, over: Partial<Job> = {}): Job {
  return {
    id,
    status: 'completed',
    options: toJobOptions(
      defaultFormState({
        mediaUnitTypes: ['media'],
        sourceUnitTypes: [],
        fieldKeys: { image: [], file: [] },
      }),
    ),
    total: 10,
    converted: 8,
    skipped: 1,
    error: 1,
    message: null,
    userId: 1,
    blogId: 2,
    createdAt: '2026-10-18 05:00:00',
    startedAt: null,
    finishedAt: null,
    ...over,
  };
}

describe('JobList', () => {
  it('過去のジョブを新しい順に表示し、詳細へのリンクを持つ', async () => {
    const client = fakeClient({
      jobs: { jobs: [job(5), job(4, { status: 'rolled_back' })], total: 2 },
    });
    renderWithApi(<JobList />, client);

    const link = await screen.findByRole('link', { name: '#5' });
    expect(link).toHaveAttribute('href', '#/jobs/5');
    expect(screen.getByText('ロールバック済み')).toBeInTheDocument();
    expect(client.calls[0]).toEqual({ action: 'jobs', params: { page: 1 } });
  });

  it('ジョブが無ければ案内を出す', async () => {
    renderWithApi(<JobList />, fakeClient({ jobs: { jobs: [], total: 0 } }));
    expect(await screen.findByText('まだ変換ジョブはありません。')).toBeInTheDocument();
  });

  it('ページを移動できる', async () => {
    const client = fakeClient({
      jobs: [
        { jobs: [job(30)], total: 45 },
        { jobs: [job(10)], total: 45 },
        { jobs: [job(30)], total: 45 },
      ],
    });
    renderWithApi(<JobList />, client);
    await screen.findByRole('link', { name: '#30' });
    await userEvent.click(screen.getByRole('button', { name: '次のページ' }));
    expect(await screen.findByRole('link', { name: '#10' })).toBeInTheDocument();
    expect(client.calls.at(-1)?.params).toEqual({ page: 2 });
    await userEvent.click(screen.getByRole('button', { name: '前のページ' }));
    expect(await screen.findByRole('link', { name: '#30' })).toBeInTheDocument();
  });

  it('取得に失敗したらエラーを表示する', async () => {
    renderWithApi(
      <JobList />,
      fakeClient({ jobs: new ApiError('権限がありません', 403, 'forbidden') }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('権限がありません');
  });
});
