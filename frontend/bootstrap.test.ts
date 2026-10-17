import { beforeEach, describe, expect, it, vi } from 'vitest';
import { buildClient, readBootstrap, readCsrfToken } from './bootstrap';

const boot = {
  blogId: 2,
  version: '0.1.0',
  adminUrl: '/x/',
  actions: ['jobs'],
  csv: { action: 'ACMS_POST_ArchivesToMedia_ExportCsv' },
  api: { action: 'ACMS_POST_ArchivesToMedia_Api' },
};

beforeEach(() => {
  document.head.innerHTML = '';
  document.body.innerHTML = '';
});

function addBootstrap(json: string) {
  const s = document.createElement('script');
  s.type = 'application/json';
  s.id = 'a2m-bootstrap';
  s.textContent = json;
  document.body.appendChild(s);
}

describe('bootstrap', () => {
  it('script タグの JSON を読む', () => {
    addBootstrap(JSON.stringify(boot));
    expect(readBootstrap(document).blogId).toBe(2);
  });

  it('初期データが無ければエラー', () => {
    expect(() => readBootstrap(document)).toThrow('#a2m-bootstrap');
  });

  it('CSRF トークンは window.csrfToken、無ければ meta タグ、それも無ければ空', () => {
    expect(readCsrfToken(document, { csrfToken: 'win' })).toBe('win');
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'metaTok';
    document.head.appendChild(meta);
    expect(readCsrfToken(document, {})).toBe('metaTok');
    meta.remove();
    expect(readCsrfToken(document, {})).toBe('');
  });

  it('クライアントを組み立て、現在の URL（hash なし）へ POST する', async () => {
    addBootstrap(JSON.stringify(boot));
    const fetchMock = vi.fn(
      async (..._args: unknown[]) =>
        new Response('{"ok":1}', { headers: { 'Content-Type': 'application/json' } }),
    );
    vi.stubGlobal('fetch', fetchMock);
    const win = {
      location: { href: 'https://example.test/bid/2/admin/app_archives_to_media_index/#/jobs' },
      csrfToken: 't',
    } as unknown as Window & { csrfToken?: string };

    const client = buildClient(document, win);
    await client.call('jobs');

    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      'https://example.test/bid/2/admin/app_archives_to_media_index/',
    );
    vi.unstubAllGlobals();
  });
});
