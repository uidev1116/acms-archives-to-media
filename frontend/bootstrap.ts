import { createClient } from './api/client';
import type { Client } from './api/client';
import type { Bootstrap } from './types';

/** サーバーが差し込んだ初期データ（<script type="application/json" id="a2m-bootstrap">） */
export function readBootstrap(doc: Document): Bootstrap {
  const el = doc.getElementById('a2m-bootstrap');
  if (el === null) {
    throw new Error('初期データ（#a2m-bootstrap）が見つかりません。');
  }
  return JSON.parse(el.textContent ?? '') as Bootstrap;
}

/** 管理画面が meta タグ（csrf-token）と window.csrfToken で持っている CSRF トークン */
export function readCsrfToken(doc: Document, win: { csrfToken?: string }): string {
  const meta = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  return win.csrfToken ?? meta ?? '';
}

export function buildClient(doc: Document, win: Window & { csrfToken?: string }): Client {
  const boot = readBootstrap(doc);
  return createClient({
    endpoint: win.location.href.replace(/#.*$/, ''),
    apiAction: boot.api.action,
    csvAction: boot.csv.action,
    getToken: () => readCsrfToken(doc, win),
  });
}
