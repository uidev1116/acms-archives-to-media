import { describe, expect, it } from 'vitest';
import type { Definitions } from '../../types';
import {
  defaultFormState,
  fromJobOptions,
  parseCustomMap,
  parseList,
  toJobOptions,
  validateForm,
} from './options';

const defs: Definitions = {
  mediaUnitTypes: ['media', 'media_2'],
  sourceUnitTypes: [
    { type: 'image', count: 3 },
    { type: 'image_2', count: 1 },
    { type: 'file', count: 2 },
  ],
  fieldKeys: { image: ['hero'], file: ['doc'] },
};

describe('parseList / parseCustomMap', () => {
  it('カンマ・改行・空白で区切り、空要素と重複を除く', () => {
    expect(parseList(' a, b\nc  ,, a ')).toEqual(['a', 'b', 'c']);
    expect(parseList('')).toEqual([]);
  });

  it('元=先 の行をマップにし、不正な行は invalid に集める', () => {
    expect(parseCustomMap('hero=visual\n doc = file_media ')).toEqual({
      map: { hero: 'visual', doc: 'file_media' },
      invalid: [],
    });
    expect(parseCustomMap('hero\n=x\na=b=c\n\n')).toEqual({
      map: {},
      invalid: ['hero', '=x', 'a=b=c'],
    });
  });
});

describe('defaultFormState', () => {
  it('設計書の決定どおりの既定値（別名モード・リビジョンなし・auto・全対象）', () => {
    const s = defaultFormState(defs);
    expect(s.targets).toEqual(['unit_image', 'unit_file', 'field_image', 'field_file']);
    expect(s.fieldMode).toBe('suffix');
    expect(s.fieldSuffix).toBe('_media');
    expect(s.includeRevisions).toBe(false);
    expect(s.includeChildBlogs).toBe(false);
    expect(s.enlarged).toBe('auto');
    expect(s.appendToGroup).toBe(true);
    expect(s.fillAltOnNew).toBe(true);
    expect(s.unitTypeMap).toEqual({ image: 'media', image_2: 'media', file: 'media' });
    expect(s.entryStatus).toEqual(['open', 'close', 'draft']);
  });

  it('定義が空でもメディアユニット media を既定にする', () => {
    const s = defaultFormState({
      mediaUnitTypes: [],
      sourceUnitTypes: [{ type: 'image', count: 1 }],
      fieldKeys: { image: [], file: [] },
    });
    expect(s.unitTypeMap).toEqual({ image: 'media' });
  });
});

describe('toJobOptions / fromJobOptions', () => {
  it('フォームの状態を API の形式に変換できる', () => {
    const s = {
      ...defaultFormState(defs),
      targets: ['unit_image' as const],
      fieldMode: 'custom' as const,
      customText: 'hero=visual',
      includeKeysText: 'hero, doc',
      excludeKeysText: 'og',
      tagsText: 'archives-import, a2m',
      enlarged: 'yes' as const,
      chunkSize: 10,
    };
    const o = toJobOptions(s);
    expect(o.targets).toEqual(['unit_image']);
    expect(o.field).toEqual({
      mode: 'custom',
      suffix: '_media',
      custom: { hero: 'visual' },
      includeKeys: ['hero', 'doc'],
      excludeKeys: ['og'],
      appendToGroup: true,
      removeLegacyKeys: false,
    });
    expect(o.media).toEqual({
      fillAltOnNew: true,
      enlarged: 'yes',
      tags: ['archives-import', 'a2m'],
    });
    expect(o.chunkSize).toBe(10);
  });

  it('同名モードでは旧キー削除フラグを送る', () => {
    const s = { ...defaultFormState(defs), fieldMode: 'same' as const, removeLegacyKeys: true };
    expect(toJobOptions(s).field.removeLegacyKeys).toBe(true);
    const t = { ...s, fieldMode: 'suffix' as const };
    expect(toJobOptions(t).field.removeLegacyKeys).toBe(false);
  });

  it('API のオプションをフォームの状態へ戻せる（往復）', () => {
    const s = {
      ...defaultFormState(defs),
      fieldMode: 'custom' as const,
      customText: 'hero=visual',
      includeKeysText: 'hero',
      tagsText: 'x',
      includeRevisions: true,
    };
    const back = fromJobOptions(toJobOptions(s));
    expect(toJobOptions(back)).toEqual(toJobOptions(s));
    expect(back.customText).toBe('hero=visual');
  });
});

describe('validateForm', () => {
  it('問題が無ければ空', () => {
    expect(validateForm(defaultFormState(defs))).toEqual([]);
  });

  it('対象が 0 件・接尾辞が不正・カスタム行が不正・タグが不正を検出する', () => {
    const s = {
      ...defaultFormState(defs),
      targets: [],
      fieldSuffix: 'a b',
      fieldMode: 'custom' as const,
      customText: 'oops',
      tagsText: 'a;b',
    };
    const errors = validateForm(s);
    expect(errors).toContain('変換対象を 1 つ以上選んでください。');
    expect(errors.some((e) => e.includes('接尾辞'))).toBe(true);
    expect(errors.some((e) => e.includes('oops'))).toBe(true);
    expect(errors.some((e) => e.includes('タグ'))).toBe(true);
  });

  it('所属が 0 件・エントリー状態が 0 件も検出する', () => {
    const s = { ...defaultFormState(defs), owners: [], entryStatus: [] };
    const errors = validateForm(s);
    expect(errors.some((e) => e.includes('所属'))).toBe(true);
    expect(errors.some((e) => e.includes('エントリーの状態'))).toBe(true);
  });
});
