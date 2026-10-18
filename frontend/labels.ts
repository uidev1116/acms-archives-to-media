import type { JobStatus, TargetType } from './types';

export const JOB_STATUS_LABEL: Record<JobStatus, string> = {
  draft: '下書き',
  scanning: 'スキャン中',
  scanned: 'スキャン済み',
  running: '実行中',
  paused: '一時停止',
  completed: '完了',
  failed: '失敗',
  rolling_back: 'ロールバック中',
  rolled_back: 'ロールバック済み',
  finalized: '確定済み',
};

export const ITEM_STATUS_LABEL: Record<string, string> = {
  pending: '待機',
  excluded: '除外',
  converted: '変換済み',
  skipped: 'スキップ',
  error: 'エラー',
  rolled_back: 'ロールバック済み',
};

export const TARGET_LABEL: Record<TargetType, string> = {
  unit_image: '画像ユニット',
  unit_file: 'ファイルユニット',
  field_image: '画像フィールド',
  field_file: 'ファイルフィールド',
};

export const PREVIEW_LABEL: Record<string, string> = {
  new: '新規登録',
  reuse: '既存メディアを再利用',
  mixed: '新規と再利用が混在',
  skip: 'スキップ見込み',
  unknown: '未判定（実行時に判定）',
};

export const OWNER_LABEL: Record<string, string> = {
  entry: 'エントリー',
  category: 'カテゴリー',
  blog: 'ブログ',
  user: 'ユーザー',
  module: 'モジュール',
  unit: 'カスタムユニット',
};

export type BadgeVariant = 'success' | 'info' | 'warning' | 'danger' | 'approval';

export function jobStatusVariant(status: JobStatus): BadgeVariant {
  switch (status) {
    case 'completed':
    case 'finalized':
      return 'success';
    case 'failed':
      return 'danger';
    case 'running':
    case 'scanning':
    case 'rolling_back':
      return 'info';
    case 'paused':
    case 'rolled_back':
      return 'warning';
    default:
      return 'approval';
  }
}

export function itemStatusVariant(status: string): BadgeVariant {
  switch (status) {
    case 'converted':
      return 'success';
    case 'error':
      return 'danger';
    case 'skipped':
      return 'warning';
    case 'pending':
      return 'info';
    default:
      return 'approval';
  }
}

export function label(map: Record<string, string>, key: string): string {
  return map[key] ?? key;
}

export function formatDateTime(value: string | null): string {
  return value === null || value === '' ? '—' : value.replace('T', ' ').slice(0, 19);
}
