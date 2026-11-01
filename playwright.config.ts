import { defineConfig } from '@playwright/test';

// 認証情報は .env.local（コミットしない）から読む。CI では使わない（ローカルの a-blog cms と DB が必要）。
try {
  process.loadEnvFile('.env.local');
} catch {
  // 無ければ環境変数をそのまま使う
}

export default defineConfig({
  testDir: 'tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  fullyParallel: false,
  workers: 1,
  timeout: 120_000,
  expect: { timeout: 30_000 },
  reporter: [['list']],
  use: {
    baseURL: process.env.A2M_BASE_URL ?? 'https://web.my-blog-site-backend.localhost/a2m-test/',
    ignoreHTTPSErrors: true,
    locale: 'ja-JP',
    storageState: 'tests/e2e/.auth/state.json',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
