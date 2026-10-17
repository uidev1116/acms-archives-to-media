import { describe, expect, it } from 'vitest';
import type { JobOptions } from '../../types';
import { destName } from './destName';

const base: JobOptions['field'] = {
  mode: 'suffix',
  suffix: '_media',
  custom: { hero: 'visual' },
  includeKeys: [],
  excludeKeys: [],
  appendToGroup: true,
  removeLegacyKeys: false,
};

describe('destName', () => {
  it('別名モードは接尾辞を付ける', () => {
    expect(destName('hero', base)).toBe('hero_media');
  });
  it('同名モードはそのまま', () => {
    expect(destName('hero', { ...base, mode: 'same' })).toBe('hero');
  });
  it('カスタムは指定名、無ければ接尾辞', () => {
    expect(destName('hero', { ...base, mode: 'custom' })).toBe('visual');
    expect(destName('doc', { ...base, mode: 'custom' })).toBe('doc_media');
  });
});
