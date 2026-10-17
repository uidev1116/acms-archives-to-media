import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DefinitionsProvider } from '../../definitions';
import type { Definitions, Job, JobDetail } from '../../types';
import { defaultFormState, toJobOptions } from '../settings/options';
import { JobOverview } from './JobOverview';

const defs: Definitions = {
  mediaUnitTypes: ['media'],
  sourceUnitTypes: [],
  fieldKeys: { image: ['hero'], file: ['doc'] },
};

function detail(
  over: Partial<Job> = {},
  counts: Record<string, number> = { converted: 5, skipped: 1, error: 2 },
): JobDetail {
  return {
    job: {
      id: 12,
      status: 'completed',
      options: toJobOptions(defaultFormState(defs)),
      total: 8,
      converted: 5,
      skipped: 1,
      error: 2,
      message: null,
      userId: 1,
      blogId: 2,
      createdAt: '2026-10-18 05:00:00',
      startedAt: '2026-10-18 05:01:00',
      finishedAt: '2026-10-18 05:02:00',
      ...over,
    },
    summary: [],
    counts,
  };
}

function setup(
  d: JobDetail,
  handlers: Partial<{ onRollback: () => void; onCsv: () => void; onRequeue: () => void }> = {},
) {
  const props = { onRollback: vi.fn(), onCsv: vi.fn(), onRequeue: vi.fn(), ...handlers };
  render(
    <DefinitionsProvider value={defs}>
      <JobOverview detail={d} {...props} />
    </DefinitionsProvider>,
  );
  return props;
}

describe('JobOverview', () => {
  it('状態と件数を表示する', () => {
    setup(detail());
    expect(screen.getByText('完了')).toBeInTheDocument();
    expect(screen.getByText('変換済み')).toBeInTheDocument();
    expect(screen.getByText('5')).toBeInTheDocument();
    expect(screen.getByText('2026-10-18 05:02:00')).toBeInTheDocument();
  });

  it('完了したジョブは CSV とロールバックができる', async () => {
    const p = setup(detail());
    await userEvent.click(screen.getByRole('button', { name: '結果を CSV でダウンロード' }));
    await userEvent.click(screen.getByRole('button', { name: 'ロールバック' }));
    expect(p.onCsv).toHaveBeenCalled();
    expect(p.onRollback).toHaveBeenCalled();
  });

  it('スキップ・エラーがあれば再実行できる', async () => {
    const p = setup(detail());
    await userEvent.click(screen.getByRole('button', { name: 'スキップ・エラー分を再実行' }));
    expect(p.onRequeue).toHaveBeenCalled();
  });

  it('スキップ・エラーが無ければ再実行ボタンは出ない', () => {
    setup(detail({}, { converted: 8 }));
    expect(
      screen.queryByRole('button', { name: 'スキップ・エラー分を再実行' }),
    ).not.toBeInTheDocument();
  });

  it('ロールバック済みのジョブはロールバックできない', () => {
    setup(detail({ status: 'rolled_back' }, { rolled_back: 5 }));
    expect(screen.queryByRole('button', { name: 'ロールバック' })).not.toBeInTheDocument();
  });

  it('失敗メッセージを表示する', () => {
    setup(detail({ status: 'failed', message: 'DB 接続が切れました' }));
    expect(screen.getByRole('alert')).toHaveTextContent('DB 接続が切れました');
  });

  it('フィールドを対象にしたジョブにはテンプレートのヒントを出す', () => {
    setup(detail());
    expect(screen.getByText('テンプレート移行のヒント')).toBeInTheDocument();
  });

  it('フィールドを対象にしていないジョブにはヒントを出さない', () => {
    const opts = toJobOptions({ ...defaultFormState(defs), targets: ['unit_image'] });
    setup(detail({ options: opts }));
    expect(screen.queryByText('テンプレート移行のヒント')).not.toBeInTheDocument();
  });
});
