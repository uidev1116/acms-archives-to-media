# ArchivesToMedia

a-blog cms の `archives/` 配下の画像・ファイルを参照している **ユニット** と **カスタムフィールド** を、**メディア参照へ一括変換する**拡張アプリ（プラグイン）です。

- 変換前に何が変わるかを確認できます（ドライラン）
- 変換後は元に戻せます（ロールバック）
- 共用レンタルサーバーのタイムアウトに耐えるよう、チャンク単位で実行・再開できます
- 既定ではテンプレートを壊しません（フィールドは別名 `{fd}_media` に書き込み、旧データはそのまま残します）

対応: a-blog cms 3.2.29 以降 / PHP 8.1 以降 / 管理画面 UI は `@ablogcms/components`（React 19）

- 設計書: [docs/design.md](docs/design.md)
- 動作確認・実装メモ: [docs/report.md](docs/report.md)

> 開発中です（0.1.0）。**本番で実行する前に、必ずステージングで「DB バックアップ → 実行 → 表示確認 → ロールバック」を一通り試してください。**

## 何を変換するか

| 変換元 | 変換先 |
|---|---|
| 画像ユニット（`image` / `image_*`） | メディアユニット |
| ファイルユニット（`file` / `file_*`） | メディアユニット |
| 画像フィールド（`{fd}@path`） | メディアフィールド（`{fd}_media` と `{fd}_media@media`） |
| ファイルフィールド（`{fd}@path` + `{fd}@baseName`） | 同上 |

リビジョン（`column_rev` / `field_rev`）も、設定でオンにすれば同じ手順で変換・ロールバックできます（既定はオフ）。フィールドの書き込み先は「別名（`_media`）」「同名」「フィールドごとの名前指定」から選べます。

対象外: テンプレートの書き換え、本文 HTML 内の `<img src="/archives/...">`、archives の元ファイルの削除、外部ストレージ上のファイル。

> **注意**: テンプレートを直す前に管理画面でエントリーを再保存すると、書き込んだ `{fd}_media` は消えます（旧キーは残るので表示は壊れません）。テンプレート更新後にもう一度実行すると揃います。

## 使い方

1. 管理画面 → 拡張アプリ → ArchivesToMedia をインストール・有効化
2. 「アーカイブ → メディア変換」を開く
3. **設定** → **スキャンと確認**（何も書き換えません）→ **実行**（途中で一時停止・再開できます）→ **結果**
4. 結果の「ロールバック」で元に戻せます。過去の実行は「過去の実行」→ ジョブ詳細から開けます
5. テンプレートを新しい書き方に直したあとは、結果画面の「旧データを削除（クリーンアップ）」で旧キー（`{fd}@path` など）を消せます。クリーンアップもロールバックで戻せます

### 運用上の注意

- **プラグインを更新したら**、管理画面の拡張アプリで「アップデート」を実行してください（テーブル定義を最新にします）。
- **アンインストール**は、ロールバックできる変換済みアイテムが残っていると中止されます（ロールバック情報ごとテーブルを削除してしまうため）。先にロールバックするか、不要であれば `config.system.php` に `define('ARCHIVES_TO_MEDIA_FORCE_UNINSTALL', true);` を追加してから実行してください。
- 実行中は、対象の行を `SELECT ... FOR UPDATE` でロックしてから書き込みます。それでも、編集ロック機能が使えないサイトでは**実行中にエントリーを保存しない**運用を推奨します。

## 開発

このプラグインは [appleple/acms-plugin-skeleton](https://github.com/appleple/acms-plugin-skeleton) をもとにしています。`src/` が `extension/plugins/ArchivesToMedia/` にリンクされるプラグイン本体です。

```bash
# PHP（a-blog cms 本体を含む環境の中で。テスト用 DB は環境変数で渡す）
composer install
vendor/bin/acms-create-database    # 初回のみ
composer check                     # phpcs + PHPStan (level max) + PHPUnit (Unit / Integration)

# フロントエンド（frontend/ → src/assets/dist/）
npm ci
npm run build                      # src/assets/dist/archives-to-media.js（コミットしない。CI / リリースでビルド）
npm run lint                       # ESLint + Prettier + Stylelint
npm run typecheck
npm run test:coverage              # Vitest（カバレッジ 90% 以上）
```

PHP のカバレッジは pcov または Xdebug で `vendor/bin/phpunit --coverage-text` を実行します。

### E2E（Playwright、ローカル専用）

実際の管理画面で「設定 → スキャン → 実行（一時停止・再開）→ 結果 → ロールバック → CSV」を通しで確認します。ローカルの a-blog cms と Docker の DB が必要なため、CI では実行しません。

```bash
npx playwright install chromium    # 初回のみ
npm run e2e
```

`.env.local`（コミットしない）に管理者の `A2M_ADMIN_MAIL` / `A2M_ADMIN_PASS` を書いておきます（`e2e/env.example` を参照）。そのユーザーは、子ブログにログインできる設定（ユーザーの「どのブログからでもログイン」）が必要です。実行前に `docs/verification/seed-a2m-test.php` で子ブログ `a2m-test` のデータを未変換の状態に作り直します（親ブログには触れません）。コンテナ名や URL を変える場合は `A2M_WEB_CONTAINER` / `A2M_DB_CONTAINER` / `A2M_DB_NAME` / `A2M_BASE_URL` を指定します。

## リリース

`composer package` で `build/ArchivesToMedia.zip` を作ります（先に `npm run build` が必要です）。タグ `v*` を push すると、`release.yml` がビルドして GitHub Release に zip を公開します。バージョンは `src/ServiceProvider.php` の `$version` です。

## ライセンス

MIT（[LICENSE](LICENSE)）
