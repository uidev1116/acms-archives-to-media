/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// 管理画面にはアセット API（type="module"）で 1 ファイルだけ読み込ませる。
// 管理画面側の React を共有する公開 API が無いので、React と @ablogcms/components はバンドルに含める。
// CSS は JS から import せず、src/assets/css/archives-to-media.css を別途 <link> で配信する。
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
      formats: ['es'],
      fileName: () => 'archives-to-media.js',
    },
    rollupOptions: {
      output: { inlineDynamicImports: true },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['frontend/test/setup.ts'],
    include: ['frontend/**/*.test.{ts,tsx}'],
    css: false,
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
