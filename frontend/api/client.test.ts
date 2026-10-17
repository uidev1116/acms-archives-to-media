import { describe, expect, it, vi } from 'vitest';
import { ApiError, createClient } from './client';

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function make(fetchImpl: typeof fetch, token = 'tok123') {
  return createClient({
    endpoint: '/bid/2/admin/app_archives_to_media_index/',
    apiAction: 'ACMS_POST_ArchivesToMedia_Api',
    csvAction: 'ACMS_POST_ArchivesToMedia_ExportCsv',
    getToken: () => token,
    fetchImpl,
  });
}

describe('createClient', () => {
  it('操作名とパラメーターを formToken 付きの POST で送る', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ ok: 1 }));
    const client = make(fetchImpl as unknown as typeof fetch);

    const res = await client.call<{ ok: number }>('scan', { jobId: 5 });

    expect(res).toEqual({ ok: 1 });
    const [url, init] = fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/bid/2/admin/app_archives_to_media_index/');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    const body = init.body as FormData;
    expect(body.get('ACMS_POST_ArchivesToMedia_Api')).toBe('exec');
    expect(body.get('formToken')).toBe('tok123');
    expect(body.get('action')).toBe('scan');
    expect(body.get('jobId')).toBe('5');
  });

  it('オブジェクトは JSON、真偽値は 1/0、undefined と null は送らない', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({}));
    const client = make(fetchImpl as unknown as typeof fetch);

    await client.call('create', {
      options: { targets: ['unit_image'] },
      excluded: true,
      desc: false,
      q: undefined,
      page: null,
      ids: [1, 2],
    });

    const body = (fetchImpl.mock.calls[0] as unknown as [string, RequestInit])[1].body as FormData;
    expect(body.get('options')).toBe('{"targets":["unit_image"]}');
    expect(body.get('excluded')).toBe('1');
    expect(body.get('desc')).toBe('0');
    expect(body.has('q')).toBe(false);
    expect(body.has('page')).toBe(false);
    expect(body.get('ids')).toBe('[1,2]');
  });

  it('エラー応答は ApiError（ステータスとコード付き）にする', async () => {
    const fetchImpl = vi.fn(async () =>
      jsonResponse({ error: '同じブログで別の処理が実行中です。', code: 'busy' }, 409),
    );
    const client = make(fetchImpl as unknown as typeof fetch);

    await expect(client.call('run', { jobId: 1 })).rejects.toMatchObject({
      name: 'ApiError',
      status: 409,
      code: 'busy',
      message: '同じブログで別の処理が実行中です。',
    });
  });

  it('JSON でない応答（セッション切れの HTML など）は bad_response', async () => {
    const fetchImpl = vi.fn(
      async () =>
        new Response('<html>login</html>', {
          status: 200,
          headers: { 'Content-Type': 'text/html' },
        }),
    );
    const client = make(fetchImpl as unknown as typeof fetch);

    const err = await client.call('jobs').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).code).toBe('bad_response');
  });

  it('通信エラーは network として ApiError にする', async () => {
    const fetchImpl = vi.fn(async () => {
      throw new TypeError('Failed to fetch');
    });
    const client = make(fetchImpl as unknown as typeof fetch);

    const err = await client.call('jobs').catch((e: unknown) => e);
    expect((err as ApiError).code).toBe('network');
    expect((err as ApiError).status).toBe(0);
  });

  it('エラー本文にメッセージが無ければ既定の文言', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({}, 500));
    const client = make(fetchImpl as unknown as typeof fetch);
    await expect(client.call('jobs')).rejects.toMatchObject({ code: 'http_error', status: 500 });
  });

  it('CSV は hidden フォームを作って submit する', () => {
    let connectedAtSubmit = false;
    const submit = vi.fn((f: HTMLFormElement) => {
      connectedAtSubmit = document.body.contains(f);
    });
    const client = createClient({
      endpoint: '/x/',
      apiAction: 'A',
      csvAction: 'ACMS_POST_ArchivesToMedia_ExportCsv',
      getToken: () => 'csrf',
      fetchImpl: vi.fn() as unknown as typeof fetch,
      submitForm: submit,
    });

    client.exportCsv(7);

    expect(submit).toHaveBeenCalledTimes(1);
    const form = submit.mock.calls[0]?.[0] as HTMLFormElement;
    expect(form.method).toBe('post');
    expect(form.getAttribute('action')).toBe('/x/');
    expect((form.elements.namedItem('jobId') as HTMLInputElement).value).toBe('7');
    expect((form.elements.namedItem('formToken') as HTMLInputElement).value).toBe('csrf');
    expect(
      (form.elements.namedItem('ACMS_POST_ArchivesToMedia_ExportCsv') as HTMLInputElement).value,
    ).toBe('exec');
    // 接続していないフォームは submit されないので、submit の間だけ body に置く
    expect(connectedAtSubmit).toBe(true);
    expect(document.body.contains(form)).toBe(false);
  });
});
