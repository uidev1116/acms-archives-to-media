// @vitest-environment jsdom
import { mkdtempSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import * as alert from '@ablogcms/components/alert';
import * as badge from '@ablogcms/components/badge';
import * as button from '@ablogcms/components/button';
import * as checkbox from '@ablogcms/components/checkbox';
import * as fieldset from '@ablogcms/components/fieldset';
import * as input from '@ablogcms/components/input';
import * as modal from '@ablogcms/components/modal';
import * as progressBar from '@ablogcms/components/progress-bar';
import * as radio from '@ablogcms/components/radio';
import * as spinner from '@ablogcms/components/spinner';
import * as stack from '@ablogcms/components/stack';
import * as table from '@ablogcms/components/table';
import * as React from 'react';
import * as ReactDom from 'react-dom';
import * as ReactDomClient from 'react-dom/client';
import * as ReactJsxRuntime from 'react/jsx-runtime';
import { build } from 'vite';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * ビルド成果物（IIFE）の読み込みテスト。
 * - React / react-dom / デザインシステムの部品はコアが `ACMS.Library` で公開しているものを使い、同梱しない
 *   （a-blog cms 3.2.38 以上）。同梱しているのは、コアが公開していない部品（select）と、このプラグインのコードだけ
 * - 管理画面の初期化（ACMS.adminReady）のあとに実行され、古いコアでは何もせず必要なバージョンを案内する
 */
let outDir: string;
let js: string;

beforeAll(async () => {
  outDir = mkdtempSync(join(tmpdir(), 'a2m-bundle-'));
  // vitest の中は NODE_ENV=test なので、本番のビルドと同じ production にして作る（JSX の開発用ランタイムを使わせない）
  const previous = process.env.NODE_ENV;
  process.env.NODE_ENV = 'production';
  try {
    await build({
      configFile: 'vite.config.ts',
      mode: 'production',
      logLevel: 'silent',
      build: { outDir, emptyOutDir: true },
    });
  } finally {
    process.env.NODE_ENV = previous;
  }
  js = readFileSync(join(outDir, 'archives-to-media.js'), 'utf8');
}, 120_000);

afterAll(() => rmSync(outDir, { recursive: true, force: true }));

/** コア（a-blog cms 3.2.38 以上）が `ACMS.Library` で公開するもの。実物のパッケージで代用する */
const coreLibrary = () => ({
  React,
  ReactJsxRuntime,
  ReactDom,
  ReactDomClient,
  components: {
    alert,
    badge,
    button,
    checkbox,
    fieldset,
    input,
    modal,
    'progress-bar': progressBar,
    radio,
    spinner,
    stack,
    table,
  },
});

type FakeAcms = {
  adminReady: (fn: () => void) => void;
  Library?: unknown;
  i18n: (key: string) => string;
};

/** acms.js の ACMS.adminReady の再現：発火済みなら即実行、そうでなければ発火時に実行 */
function fakeAcms() {
  const listeners: (() => void)[] = [];
  let fired = false;
  const acms: FakeAcms = {
    adminReady: (fn) => (fired ? fn() : listeners.push(fn)),
    i18n: (key) => key,
  };
  return {
    acms,
    fire() {
      acms.Library = coreLibrary();
      fired = true;
      listeners.forEach((fn) => fn());
    },
  };
}

function mountPoint() {
  document.body.innerHTML =
    '<div id="a2m-root"></div>' +
    '<script type="application/json" id="a2m-bootstrap">' +
    JSON.stringify({ api: { action: 'ACMS_POST_Api' }, csv: { action: 'ACMS_POST_Csv' } }) +
    '</script>';
  return document.getElementById('a2m-root') as HTMLElement;
}

function run(acms: unknown) {
  (globalThis as unknown as { ACMS: unknown }).ACMS = acms;
  new Function(js)();
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('ビルド成果物', () => {
  it('React・react-dom・デザインシステムの部品の実装は同梱しない（コアが公開しているものを使う）', () => {
    expect(js).not.toContain('__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE'); // react 本体
    expect(js).not.toContain('__DOM_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE'); // react-dom 本体
    expect(js).not.toContain('acms-admin-modal-backdrop'); // modal の実装
    expect(js).not.toContain('acms-admin-progress'); // progress-bar の実装
  });

  it('サイズが小さい（React と部品を含めていた約 500KB から、select を含めても大きく減る）', () => {
    expect(statSync(join(outDir, 'archives-to-media.js')).size).toBeLessThan(250 * 1024);
  });

  it('管理画面の初期化（adminReady）の前には描画せず、発火したら描画する', async () => {
    const root = mountPoint();
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('offline'));
    const { acms, fire } = fakeAcms();
    run(acms);
    expect(root.childElementCount).toBe(0);

    fire();

    await vi.waitFor(() => expect(root.childElementCount).toBeGreaterThan(0));
  });

  it('adminReady の発火後に読み込まれても、すぐに描画する', async () => {
    const root = mountPoint();
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('offline'));
    const { acms, fire } = fakeAcms();
    fire();
    run(acms);
    await vi.waitFor(() => expect(root.childElementCount).toBeGreaterThan(0));
  });

  it('古いコア（ReactDom / components が無い。3.2.37 まで）では、何も描画せず、必要なバージョンを案内する', () => {
    const root = mountPoint();
    const info = vi.spyOn(console, 'info').mockImplementation(() => undefined);
    const { acms } = fakeAcms();
    acms.Library = { React, ReactJsxRuntime }; // React と JSX ランタイムだけ
    acms.adminReady = (fn) => fn(); // 初期化済みのコア：すぐ実行される
    expect(() => run(acms)).not.toThrow();
    expect(root.childElementCount).toBe(0);
    expect(info).toHaveBeenCalledWith(expect.stringContaining('3.2.38'));
  });

  it('管理画面以外（ACMS が無い・adminReady が無い）では何もせず、例外にもならない', () => {
    mountPoint();
    expect(() => run(undefined)).not.toThrow();
    expect(() => run({ Library: {} })).not.toThrow();
  });
});
