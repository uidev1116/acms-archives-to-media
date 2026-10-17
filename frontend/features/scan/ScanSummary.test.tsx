import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
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

  it('候補が無いときはその旨を表示する', () => {
    render(<ScanSummary summary={[]} />);
    expect(screen.getByText('変換できる候補は見つかりませんでした。')).toBeInTheDocument();
  });
});
