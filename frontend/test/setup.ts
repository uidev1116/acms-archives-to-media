import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeAll } from 'vitest';

// @ablogcms/components は、管理画面のコア JS が提供するグローバル ACMS.i18n() を使う
beforeAll(() => {
  (globalThis as unknown as { ACMS: { i18n: (key: string) => string } }).ACMS = { i18n: (key) => key };
});

afterEach(() => {
  cleanup();
});
