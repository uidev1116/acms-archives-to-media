import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { vi } from 'vitest';
import type { Client } from '../api/client';
import { ApiProvider } from '../api/context';

type Handler = (params: Record<string, unknown>) => unknown | Promise<unknown>;

export interface FakeClient extends Client {
  call: ReturnType<typeof vi.fn> & Client['call'];
  exportCsv: ReturnType<typeof vi.fn> & Client['exportCsv'];
  calls: { action: string; params: Record<string, unknown> }[];
}

/**
 * 操作名ごとのハンドラーを渡して偽のクライアントを作る。
 * ハンドラーが配列なら呼び出しごとに順に返し、最後の要素を使い続ける。
 */
export function fakeClient(handlers: Record<string, Handler | unknown[] | unknown>): FakeClient {
  const counters: Record<string, number> = {};
  const calls: FakeClient['calls'] = [];
  const call = vi.fn(async (action: string, params: Record<string, unknown> = {}) => {
    calls.push({ action, params });
    const h = handlers[action];
    if (h === undefined) {
      throw new Error(`unexpected action: ${action}`);
    }
    let value: unknown = h;
    if (Array.isArray(h)) {
      const i = counters[action] ?? 0;
      counters[action] = i + 1;
      value = h[Math.min(i, h.length - 1)];
    }
    const result = typeof value === 'function' ? await (value as Handler)(params) : value;
    if (result instanceof Error) throw result;
    return result;
  });
  return { call, exportCsv: vi.fn(), calls } as unknown as FakeClient;
}

export function renderWithApi(ui: ReactElement, client: Client) {
  return render(<ApiProvider client={client}>{ui}</ApiProvider>);
}
