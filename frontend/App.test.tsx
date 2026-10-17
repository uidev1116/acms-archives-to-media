import { act, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ApiError } from './api/client';
import { App } from './App';
import { fakeClient, renderWithApi } from './test/helpers';
import type { Definitions } from './types';

const defs: Definitions = {
  mediaUnitTypes: ['media'],
  sourceUnitTypes: [],
  fieldKeys: { image: [], file: [] },
};

afterEach(() => {
  window.location.hash = '';
});

describe('App', () => {
  it('定義を読み込んで、ウィザードを表示する', async () => {
    renderWithApi(<App />, fakeClient({ definitions: defs }));
    expect(await screen.findByRole('button', { name: 'スキャンして確認へ' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: '新しい変換' })).toHaveAttribute('href', '#/');
    expect(screen.getByRole('link', { name: '過去の実行' })).toHaveAttribute('href', '#/jobs');
  });

  it('hash に応じて一覧とジョブ詳細に切り替わる', async () => {
    window.location.hash = '#/jobs';
    renderWithApi(
      <App />,
      fakeClient({
        definitions: defs,
        jobs: { jobs: [], total: 0 },
        job: new ApiError('x', 404, 'not_found'),
        items: { items: [], total: 0 },
      }),
    );
    expect(await screen.findByText('まだ変換ジョブはありません。')).toBeInTheDocument();

    await act(async () => {
      window.location.hash = '#/jobs/3';
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    });
    expect(await screen.findByRole('heading', { name: 'ジョブ #3' })).toBeInTheDocument();
  });

  it('定義の取得に失敗したらエラーを表示する', async () => {
    renderWithApi(
      <App />,
      fakeClient({ definitions: new ApiError('管理者権限が必要です。', 403, 'forbidden') }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent('管理者権限が必要です。');
  });
});
