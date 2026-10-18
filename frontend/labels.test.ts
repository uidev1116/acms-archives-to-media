import { describe, expect, it } from 'vitest';
import {
  formatDateTime,
  ITEM_STATUS_LABEL,
  itemStatusVariant,
  JOB_STATUS_LABEL,
  jobStatusVariant,
  label,
} from './labels';
import type { JobStatus } from './types';

describe('labels', () => {
  it('ジョブ状態ごとにバッジの色を決める', () => {
    const expected: Record<JobStatus, string> = {
      draft: 'approval',
      scanning: 'info',
      scanned: 'approval',
      running: 'info',
      paused: 'warning',
      completed: 'success',
      failed: 'danger',
      rolling_back: 'info',
      rolled_back: 'warning',
      finalized: 'success',
    };
    for (const [status, variant] of Object.entries(expected)) {
      expect(jobStatusVariant(status as JobStatus)).toBe(variant);
      expect(JOB_STATUS_LABEL[status as JobStatus]).toBeTruthy();
    }
  });

  it('アイテム状態ごとにバッジの色を決める', () => {
    expect(itemStatusVariant('converted')).toBe('success');
    expect(itemStatusVariant('error')).toBe('danger');
    expect(itemStatusVariant('skipped')).toBe('warning');
    expect(itemStatusVariant('pending')).toBe('info');
    expect(itemStatusVariant('excluded')).toBe('approval');
    expect(ITEM_STATUS_LABEL.converted).toBe('変換済み');
  });

  it('未知のキーはそのまま返す', () => {
    expect(label({ a: 'A' }, 'a')).toBe('A');
    expect(label({ a: 'A' }, 'zzz')).toBe('zzz');
  });

  it('日時は秒まで、空は —', () => {
    expect(formatDateTime('2026-10-18 05:15:37')).toBe('2026-10-18 05:15:37');
    expect(formatDateTime('2026-10-18T05:15:37+09:00')).toBe('2026-10-18 05:15:37');
    expect(formatDateTime(null)).toBe('—');
    expect(formatDateTime('')).toBe('—');
  });
});
