import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { Definitions, JobOptions } from '../../types';
import { defaultFormState } from './options';
import type { FormState } from './options';
import { SettingsStep } from './SettingsStep';

const defs: Definitions = {
  mediaUnitTypes: ['media', 'media_2'],
  sourceUnitTypes: [
    { type: 'image', count: 3 },
    { type: 'file', count: 2 },
  ],
  fieldKeys: { image: ['hero'], file: ['doc'] },
};

function Harness({
  onSubmit,
  initial,
  busy,
}: {
  onSubmit: (o: JobOptions) => void;
  initial?: Partial<FormState>;
  busy?: boolean;
}) {
  const [value, setValue] = useState<FormState>({ ...defaultFormState(defs), ...initial });
  return <SettingsStep definitions={defs} value={value} onChange={setValue} onSubmit={onSubmit} busy={busy} />;
}

describe('SettingsStep', () => {
  it('テンプレート未更新の再保存についての注意を常に表示する', () => {
    render(<Harness onSubmit={vi.fn()} />);
    expect(screen.getByText(/再保存すると/)).toBeInTheDocument();
  });

  it('既定のまま送信すると、別名モードのオプションで onSubmit される', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} />);

    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    const o = onSubmit.mock.calls[0]?.[0] as JobOptions;
    expect(o.field.mode).toBe('suffix');
    expect(o.field.suffix).toBe('_media');
    expect(o.includeRevisions).toBe(false);
    expect(o.media.enlarged).toBe('auto');
    expect(o.unitTypeMap).toEqual({ image: 'media', file: 'media' });
  });

  it('変換対象のチェックを外すと、そのユニットのマッピングが隠れる', async () => {
    render(<Harness onSubmit={vi.fn()} />);
    expect(screen.getByLabelText('image の変換先ユニット')).toBeInTheDocument();

    await userEvent.click(screen.getByLabelText('画像ユニット'));
    expect(screen.queryByLabelText('image の変換先ユニット')).not.toBeInTheDocument();
    expect(screen.getByLabelText('file の変換先ユニット')).toBeInTheDocument();
  });

  it('ユニット種類ごとにメディアユニットの種類を選べる', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} />);

    await userEvent.selectOptions(screen.getByLabelText('image の変換先ユニット'), 'media_2');
    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));

    expect((onSubmit.mock.calls[0]?.[0] as JobOptions).unitTypeMap).toEqual({ image: 'media_2', file: 'media' });
  });

  it('ユニットが無いサイトではその旨を表示する', () => {
    const empty: Definitions = { mediaUnitTypes: ['media'], sourceUnitTypes: [], fieldKeys: { image: [], file: [] } };
    render(<SettingsStep definitions={empty} value={defaultFormState(empty)} onChange={vi.fn()} onSubmit={vi.fn()} />);
    expect(screen.getByText(/変換できる画像・ファイルユニットはありません/)).toBeInTheDocument();
  });

  it('接尾辞を変えられる', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} />);
    const input = screen.getByLabelText('接尾辞');
    await userEvent.clear(input);
    await userEvent.type(input, '_m');
    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
    expect((onSubmit.mock.calls[0]?.[0] as JobOptions).field.suffix).toBe('_m');
  });

  it('カスタムモードでは対応表の入力欄が出る', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} />);
    await userEvent.click(screen.getByLabelText(/^カスタム（フィールド/));
    await userEvent.type(screen.getByLabelText('フィールド名の対応（元=先）'), 'hero=visual');
    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
    const o = onSubmit.mock.calls[0]?.[0] as JobOptions;
    expect(o.field.mode).toBe('custom');
    expect(o.field.custom).toEqual({ hero: 'visual' });
  });

  it('同名モードは警告を出し、確認ダイアログで同意するまで送信しない', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} />);
    await userEvent.click(screen.getByLabelText(/同名/));
    expect(screen.getByText(/テンプレートを同時に差し替える/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
    expect(onSubmit).not.toHaveBeenCalled();
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('同名モードで実行します')).toBeInTheDocument();

    await userEvent.click(within(dialog).getByRole('button', { name: 'キャンセル' }));
    expect(onSubmit).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: '理解して続ける' }));
    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect((onSubmit.mock.calls[0]?.[0] as JobOptions).field.removeLegacyKeys).toBe(true);
  });

  it('入力に問題があるとエラーを表示して送信しない', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} initial={{ targets: [] }} />);
    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent('変換対象を 1 つ以上選んでください。');
  });

  it('リビジョン・子ブログ・グループ追記・alt・拡大表示・タグ・状態・所属を変えられる', async () => {
    const onSubmit = vi.fn();
    render(<Harness onSubmit={onSubmit} />);

    await userEvent.click(screen.getByLabelText('リビジョンも変換する'));
    await userEvent.click(screen.getByLabelText('子ブログも含める'));
    await userEvent.click(screen.getByLabelText('フィールドグループの定義に追記する'));
    await userEvent.click(screen.getByLabelText('新規メディアに alt・キャプションを設定する'));
    await userEvent.selectOptions(screen.getByLabelText('拡大表示'), 'no');
    const tags = screen.getByLabelText('新規メディアに付けるタグ');
    await userEvent.clear(tags);
    await userEvent.type(tags, 'a, b');
    await userEvent.click(screen.getByLabelText('ゴミ箱のエントリー'));
    await userEvent.click(screen.getByLabelText('カテゴリー'));
    await userEvent.type(screen.getByLabelText('対象にするフィールド名（空なら全部）'), 'hero');
    await userEvent.type(screen.getByLabelText('除外するフィールド名'), 'og');

    await userEvent.click(screen.getByRole('button', { name: 'スキャンして確認へ' }));
    const o = onSubmit.mock.calls[0]?.[0] as JobOptions;
    expect(o.includeRevisions).toBe(true);
    expect(o.includeChildBlogs).toBe(true);
    expect(o.field.appendToGroup).toBe(false);
    expect(o.media.fillAltOnNew).toBe(false);
    expect(o.media.enlarged).toBe('no');
    expect(o.media.tags).toEqual(['a', 'b']);
    expect(o.entryStatus).toContain('trash');
    expect(o.owners).not.toContain('category');
    expect(o.field.includeKeys).toEqual(['hero']);
    expect(o.field.excludeKeys).toEqual(['og']);
  });

  it('busy 中は送信ボタンが押せない', () => {
    render(<Harness onSubmit={vi.fn()} busy />);
    expect(screen.getByRole('button', { name: 'スキャンして確認へ' })).toBeDisabled();
  });
});
