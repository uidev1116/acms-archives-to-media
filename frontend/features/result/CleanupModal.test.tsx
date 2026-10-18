import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { CleanupModal } from './CleanupModal';

describe('CleanupModal', () => {
  it('件数と注意を表示し、テンプレート移行の確認にチェックするまで実行できない', async () => {
    const onConfirm = vi.fn();
    render(<CleanupModal isOpen count={7} onClose={vi.fn()} onConfirm={onConfirm} />);

    expect(screen.getByText(/7 件のフィールドの旧キー/)).toBeInTheDocument();
    expect(screen.getByText(/archives の元ファイルは削除しません/)).toBeInTheDocument();
    const go = screen.getByRole('button', { name: '旧データを削除する' });
    expect(go).toBeDisabled();

    await userEvent.click(screen.getByLabelText('テンプレートの移行は完了しています'));
    expect(go).toBeEnabled();
    await userEvent.click(go);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('キャンセルで閉じる', async () => {
    const onClose = vi.fn();
    render(<CleanupModal isOpen count={1} onClose={onClose} onConfirm={vi.fn()} />);
    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }));
    expect(onClose).toHaveBeenCalled();
  });

  it('閉じているときは何も出さず、開き直すとチェックは外れている', async () => {
    const { rerender } = render(
      <CleanupModal isOpen={false} count={1} onClose={vi.fn()} onConfirm={vi.fn()} />,
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    rerender(<CleanupModal isOpen count={1} onClose={vi.fn()} onConfirm={vi.fn()} />);
    expect(screen.getByLabelText('テンプレートの移行は完了しています')).not.toBeChecked();
  });
});
