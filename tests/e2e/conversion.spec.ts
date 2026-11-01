import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

const PAGE = 'admin/app_archives_to_media_index/';
const API_ACTION = 'ACMS_POST_ArchivesToMedia_Api';

async function openWizard(page: Page): Promise<void> {
  await page.goto(PAGE);
  await expect(page.getByRole('heading', { name: /1\. 設定|設定/ }).first()).toBeVisible();
}

async function scan(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'スキャンして確認へ' }).click();
  // 既定は同名モードなので、確認ダイアログに同意して進む
  await page.getByRole('button', { name: '理解して続ける' }).click();
  await expect(page.getByRole('heading', { name: '2. スキャンと確認' })).toBeVisible();
  await expect(page.getByRole('button', { name: '変換の実行へ' })).toBeEnabled();
}

async function startRun(page: Page): Promise<void> {
  await page.getByRole('button', { name: '変換の実行へ' }).click();
  await page.getByLabel('DB のバックアップを取りました').check();
  await page.getByRole('button', { name: '変換を開始する' }).click();
}

async function jobIdOf(page: Page): Promise<number> {
  await page.goto(`${PAGE}#/jobs`);
  const link = page.getByRole('link', { name: /^#\d+$/ }).first();
  await expect(link).toBeVisible();
  const label = (await link.textContent()) ?? '';
  return Number(/#(\d+)/.exec(label)?.[1]);
}

test.describe.configure({ mode: 'serial' });

test('ウィザードで スキャン → 変換 → 結果 まで進み、ロールバックで元に戻せる', async ({ page }) => {
  await openWizard(page);
  await scan(page);
  await startRun(page);

  await expect(page.getByRole('heading', { name: '4. 結果' })).toBeVisible({ timeout: 90_000 });
  await expect(page.getByText('完了', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('変換済み').first()).toBeVisible();

  await page.getByRole('button', { name: 'ロールバック' }).click();
  await expect(page.getByText('ロールバックの確認')).toBeVisible();
  await page.getByRole('button', { name: 'ロールバックする' }).click();

  await expect(page.getByText('ロールバック済み').first()).toBeVisible({ timeout: 90_000 });
  await expect(page.getByRole('button', { name: 'ロールバック', exact: true })).toHaveCount(0);
});

test('実行を一時停止して再開できる', async ({ page }) => {
  // 実行 API を遅らせて、一時停止ボタンが押せる時間を作る
  await page.route('**/*', async (route) => {
    const request = route.request();
    if (
      request.method() === 'POST' &&
      (request.postData() ?? '').includes('name="action"\r\n\r\nrun')
    ) {
      await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    await route.continue();
  });

  await openWizard(page);
  await scan(page);
  await startRun(page);

  await page.getByRole('button', { name: '一時停止' }).click();
  await expect(page.getByText('一時停止中です')).toBeVisible({ timeout: 30_000 });

  await page.getByRole('button', { name: '再開' }).click();
  await expect(page.getByRole('heading', { name: '4. 結果' })).toBeVisible({ timeout: 120_000 });
});

test('過去の実行から詳細を開き、結果を CSV でダウンロードできる', async ({ page }) => {
  const jobId = await jobIdOf(page);
  await page.goto(`${PAGE}#/jobs/${jobId}`);
  await expect(page.getByText(`ジョブ #${jobId}`).first()).toBeVisible();

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: '結果を CSV でダウンロード' }).click(),
  ]);
  expect(download.suggestedFilename()).toBe(`archives-to-media-job-${jobId}.csv`);
  const stream = await download.createReadStream();
  const chunks: Buffer[] = [];
  for await (const chunk of stream) chunks.push(chunk as Buffer);
  const csv = Buffer.concat(chunks).toString('utf8');
  expect(csv.startsWith('﻿item_id,target,')).toBe(true);
  expect(csv.split('\n').length).toBeGreaterThan(10);
});

test('API は管理画面の外（トークン無し）からは使えない', async ({ request }) => {
  const res = await request.post(PAGE, { multipart: { [API_ACTION]: 'exec', action: 'jobs' } });
  // formToken が無い POST はコアが処理せず、API の JSON は返らない
  expect(res.headers()['content-type'] ?? '').not.toContain('application/json');
  expect((await res.text()).trimStart().startsWith('{')).toBe(false);
});
