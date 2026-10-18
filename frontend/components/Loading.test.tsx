import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Loading } from './Loading';

describe('Loading', () => {
  it('読み込み中の表示をステータスとして出す', () => {
    render(<Loading />);
    expect(screen.getByRole('status', { name: '読み込み中' })).toHaveClass(
      'acms-admin-a2m-loading',
    );
  });
});
