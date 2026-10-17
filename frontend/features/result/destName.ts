import type { JobOptions } from '../../types';

/** サーバー側 FieldNameResolver と同じ規則で、書き込み先のフィールド名を求める */
export function destName(fd: string, field: JobOptions['field']): string {
  switch (field.mode) {
    case 'same':
      return fd;
    case 'custom':
      return field.custom[fd] ?? fd + field.suffix;
    default:
      return fd + field.suffix;
  }
}
