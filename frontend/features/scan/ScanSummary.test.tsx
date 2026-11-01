import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { SummaryRow } from '../../types';
import { ScanSummary } from './ScanSummary';

const summary: SummaryRow[] = [
  { target: 'unit_image', status: 'pending', preview: 'new', reason: '', count: 5 },
  { target: 'unit_image', status: 'pending', preview: 'reuse', reason: '', count: 2 },
  {
    target: 'unit_image',
    status: 'skipped',
    preview: 'skip',
    reason: 'SOURCE_NOT_FOUND',
    count: 1,
  },
  { target: 'field_file', status: 'pending', preview: 'mixed', reason: '', count: 3 },
  { target: 'field_file', status: 'excluded', preview: 'new', reason: '', count: 4 },
];

describe('ScanSummary', () => {
  it('種類ごとに 新規・再利用・混在・スキップ・除外 の件数を集計する', () => {
    render(<ScanSummary summary={summary} />);
    const imageRow = screen.getByRole('row', { name: /画像ユニット/ });
    const cells = within(imageRow)
      .getAllByRole('cell')
      .map((c) => c.textContent);
    // 合計, 新規, 再利用, 混在, スキップ, 除外
    expect(cells).toEqual(['8', '5', '2', '0', '1', '0']);

    const fileRow = screen.getByRole('row', { name: /ファイルフィールド/ });
    expect(
      within(fileRow)
        .getAllByRole('cell')
        .map((c) => c.textContent),
    ).toEqual(['7', '0', '0', '3', '0', '4']);
  });

  it('スキップの理由を一覧にする', () => {
    render(<ScanSummary summary={summary} />);
    expect(screen.getByText('元ファイルが見つかりません')).toBeInTheDocument();
    expect(screen.getByText('SOURCE_NOT_FOUND')).toBeInTheDocument();
  });

  it('理由を押すと絞り込みを依頼し、選択中は押された状態になる', async () => {
    const onPick = vi.fn();
    render(<ScanSummary summary={summary} activeReason="SOURCE_NOT_FOUND" onPickReason={onPick} />);
    const button = screen.getByRole('button', { name: /元ファイルが見つかりません/ });
    expect(button).toHaveAttribute('aria-pressed', 'true');
    await userEvent.click(button);
    expect(onPick).toHaveBeenCalledWith('SOURCE_NOT_FOUND');
  });

  it('候補が無いときはその旨を表示する', () => {
    render(<ScanSummary summary={[]} />);
    expect(screen.getByText('変換できる候補は見つかりませんでした。')).toBeInTheDocument();
  });

  it('新規か再利用かを判定していない候補があれば、実行時に判定することを知らせる', () => {
    render(
      <ScanSummary
        summary={[
          { target: 'field_image', status: 'pending', preview: 'new', reason: '', count: 2 },
          { target: 'field_image', status: 'pending', preview: 'unknown', reason: '', count: 7 },
        ]}
      />,
    );
    expect(screen.getByText(/7 件は.*実行時に判定します/)).toBeInTheDocument();
    const row = screen.getByRole('row', { name: /画像フィールド/ });
    expect(
      within(row)
        .getAllByRole('cell')
        .map((c) => c.textContent),
    ).toEqual(['9', '2', '0', '0', '7', '0', '0']); // 合計, 新規, 再利用, 混在, 未判定, スキップ, 除外
  });

  it('判定していない候補が無ければ知らせない', () => {
    render(<ScanSummary summary={summary} />);
    expect(screen.queryByText(/実行時に判定します/)).not.toBeInTheDocument();
  });
});
