import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: [
      'src/assets/dist',
      'node_modules',
      'vendor',
      'build',
      'coverage',
      'test-results',
      'playwright-report',
    ],
  },
  { linterOptions: { reportUnusedDisableDirectives: 'error' } },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['frontend/**/*.{ts,tsx}'],
    languageOptions: { globals: { ...globals.browser } },
    plugins: { 'react-hooks': reactHooks },
    rules: {
      ...reactHooks.configs.recommended.rules,
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      eqeqeq: ['error', 'always'],
      'no-console': ['error', { allow: ['warn', 'error'] }],
    },
  },
  {
    // コアが ACMS.Library.components で公開する部品は、ビルドで external（名前空間のまま渡る）。
    // 既定の import（import X from）だと、実行時に名前空間オブジェクトが渡って壊れるので、名前付き import にする。
    // select はコアが公開していないので同梱され、既定の import でよい。
    files: ['frontend/**/*.{ts,tsx}'],
    ignores: ['frontend/**/*.test.{ts,tsx}', 'frontend/test/**'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['@ablogcms/components/*', '!@ablogcms/components/select'],
              importNames: ['default'],
              message:
                '名前付き import にしてください（external の部品は既定の import だと壊れます）',
            },
          ],
        },
      ],
    },
  },
  // 管理画面の UI なので、アクセシビリティの基本ルールを有効にする
  { files: ['frontend/**/*.tsx'], ...jsxA11y.flatConfigs.recommended },
  {
    // 設定ファイルと E2E は Node で動く
    files: ['*.{js,ts}', 'e2e/**/*.ts'],
    languageOptions: { globals: { ...globals.node } },
  },
  // 整形はプリティアに任せる（ルールの衝突を避けるため、最後に置く）
  prettier,
);
