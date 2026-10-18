import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { chromium } from '@playwright/test';

/**
 * 1. 動作確認用の子ブログ（a2m-test）を未変換の状態に作り直す（e2e/seed-a2m-test.php）。
 *    seed は a2m-test 以外のブログには触れない。
 * 2. 管理者でログインして、セッションを e2e/.auth/state.json に保存する。
 *
 * 必要な環境変数（.env.local）: A2M_ADMIN_MAIL, A2M_ADMIN_PASS
 * 任意: A2M_BASE_URL, A2M_WEB_CONTAINER, A2M_DB_CONTAINER, A2M_DB_NAME
 */
function required(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === '') {
    throw new Error(`${name} が未設定です（.env.local を確認してください）。`);
  }
  return value;
}

function docker(args: string[]): string {
  return execFileSync('docker', args, { encoding: 'utf8' });
}

function reseed(): void {
  const web = process.env.A2M_WEB_CONTAINER ?? 'my-blog-site-backend-web-1';
  const db = process.env.A2M_DB_CONTAINER ?? 'my-blog-site-backend-db-1';
  const dbName = process.env.A2M_DB_NAME ?? 'db_my-blog-site';
  const password = docker(['exec', db, 'printenv', 'MYSQL_ROOT_PASSWORD']).trim();
  docker([
    'exec',
    '-w',
    '/var/www/plugins/ArchivesToMedia',
    '-e',
    'ACMS_DB_HOST=db',
    '-e',
    `ACMS_DB_NAME=${dbName}`,
    '-e',
    'ACMS_DB_USER=root',
    '-e',
    `ACMS_DB_PASS=${password}`,
    web,
    'php',
    'e2e/seed-a2m-test.php',
  ]);
}

export default async function globalSetup(): Promise<void> {
  const mail = required('A2M_ADMIN_MAIL');
  const pass = required('A2M_ADMIN_PASS');
  const base = process.env.A2M_BASE_URL ?? 'https://web.my-blog-site-backend.localhost/a2m-test/';

  reseed();

  mkdirSync('e2e/.auth', { recursive: true });
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ja-JP' });
    const page = await context.newPage();
    await page.goto(new URL('login/', base).toString());
    await page.locator('input[name="mail"]').fill(mail);
    await page.locator('input[name="pass"]').fill(pass);
    await page.getByRole('button', { name: '管理ログイン' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login/'));
    await context.storageState({ path: 'e2e/.auth/state.json' });
  } finally {
    await browser.close();
  }
}
