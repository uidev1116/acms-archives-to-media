import type {
  Definitions,
  Enlarged,
  EntryStatus,
  FieldMode,
  JobOptions,
  OwnerType,
  TargetType,
} from '../../types';

/** 設定画面のフォームの状態（テキスト入力は文字列のまま持つ） */
export interface FormState {
  targets: TargetType[];
  unitTypeMap: Record<string, string>;
  fieldMode: FieldMode;
  fieldSuffix: string;
  customText: string;
  includeKeysText: string;
  excludeKeysText: string;
  appendToGroup: boolean;
  removeLegacyKeys: boolean;
  owners: OwnerType[];
  includeRevisions: boolean;
  includeChildBlogs: boolean;
  entryStatus: EntryStatus[];
  fillAltOnNew: boolean;
  enlarged: Enlarged;
  tagsText: string;
  chunkSize: number;
}

export const ALL_TARGETS: TargetType[] = ['unit_image', 'unit_file', 'field_image', 'field_file'];
export const ALL_OWNERS: OwnerType[] = ['entry', 'category', 'blog', 'user', 'module', 'unit'];
export const ALL_ENTRY_STATUS: EntryStatus[] = ['open', 'close', 'draft', 'trash'];

const FIELD_NAME = /^[A-Za-z0-9_-]{1,100}$/;
const SUFFIX = /^[A-Za-z0-9_-]{1,32}$/;

export function parseList(text: string): string[] {
  const seen = new Set<string>();
  for (const part of text.split(/[\s,]+/)) {
    if (part !== '') seen.add(part);
  }
  return [...seen];
}

export function parseCustomMap(text: string): { map: Record<string, string>; invalid: string[] } {
  const map: Record<string, string> = {};
  const invalid: string[] = [];
  for (const raw of text.split('\n')) {
    const line = raw.trim();
    if (line === '') continue;
    const parts = line.split('=').map((p) => p.trim());
    const [from, to] = parts;
    if (parts.length !== 2 || !from || !to || !FIELD_NAME.test(from) || !FIELD_NAME.test(to)) {
      invalid.push(line);
      continue;
    }
    map[from] = to;
  }
  return { map, invalid };
}

export function defaultFormState(defs: Definitions): FormState {
  const mediaDefault = defs.mediaUnitTypes[0] ?? 'media';
  const unitTypeMap: Record<string, string> = {};
  for (const { type } of defs.sourceUnitTypes) {
    unitTypeMap[type] = mediaDefault;
  }
  return {
    targets: [...ALL_TARGETS],
    unitTypeMap,
    fieldMode: 'suffix',
    fieldSuffix: '_media',
    customText: '',
    includeKeysText: '',
    excludeKeysText: '',
    appendToGroup: true,
    removeLegacyKeys: true,
    owners: [...ALL_OWNERS],
    includeRevisions: false,
    includeChildBlogs: false,
    entryStatus: ['open', 'close', 'draft'],
    fillAltOnNew: true,
    enlarged: 'auto',
    tagsText: 'archives-import',
    chunkSize: 20,
  };
}

export function toJobOptions(s: FormState): JobOptions {
  return {
    targets: s.targets,
    unitTypeMap: s.unitTypeMap,
    field: {
      mode: s.fieldMode,
      suffix: s.fieldSuffix,
      custom: parseCustomMap(s.customText).map,
      includeKeys: parseList(s.includeKeysText),
      excludeKeys: parseList(s.excludeKeysText),
      appendToGroup: s.appendToGroup,
      removeLegacyKeys: s.fieldMode === 'same' && s.removeLegacyKeys,
    },
    owners: s.owners,
    includeRevisions: s.includeRevisions,
    includeChildBlogs: s.includeChildBlogs,
    entryStatus: s.entryStatus,
    media: { fillAltOnNew: s.fillAltOnNew, enlarged: s.enlarged, tags: parseList(s.tagsText) },
    chunkSize: s.chunkSize,
  };
}

export function fromJobOptions(o: JobOptions): FormState {
  return {
    targets: o.targets,
    unitTypeMap: o.unitTypeMap,
    fieldMode: o.field.mode,
    fieldSuffix: o.field.suffix,
    customText: Object.entries(o.field.custom)
      .map(([from, to]) => `${from}=${to}`)
      .join('\n'),
    includeKeysText: o.field.includeKeys.join(', '),
    excludeKeysText: o.field.excludeKeys.join(', '),
    appendToGroup: o.field.appendToGroup,
    removeLegacyKeys: o.field.removeLegacyKeys,
    owners: o.owners,
    includeRevisions: o.includeRevisions,
    includeChildBlogs: o.includeChildBlogs,
    entryStatus: o.entryStatus,
    fillAltOnNew: o.media.fillAltOnNew,
    enlarged: o.media.enlarged,
    tagsText: o.media.tags.join(', '),
    chunkSize: o.chunkSize,
  };
}

export function validateForm(s: FormState): string[] {
  const errors: string[] = [];
  if (s.targets.length === 0) errors.push('変換対象を 1 つ以上選んでください。');
  if (!SUFFIX.test(s.fieldSuffix)) {
    errors.push(
      'フィールド名の接尾辞は半角英数字・ハイフン・アンダースコアで 32 文字以内にしてください。',
    );
  }
  if (s.fieldMode === 'custom') {
    for (const line of parseCustomMap(s.customText).invalid) {
      errors.push(
        `フィールド名の対応が不正です: ${line}（「元=先」の形式で、半角英数字・ハイフン・アンダースコアを使ってください）`,
      );
    }
  }
  for (const key of [...parseList(s.includeKeysText), ...parseList(s.excludeKeysText)]) {
    if (!FIELD_NAME.test(key)) errors.push(`フィールド名が不正です: ${key}`);
  }
  for (const tag of parseList(s.tagsText)) {
    if (tag.length > 64 || /[,;]/.test(tag)) errors.push(`タグが不正です: ${tag}`);
  }
  if (s.owners.length === 0) errors.push('フィールドの所属を 1 つ以上選んでください。');
  if (s.entryStatus.length === 0) errors.push('対象のエントリーの状態を 1 つ以上選んでください。');
  return errors;
}
