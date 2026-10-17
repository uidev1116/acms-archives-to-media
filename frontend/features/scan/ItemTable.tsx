import Alert from '@ablogcms/components/alert';
import Badge from '@ablogcms/components/badge';
import ButtonV2 from '@ablogcms/components/button-v2';
import Checkbox from '@ablogcms/components/checkbox';
import Input from '@ablogcms/components/input';
import Select from '@ablogcms/components/select';
import Table, {
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableScrollable,
} from '@ablogcms/components/table';
import { useCallback, useEffect, useState } from 'react';
import { ApiError } from '../../api/client';
import { useApi } from '../../api/context';
import {
  ITEM_STATUS_LABEL,
  itemStatusVariant,
  label,
  OWNER_LABEL,
  PREVIEW_LABEL,
  TARGET_LABEL,
} from '../../labels';
import type { ItemRow, ItemsPage, TargetType } from '../../types';

const PER_PAGE = 20;

interface Props {
  jobId: number;
  /** 除外の操作を出すか（スキャン後の確認画面だけ true） */
  editable: boolean;
  reloadKey?: number;
  onChanged?: () => void;
  /** 状態の絞り込みの初期値 */
  initialStatus?: string;
}

interface Filters {
  target: string;
  status: string;
  q: string;
}

const NO_FILTER: Filters = { target: '', status: '', q: '' };

function activeFilter(f: Filters): Record<string, string> {
  const out: Record<string, string> = {};
  if (f.target !== '') out.target = f.target;
  if (f.status !== '') out.status = f.status;
  if (f.q !== '') out.q = f.q;
  return out;
}

export function ItemTable({
  jobId,
  editable,
  reloadKey = 0,
  onChanged,
  initialStatus = '',
}: Props) {
  const api = useApi();
  const [draft, setDraft] = useState<Filters>({ ...NO_FILTER, status: initialStatus });
  const [applied, setApplied] = useState<Filters>({ ...NO_FILTER, status: initialStatus });
  const [pageNo, setPageNo] = useState(1);
  const [data, setData] = useState<ItemsPage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [version, setVersion] = useState(0);

  const load = useCallback(async () => {
    try {
      const res = await api.call<ItemsPage>('items', {
        jobId,
        ...activeFilter(applied),
        page: pageNo,
        perPage: PER_PAGE,
        sort: 'item_id',
        desc: false,
      });
      setData(res);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : String(e));
    }
  }, [api, jobId, applied, pageNo]);

  useEffect(() => {
    void load();
  }, [load, reloadKey, version]);

  const apply = () => {
    setSelected([]);
    setPageNo(1);
    setApplied(draft);
  };

  const exclude = async (params: Record<string, unknown>) => {
    try {
      const res = await api.call<{ updated: number }>('exclude', { jobId, ...params });
      setNotice(`${res.updated} 件を更新しました。`);
      setSelected([]);
      setVersion((v) => v + 1);
      onChanged?.();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : String(e));
    }
  };

  const total = data?.total ?? 0;
  const pages = Math.max(1, Math.ceil(total / PER_PAGE));
  const items: ItemRow[] = data?.items ?? [];

  return (
    <div className="a2m-items">
      <div className="a2m-filters">
        <Select
          aria-label="種類で絞り込み"
          value={draft.target}
          onChange={(e) => setDraft({ ...draft, target: e.target.value })}
        >
          <option value="">すべての種類</option>
          {(Object.keys(TARGET_LABEL) as TargetType[]).map((t) => (
            <option key={t} value={t}>
              {TARGET_LABEL[t]}
            </option>
          ))}
        </Select>
        <Select
          aria-label="状態で絞り込み"
          value={draft.status}
          onChange={(e) => setDraft({ ...draft, status: e.target.value })}
        >
          <option value="">すべての状態</option>
          {Object.entries(ITEM_STATUS_LABEL).map(([value, text]) => (
            <option key={value} value={value}>
              {text}
            </option>
          ))}
        </Select>
        <Input
          aria-label="パスで検索"
          placeholder="パスの一部"
          value={draft.q}
          onChange={(e) => setDraft({ ...draft, q: e.target.value })}
        />
        <ButtonV2 onClick={apply}>絞り込む</ButtonV2>
      </div>

      {editable && (
        <div className="a2m-bulk">
          <ButtonV2
            size="small"
            disabled={selected.length === 0}
            onClick={() => void exclude({ ids: selected, excluded: true })}
          >
            選択を除外
          </ButtonV2>
          <ButtonV2
            size="small"
            disabled={selected.length === 0}
            onClick={() => void exclude({ ids: selected, excluded: false })}
          >
            選択の除外を解除
          </ButtonV2>
          <ButtonV2
            size="small"
            onClick={() => void exclude({ filter: activeFilter(applied), excluded: true })}
          >
            絞り込み結果をすべて除外
          </ButtonV2>
        </div>
      )}

      {notice !== null && (
        <Alert variant="info" role="status" onClose={() => setNotice(null)}>
          {notice}
        </Alert>
      )}
      {error !== null && (
        <Alert variant="danger" role="alert">
          {error}
        </Alert>
      )}

      {data !== null && items.length === 0 ? (
        <p className="a2m-muted">該当するアイテムはありません。</p>
      ) : (
        <TableScrollable>
          <Table variant="list">
            <TableHeader>
              <TableRow>
                {editable && <TableHead />}
                <TableHead>種類</TableHead>
                <TableHead>所属</TableHead>
                <TableHead>旧パス</TableHead>
                <TableHead>見込み</TableHead>
                <TableHead>状態</TableHead>
                <TableHead>理由</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((it) => (
                <TableRow key={it.id}>
                  {editable && (
                    <TableCell>
                      {(it.status === 'pending' || it.status === 'excluded') && (
                        <Checkbox
                          aria-label={`アイテム ${it.id} を選択`}
                          checked={selected.includes(it.id)}
                          onChange={(e) =>
                            setSelected((cur) =>
                              e.target.checked ? [...cur, it.id] : cur.filter((v) => v !== it.id),
                            )
                          }
                        />
                      )}
                    </TableCell>
                  )}
                  <TableCell>{label(TARGET_LABEL, it.target)}</TableCell>
                  <TableCell>
                    {label(OWNER_LABEL, it.ownerType)} #{it.ownerId}
                  </TableCell>
                  <TableCell>
                    {it.sourcePaths.map((p, i) => (
                      <code key={`${p}-${i}`} className="a2m-path">
                        {p}
                      </code>
                    ))}
                  </TableCell>
                  <TableCell>{label(PREVIEW_LABEL, it.preview)}</TableCell>
                  <TableCell>
                    <Badge variant={itemStatusVariant(it.status)}>
                      {label(ITEM_STATUS_LABEL, it.status)}
                    </Badge>
                  </TableCell>
                  <TableCell>{it.reasonLabel}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableScrollable>
      )}

      <div className="a2m-pager">
        <ButtonV2 size="small" disabled={pageNo <= 1} onClick={() => setPageNo((p) => p - 1)}>
          前のページ
        </ButtonV2>
        <span>
          {pageNo} / {pages} ページ（{total} 件）
        </span>
        <ButtonV2 size="small" disabled={pageNo >= pages} onClick={() => setPageNo((p) => p + 1)}>
          次のページ
        </ButtonV2>
      </div>
    </div>
  );
}
