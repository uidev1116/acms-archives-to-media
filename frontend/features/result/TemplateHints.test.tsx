import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { JobOptions } from '../../types';
import { TemplateHints } from './TemplateHints';

const field: JobOptions['field'] = {
  mode: 'suffix',
  suffix: '_media',
  custom: {},
  includeKeys: [],
  excludeKeys: [],
  appendToGroup: true,
  removeLegacyKeys: false,
};

describe('TemplateHints', () => {
  it('フィールドごとに旧変数と新変数の対応を表示する', () => {
    render(<TemplateHints fieldKeys={{ image: ['hero'], file: ['doc'] }} field={field} />);
    expect(screen.getByText('hero')).toBeInTheDocument();
    expect(
      screen.getByText(/<img src="\{hero_media@path\}" alt="\{hero_media@alt\}">/),
    ).toBeInTheDocument();
    expect(screen.getByText(/<a href="\{doc_media@path\}">/)).toBeInTheDocument();
    expect(screen.getByText(/name="hero_media:extension" value="media"/)).toBeInTheDocument();
  });

  it('同名モードでは変数名は変わらない旨を表示する', () => {
    render(
      <TemplateHints
        fieldKeys={{ image: ['hero'], file: [] }}
        field={{ ...field, mode: 'same' }}
      />,
    );
    expect(screen.getByText(/変数名は変わりません/)).toBeInTheDocument();
  });

  it('フィールドが無ければ何も出さない', () => {
    const { container } = render(
      <TemplateHints fieldKeys={{ image: [], file: [] }} field={field} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('コピーボタンでクリップボードに書き込む', async () => {
    const writeText = vi.fn(async (_text: string) => undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    render(<TemplateHints fieldKeys={{ image: ['hero'], file: [] }} field={field} />);
    await userEvent.click(screen.getByRole('button', { name: 'hero の新しい書き方をコピー' }));
    expect(writeText).toHaveBeenCalledTimes(1);
    expect(String(writeText.mock.calls[0]?.[0])).toContain('{hero_media@path}');
    expect(await screen.findByText('コピーしました')).toBeInTheDocument();
  });
});
