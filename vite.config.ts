/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// 管理画面にはアセット API で 1 ファイルだけ読み込ませる（IIFE。通常の <script>）。
// React・react-dom・デザインシステムの部品（@ablogcms/components）は、コア（a-blog cms 3.2.38 以上）が
// `ACMS.Library` で公開しているものを使い、同梱しない。
// CSS は JS から import せず、src/assets/css/archives-to-media.css を別途 <link> で配信する。

/** コアが `ACMS.Library.components['<サブパス>']` で公開している部品（キーはサブパス名と同じ） */
export const EXPOSED_COMPONENTS = [
  'alert',
  'announce',
  'badge',
  'button',
  'button-group',
  'button-v2',
  'checkbox',
  'conditional-wrap',
  'copy-to-clipboard',
  'draggable-button',
  'drawer',
  'drop-zone',
  'dropdown-menu',
  'field',
  'fieldset',
  'filter',
  'grid',
  'icon',
  'input',
  'input-group',
  'label',
  'modal',
  'pagination',
  'popover',
  'portal',
  'progress-bar',
  'radio',
  'slot',
  'slot-fill',
  'spacer',
  'spinner',
  'splash',
  'stack',
  'table',
  'tabs',
  'textarea',
  'toast',
  'tooltip',
  'visually-hidden',
] as const;

const COMPONENTS_PREFIX = '@ablogcms/components/';

// externals: import 元 → ホストが公開しているグローバル
export const globals: Record<string, string> = {
  react: 'ACMS.Library.React',
  'react/jsx-runtime': 'ACMS.Library.ReactJsxRuntime',
  'react-dom': 'ACMS.Library.ReactDom',
  'react-dom/client': 'ACMS.Library.ReactDomClient',
};

const exposed = new Set<string>(EXPOSED_COMPONENTS);

/**
 * `@ablogcms/components/<サブパス>` は、コアが公開しているものだけ external にする。
 * 公開されていない部品（select など）を external にすると実行時に undefined になるので、同梱する。
 */
export const isExternal = (id: string): boolean =>
  id in globals ||
  (id.startsWith(COMPONENTS_PREFIX) && exposed.has(id.slice(COMPONENTS_PREFIX.length)));
export const globalFor = (id: string): string =>
  id.startsWith(COMPONENTS_PREFIX)
    ? `ACMS.Library.components[${JSON.stringify(id.slice(COMPONENTS_PREFIX.length))}]`
    : (globals[id] ?? id);

/**
 * IIFE は externals を読み込み時に束縛するので、全体を関数で包み、コアが公開している ACMS.adminReady
 * （acms.js が同期で定義する。admin.js の初期化後に発火し、後から呼んでも即時に実行される）の中で実行する。
 * admin.js が読み込まれないページでは何も実行しない。コアが古い（ACMS.Library.ReactDom / components が無い）
 * ときも何もせず、必要なバージョンを console.info で案内する。
 */
const BOOT_BANNER = `;(function(){var __run=function(){
var L=window.ACMS&&window.ACMS.Library;
if(!L||!L.ReactDom||!L.ReactDomClient||!L.components){console.info('[archives-to-media] a-blog cms 3.2.38 以上が必要です（ACMS.Library.ReactDom / components がありません）。');return;}
try{`;
const BOOT_FOOTER = `}catch(e){console.error('[archives-to-media]',e);}};
var A=window.ACMS;
if(A&&typeof A.adminReady==='function'){A.adminReady(__run);}
})();`;

export default defineConfig(({ command }) => ({
  plugins: [react()],
  // ライブラリモードは NODE_ENV を置換しないので、ビルド時だけ production に固定する（テストでは置換しない）
  define: command === 'build' ? { 'process.env.NODE_ENV': JSON.stringify('production') } : {},
  build: {
    outDir: 'src/assets/dist',
    emptyOutDir: true,
    sourcemap: false,
    target: 'es2020',
    lib: {
      entry: 'frontend/main.tsx',
      formats: ['iife'],
      name: 'AcmsArchivesToMedia',
      fileName: () => 'archives-to-media.js',
    },
    rolldownOptions: {
      external: isExternal,
      output: { globals: globalFor, banner: BOOT_BANNER, footer: BOOT_FOOTER },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['frontend/test/setup.ts'],
    include: ['frontend/**/*.test.{ts,tsx}'],
    css: false,
    // Modal や userEvent を多用するテストは、CI やカバレッジ計測中の負荷で既定の 5 秒を超えることがある
    testTimeout: 20_000,
    coverage: {
      provider: 'v8',
      include: ['frontend/**/*.{ts,tsx}'],
      exclude: [
        'frontend/**/*.test.{ts,tsx}',
        'frontend/test/**',
        'frontend/main.tsx',
        'frontend/**/*.d.ts',
      ],
      reporter: ['text', 'text-summary'],
      thresholds: { lines: 90, statements: 90, functions: 90, branches: 85 },
    },
  },
}));
