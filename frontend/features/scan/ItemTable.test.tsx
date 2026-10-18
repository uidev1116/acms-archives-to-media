import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/client';
import { fakeClient, renderWithApi } from '../../test/helpers';
import type { ItemRow, ItemsPage } from '../../types';
import { ItemTable } from './ItemTable';

function row(id: number, over: Partial<ItemRow> = {}): ItemRow {
  return {
    id,
    target: 'unit_image',
    table: 'column',
    ownerType: 'entry',
    ownerId: '57',
    sourcePaths: [`001/${id}.jpg`],
    preview: 'new',
    mediaIds: [],
    status: 'pending',
    reason: '',
    reasonLabel: '',
    message: null,
    blogId: 2,
    ...over,
  };
}

const page = (items: ItemRow[], total = items.length): ItemsPage => ({ items, total });

describe('ItemTable', () => {
  it('アイテムを一覧にし、旧パス・種類・見込み・状態を表示する', async () => {
    const client = fakeClient({
      items: page([
        row(1),
        row(2, {
          status: 'skipped',
          reason: 'SOURCE_NOT_FOUND',
          reasonLabel: '元ファイルが見つかりません',
          preview: 'skip',
        }),
      ]),
    });
    renderWithApi(<ItemTable jobId={9} editable />, client);

    expect(await screen.findByText('001/1.jpg')).toBeInTheDocument();
    const skipped = screen.getByRole('row', { name: /001\/2\.jpg/ });
    expect(within(skipped).getByText('スキップ')).toBeInTheDocument();
    expect(within(skipped).getByText('元ファイルが見つかりません')).toBeInTheDocument();
    expect(client.calls[0]).toEqual({
      action: 'items',
      params: { jobId: 9, page: 1, perPage: 20, sort: 'item_id', desc: false },
    });
  });

  it('候補が無ければ空の表示', async () => {
    renderWithApi(<ItemTable jobId={1} editable />, fakeClient({ items: page([]) }));
    expect(await screen.findByText('該当するアイテムはありません。')).toBeInTheDocument();
  });

  it('状態・種類・パスで絞り込むと、1 ページ目から再取得する', async () => {
    const client = fakeClient({ items: page([row(1)]) });
    renderWithApi(<ItemTable jobId={1} editable />, client);
    await screen.findByText('001/1.jpg');

    await userEvent.selectOptions(screen.getByLabelText('状態で絞り込み'), 'skipped');
    await userEvent.selectOptions(screen.getByLabelText('種類で絞り込み'), 'field_file');
    await userEvent.type(screen.getByLabelText('パスで検索'), 'abc');
    await userEvent.click(screen.getByRole('button', { name: '絞り込む' }));

    await waitFor(() => {
      const last = client.calls.at(-1);
      expect(last?.params).toMatchObject({
        status: 'skipped',
        target: 'field_file',
        q: 'abc',
        page: 1,
      });
    });
  });

  it('ページを移動できる', async () => {
    const client = fakeClient({
      items: [page([row(1)], 45), page([row(21)], 45), page([row(1)], 45)],
    });
    renderWithApi(<ItemTable jobId={1} editable />, client);
    await screen.findByText('001/1.jpg');
    expect(screen.getByText('1 / 3 ページ（45 件）')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: '前のページ' })).toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: '次のページ' }));
    expect(await screen.findByText('001/21.jpg')).toBeInTheDocument();
    expect(client.calls.at(-1)?.params).toMatchObject({ page: 2 });

    await userEvent.click(screen.getByRole('button', { name: '前のページ' }));
    await waitFor(() => expect(client.calls.at(-1)?.params).toMatchObject({ page: 1 }));
  });

  it('選択したアイテムを除外・解除できる', async () => {
    const onChanged = vi.fn();
    const client = fakeClient({ items: page([row(1), row(2)]), exclude: { updated: 1 } });
    renderWithApi(<ItemTable jobId={3} editable onChanged={onChanged} />, client);
    await screen.findByText('001/1.jpg');

    expect(screen.getByRole('button', { name: '選択を除外' })).toBeDisabled();
    await userEvent.click(screen.getByLabelText('アイテム 2 を選択'));
    await userEvent.click(screen.getByRole('button', { name: '選択を除外' }));

    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
    expect(client.calls.find((c) => c.action === 'exclude')?.params).toEqual({
      jobId: 3,
      ids: [2],
      excluded: true,
    });

    await userEvent.click(screen.getByLabelText('アイテム 1 を選択'));
    await userEvent.click(screen.getByRole('button', { name: '選択の除外を解除' }));
    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(2));
    expect(client.calls.filter((c) => c.action === 'exclude')[1]?.params).toEqual({
      jobId: 3,
      ids: [1],
      excluded: false,
    });
  });

  it('絞り込みが無いときは、まとめて除外のボタンを押せない（全件を誤って除外しない）', async () => {
    renderWithApi(<ItemTable jobId={3} editable />, fakeClient({ items: page([row(1)]) }));
    await screen.findByText('001/1.jpg');
    expect(screen.getByRole('button', { name: '絞り込み結果をすべて除外' })).toBeDisabled();
  });

  it('現在の絞り込み結果をまとめて除外できる', async () => {
    const client = fakeClient({ items: page([row(1)]), exclude: { updated: 12 } });
    renderWithApi(<ItemTable jobId={3} editable />, client);
    await screen.findByText('001/1.jpg');
    await userEvent.selectOptions(screen.getByLabelText('種類で絞り込み'), 'unit_file');
    await userEvent.click(screen.getByRole('button', { name: '絞り込む' }));

    await userEvent.click(screen.getByRole('button', { name: '絞り込み結果をすべて除外' }));
    await waitFor(() => expect(client.calls.some((c) => c.action === 'exclude')).toBe(true));
    const call = client.calls.find((c) => c.action === 'exclude');
    expect(call?.params).toEqual({ jobId: 3, filter: { target: 'unit_file' }, excluded: true });
    expect(await screen.findByText('12 件を更新しました。')).toBeInTheDocument();
  });

  it('編集できない画面では選択・除外の操作を出さない', async () => {
    renderWithApi(<ItemTable jobId={1} editable={false} />, fakeClient({ items: page([row(1)]) }));
    await screen.findByText('001/1.jpg');
    expect(screen.queryByRole('button', { name: '選択を除外' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('アイテム 1 を選択')).not.toBeInTheDocument();
  });

  it('取得に失敗したらエラーを表示する', async () => {
    const client = fakeClient({ items: new ApiError('boom', 500, 'internal') });
    renderWithApi(<ItemTable jobId={1} editable />, client);
    expect(await screen.findByRole('alert')).toHaveTextContent('boom');
  });

  it('reloadKey が変わると再取得する', async () => {
    const client = fakeClient({ items: page([row(1)]) });
    const { rerender } = renderWithApi(<ItemTable jobId={1} editable reloadKey={0} />, client);
    await screen.findByText('001/1.jpg');
    const before = client.calls.length;
    const { ApiProvider } = await import('../../api/context');
    rerender(
      <ApiProvider client={client}>
        <ItemTable jobId={1} editable reloadKey={1} />
      </ApiProvider>,
    );
    await waitFor(() => expect(client.calls.length).toBeGreaterThan(before));
  });
});
