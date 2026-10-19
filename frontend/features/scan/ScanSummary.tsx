import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@ablogcms/components/table';
import { TARGET_LABEL } from '../../labels';
import type { SummaryRow, TargetType } from '../../types';

export const REASON_LABEL: Record<string, string> = {
  SOURCE_NOT_FOUND: '元ファイルが見つかりません',
  SOURCE_FORBIDDEN: 'archives の外を指しています',
  SOURCE_TOO_LARGE: 'サイズ上限を超えています',
  SOURCE_INVALID_MIME: '許可されていないファイル形式です',
  ENTRY_LOCKED: 'エントリーが編集ロック中です',
  STALE_SNAPSHOT: 'スキャン後にデータが変更されました',
  DEST_CONFLICT: '書き込み先のフィールドが既に存在します',
  PARTIAL_SOURCE: '多言語ユニットの一部の言語で失敗しました',
  UNIT_TYPE_UNMAPPED: 'ユニット種類のマッピングがありません',
  MEDIA_REGISTER_FAILED: 'メディア登録に失敗しました',
  ROLLBACK_CONFLICT: '変換後に編集されたため戻せません',
  ALT_MISMATCH: '再利用メディアの alt と一致しません',
  MEDIA_OTHER_BLOG: '別のブログのメディアを再利用しました',
  UNKNOWN_ERROR: '予期しないエラー',
};

interface Tally {
  total: number;
  new: number;
  reuse: number;
  mixed: number;
  unknown: number;
  skipped: number;
  excluded: number;
}

function tally(rows: SummaryRow[]): Record<string, Tally> {
  const out: Record<string, Tally> = {};
  for (const r of rows) {
    const t = (out[r.target] ??= {
      total: 0,
      new: 0,
      reuse: 0,
      mixed: 0,
      unknown: 0,
      skipped: 0,
      excluded: 0,
    });
    t.total += r.count;
    if (r.status === 'skipped') t.skipped += r.count;
    else if (r.status === 'excluded') t.excluded += r.count;
    else if (r.preview === 'new') t.new += r.count;
    else if (r.preview === 'reuse') t.reuse += r.count;
    else if (r.preview === 'mixed') t.mixed += r.count;
    else if (r.preview === 'unknown') t.unknown += r.count;
  }
  return out;
}

export function ScanSummary({ summary }: { summary: SummaryRow[] }) {
  if (summary.length === 0) {
    return <p className="acms-admin-a2m-muted">変換できる候補は見つかりませんでした。</p>;
  }
  const byTarget = tally(summary);
  const unknown = summary
    .filter((r) => r.preview === 'unknown' && r.status !== 'excluded')
    .reduce((n, r) => n + r.count, 0);
  const reasons = new Map<string, number>();
  for (const r of summary) {
    if (r.status === 'skipped' && r.reason !== '') {
      reasons.set(r.reason, (reasons.get(r.reason) ?? 0) + r.count);
    }
  }

  return (
    <div className="acms-admin-a2m-summary">
      <Table variant="list">
        <TableHeader>
          <TableRow>
            <TableHead>種類</TableHead>
            <TableHead align="right">合計</TableHead>
            <TableHead align="right">新規登録</TableHead>
            <TableHead align="right">再利用</TableHead>
            <TableHead align="right">混在</TableHead>
            {unknown > 0 && <TableHead align="right">未判定</TableHead>}
            <TableHead align="right">スキップ</TableHead>
            <TableHead align="right">除外</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {Object.entries(byTarget).map(([target, t]) => (
            <TableRow key={target}>
              <TableHead scope="row">{TARGET_LABEL[target as TargetType] ?? target}</TableHead>
              <TableCell className="acms-admin-a2m-num">{t.total}</TableCell>
              <TableCell className="acms-admin-a2m-num">{t.new}</TableCell>
              <TableCell className="acms-admin-a2m-num">{t.reuse}</TableCell>
              <TableCell className="acms-admin-a2m-num">{t.mixed}</TableCell>
              {unknown > 0 && <TableCell className="acms-admin-a2m-num">{t.unknown}</TableCell>}
              <TableCell className="acms-admin-a2m-num">{t.skipped}</TableCell>
              <TableCell className="acms-admin-a2m-num">{t.excluded}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {unknown > 0 && (
        <p className="acms-admin-a2m-muted">
          {unknown} 件は、時間の都合で新規登録か再利用かを判定していません（実行時に判定します）。
        </p>
      )}
      {reasons.size > 0 && (
        <ul className="acms-admin-a2m-reasons">
          {[...reasons.entries()].map(([code, count]) => (
            <li key={code}>
              <code>{code}</code> <span>{REASON_LABEL[code] ?? code}</span>（{count} 件）
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
