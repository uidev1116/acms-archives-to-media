import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/client';
import { fakeClient, renderWithApi } from '../../test/helpers';
import type { JobDetail, JobOptions } from '../../types';
import { defaultFormState, toJobOptions } from '../settings/options';
import { ScanStep } from './ScanStep';

const options: JobOptions = toJobOptions(
  defaultFormState({
    mediaUnitTypes: ['media'],
    sourceUnitTypes: [],
    fieldKeys: { image: [], file: [] },
  }),
);

function detail(pending: number): JobDetail {
  return {
    job: {
      id: 4,
      status: 'scanned',
      options,
      total: pending,
      converted: 0,
      skipped: 0,
      error: 0,
      message: null,
      userId: 1,
      blogId: 2,
      createdAt: '2026-10-18 05:00:00',
      startedAt: null,
      finishedAt: null,
    },
    summary:
      pending > 0
        ? [{ target: 'unit_image', status: 'pending', preview: 'new', reason: '', count: pending }]
        : [],
    counts: pending > 0 ? { pending } : {},
  };
}

const items = { items: [], total: 0 };

describe('ScanStep', () => {
  it('スキャンを最後まで繰り返し、サマリーとアイテム一覧を表示する', async () => {
    const client = fakeClient({
      scan: [
        { status: 'scanning', scanned: 2, total: 2, done: false },
        { status: 'scanned', scanned: 1, total: 3, done: true },
      ],
      job: detail(3),
      items,
    });
    renderWithApi(<ScanStep jobId={4} onBack={vi.fn()} onProceed={vi.fn()} />, client);

    expect(
      await screen.findByText('スキャンが完了しました。3 件の候補が見つかりました。'),
    ).toBeInTheDocument();
    expect(client.calls.filter((c) => c.action === 'scan')).toHaveLength(2);
    expect(screen.getByRole('row', { name: /画像ユニット/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '変換の実行へ' })).toBeEnabled();
  });

  it('スキャン中は進捗を表示する', async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => {
      release = r;
    });
    const client = fakeClient({
      scan: async () => {
        await gate;
        return { status: 'scanned', scanned: 0, total: 0, done: true };
      },
      job: detail(0),
      items,
    });
    renderWithApi(<ScanStep jobId={4} onBack={vi.fn()} onProceed={vi.fn()} />, client);
    expect(await screen.findByText(/スキャン中/)).toBeInTheDocument();
    release();
    expect(await screen.findByText('変換できる候補は見つかりませんでした。')).toBeInTheDocument();
  });

  it('候補が 0 件なら実行へ進めない', async () => {
    const client = fakeClient({
      scan: { status: 'scanned', scanned: 0, total: 0, done: true },
      job: detail(0),
      items,
    });
    renderWithApi(<ScanStep jobId={4} onBack={vi.fn()} onProceed={vi.fn()} />, client);
    await screen.findByText('変換できる候補は見つかりませんでした。');
    expect(screen.getByRole('button', { name: '変換の実行へ' })).toBeDisabled();
  });

  it('失敗したらエラーを表示し、再試行で続きから再開できる', async () => {
    const client = fakeClient({
      scan: [
        new ApiError('通信に失敗しました', 0, 'network'),
        { status: 'scanned', scanned: 1, total: 1, done: true },
      ],
      job: detail(1),
      items,
    });
    renderWithApi(<ScanStep jobId={4} onBack={vi.fn()} onProceed={vi.fn()} />, client);

    expect(await screen.findByRole('alert')).toHaveTextContent('通信に失敗しました');
    await userEvent.click(screen.getByRole('button', { name: '再試行' }));
    expect(await screen.findByText(/1 件の候補が見つかりました/)).toBeInTheDocument();
  });

  it('設定に戻れる', async () => {
    const onBack = vi.fn();
    const client = fakeClient({
      scan: { status: 'scanned', scanned: 0, total: 0, done: true },
      job: detail(0),
      items,
    });
    renderWithApi(<ScanStep jobId={4} onBack={onBack} onProceed={vi.fn()} />, client);
    await screen.findByText('変換できる候補は見つかりませんでした。');
    await userEvent.click(screen.getByRole('button', { name: '設定に戻る' }));
    expect(onBack).toHaveBeenCalled();
  });

  it('実行前の確認ダイアログでバックアップに同意するまで開始できない', async () => {
    const onProceed = vi.fn();
    const client = fakeClient({
      scan: { status: 'scanned', scanned: 3, total: 3, done: true },
      job: detail(3),
      items,
    });
    renderWithApi(<ScanStep jobId={4} onBack={vi.fn()} onProceed={onProceed} />, client);
    await screen.findByText(/3 件の候補が見つかりました/);

    await userEvent.click(screen.getByRole('button', { name: '変換の実行へ' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/3 件を変換します/)).toBeInTheDocument();
    const start = within(dialog).getByRole('button', { name: '変換を開始する' });
    expect(start).toBeDisabled();

    await userEvent.click(within(dialog).getByLabelText('DB のバックアップを取りました'));
    expect(start).toBeEnabled();
    await userEvent.click(start);
    expect(onProceed).toHaveBeenCalledTimes(1);
    expect(onProceed).toHaveBeenCalledWith(3);
  });

  it('除外を変えると集計を取り直す', async () => {
    const client = fakeClient({
      scan: { status: 'scanned', scanned: 1, total: 1, done: true },
      job: [detail(2), detail(1)],
      items: {
        items: [
          {
            id: 1,
            target: 'unit_image',
            table: 'column',
            ownerType: 'entry',
            ownerId: '1',
            sourcePaths: ['a.jpg'],
            preview: 'new',
            mediaIds: [],
            status: 'pending',
            reason: '',
            reasonLabel: '',
            message: null,
            blogId: 2,
          },
        ],
        total: 1,
      },
      exclude: { updated: 1 },
    });
    renderWithApi(<ScanStep jobId={4} onBack={vi.fn()} onProceed={vi.fn()} />, client);
    await screen.findByText('a.jpg');
    await userEvent.click(screen.getByLabelText('アイテム 1 を選択'));
    await userEvent.click(screen.getByRole('button', { name: '選択を除外' }));
    await waitFor(() => expect(client.calls.filter((c) => c.action === 'job')).toHaveLength(2));
  });
});
