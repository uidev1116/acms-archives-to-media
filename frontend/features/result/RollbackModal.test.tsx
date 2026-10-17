import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { RollbackModal } from './RollbackModal';

describe('RollbackModal', () => {
  it('件数を表示し、メディア削除オプションは既定でオフ', async () => {
    const onConfirm = vi.fn();
    render(<RollbackModal isOpen count={12} onClose={vi.fn()} onConfirm={onConfirm} />);
    expect(screen.getByText(/12 件を変換前の状態に戻します/)).toBeInTheDocument();
    expect(
      screen.getByLabelText('このジョブで新規作成したメディアのうち、参照が無いものを削除する'),
    ).not.toBeChecked();

    await userEvent.click(screen.getByRole('button', { name: 'ロールバックする' }));
    expect(onConfirm).toHaveBeenCalledWith(false);
  });

  it('メディアの削除を選べる', async () => {
    const onConfirm = vi.fn();
    render(<RollbackModal isOpen count={1} onClose={vi.fn()} onConfirm={onConfirm} />);
    await userEvent.click(
      screen.getByLabelText('このジョブで新規作成したメディアのうち、参照が無いものを削除する'),
    );
    await userEvent.click(screen.getByRole('button', { name: 'ロールバックする' }));
    expect(onConfirm).toHaveBeenCalledWith(true);
  });

  it('キャンセルで閉じる', async () => {
    const onClose = vi.fn();
    render(<RollbackModal isOpen count={1} onClose={onClose} onConfirm={vi.fn()} />);
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }));
    expect(onClose).toHaveBeenCalled();
  });

  it('閉じているときは何も出さない', () => {
    render(<RollbackModal isOpen={false} count={1} onClose={vi.fn()} onConfirm={vi.fn()} />);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
