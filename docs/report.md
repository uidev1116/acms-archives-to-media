# ArchivesToMedia 実装レポート

作成: 2026-10-18（夜間の自動実装）／対象: ArchivesToMedia 0.1.0／設計書: [design.md](design.md)

> **結論**: 設計書の決定事項（UI パターン A + ジョブ詳細、別名モード既定、リビジョン既定オフ、enlarged=auto、本文 HTML 置換は対象外）どおりに実装し、ローカルの子ブログ `a2m-test` で「設定 → スキャン → 確認 → 実行（一時停止・再開）→ 完了 → ロールバック」を通しで確認しました。ロールバック後の DB は変換前と**完全に一致**し、親ブログ（既存コンテンツ）のデータは**一切変わっていません**（ハッシュで確認）。

---

## 1. 朝の動作確認手順

### 1.1 ログインして画面を開く

| 項目 | 内容 |
|---|---|
| ログイン URL | https://web.my-blog-site-backend.localhost/login/ |
| 検証用管理者 | ユーザー ID `a2m-tester`（パスワードは `plugins/ArchivesToMedia/.env.local` の `A2M_ADMIN_PASS`。コミットされません） |
| 変換画面（子ブログ a2m-test） | https://web.my-blog-site-backend.localhost/bid/4/admin/app_archives_to_media_index/ |
| 過去の実行 | 上の URL の末尾に `#/jobs` |

- 拡張アプリは**ブログごとに有効化**します。`a2m-test`（blog_id=4）では有効化済みです。別のブログで試すときは、そのブログの「拡張アプリ」画面で ArchivesToMedia を有効化してください。
- 検証用ユーザーのログインは curl で確認済みです（ブラウザの既存セッションは触っていません）。

### 1.2 クリックする順番と期待する結果（子ブログ a2m-test）

1. **設定**（既定のまま）
   - 「変換する対象」は 4 種すべてオン、書き込み先は「別名のフィールド（`_media`）」。
   - ユニット種類のマッピングに `image（36 件）` / `file（1 件）`、「検出済み」に画像フィールド `gallery, hero, legacy` / ファイルフィールド `attachment` が出ます。
   - 「スキャンして確認へ」を押す。
2. **スキャンと確認**（何も書き換えません）
   - 「スキャンが完了しました。**42 件**の候補が見つかりました。」
   - 種類別の表: 画像ユニット 36（新規登録 35・スキップ 1）、ファイルユニット 1、画像フィールド 4（新規 3・スキップ 1）、ファイルフィールド 1。
   - `SOURCE_NOT_FOUND`（元ファイルが見つかりません）が 2 件（`missing.jpg` と `gone.jpg`）。
   - 一覧で行を選んで「選択を除外」、種類で絞り込んで「絞り込み結果をすべて除外」ができます。
   - 「変換の実行へ」→ ダイアログで「DB のバックアップを取りました」にチェック →「変換を開始する」。
3. **実行**（チャンク 20 件ずつ）
   - 進捗バー・件数・ログが流れます。「一時停止」を押すと、実行中のチャンクが終わったところで止まり、「再開」で続きから進みます。
   - タブを閉じても、「過去の実行 → ジョブ詳細 → 実行を再開」で続けられます。
   - ログに `ENTRY_LOCKED`（エントリー #568 `a2m-locked` が編集ロック中）が 1 件出ます。
4. **結果**
   - 最終的に 変換済み **39**、スキップ **3**（欠落 2 + ロック 1）。
   - 「テンプレート移行のヒント」に、フィールドごとの旧変数 → 新変数の対応（コピーボタン付き）が出ます。
   - 公開ページ https://web.my-blog-site-backend.localhost/a2m-test/a2m-units/ で、画像ユニットがメディア（`/media/004/...`）、PDF がダウンロード URL（`.../media-download/...`）になり、画像が表示されます（`missing.jpg` のユニットは未変換のまま）。
5. **ロールバック**
   - 「ロールバック」→ 確認ダイアログ（「このジョブで新規作成したメディアのうち、参照が無いものを削除する」は既定オフ）→「ロールバックする」。
   - 39 件が復元され、ジョブは「ロールバック済み」になります。
6. **やり直したいとき**: 変換データを作り直すスクリプトがあります（何度でも同じ未変換状態に戻ります）。

```bash
docker exec -w /var/www/plugins/ArchivesToMedia \
  -e ACMS_DB_HOST=db -e ACMS_DB_NAME=db_my-blog-site -e ACMS_DB_USER=root \
  -e ACMS_DB_PASS="$(docker exec my-blog-site-backend-db-1 printenv MYSQL_ROOT_PASSWORD)" \
  my-blog-site-backend-web-1 php docs/verification/seed-a2m-test.php
```

> このスクリプトはコード `a2m-test` の**子ブログだけ**を対象にします（親ブログは対象外）。

### 1.3 検証で確認した結果（実測）

| 確認項目 | 結果 |
|---|---|
| 画像ユニット → メディアユニット | `column_type`=media、field_1=media_id、field_2=キャプション、field_3=alt、field_4=拡大表示、field_5=アイコン、field_6=表示サイズ、field_7=リンク。`column_size` などレイアウトは不変 |
| large 版の優先 | `hero.jpg` は `large-hero.jpg` の内容で登録（ハッシュ一致）、拡大表示=yes（リンク無し + large あり）。large 無し・リンク有りは no |
| 内容が同じ別パスの再利用 | `plain.png` と `plain-copy.png` は同じ media_id（185） |
| 多言語ユニット | `186:acms_unit_delimiter:187` のように同じ区切りで書き戻し |
| ファイルユニット | メディアユニット（アイコン表示=yes）。公開ページは `media-download` の PDF（200・`application/pdf`） |
| フィールド（別名モード） | `hero_media` / `hero_media@media`（type=media）などを追加。旧キーは不変。グループ `@profile` に `hero_media` を追記 |
| 新規メディア | 所属ブログ=4、タグ `archives-import`、alt・キャプション設定済み |
| 欠落ファイル | `SOURCE_NOT_FOUND` でスキップ、データ不変 |
| 編集ロック | `ENTRY_LOCKED` でスキップ |
| 一時停止 → 再開 | 20/40 件で停止 → ジョブ詳細から再開して完走 |
| 変換後の再スキャン | 未変換の 3 件だけ（冪等） |
| CSRF | formToken が不正・無しの POST は処理されない |
| ロールバック | 39 件復元。**子ブログの column / field 全行のハッシュが変換前と一致**。作成メディア 37 件と対応表も削除 |
| 親ブログ | column / column_rev / field / field_rev / media / entry の**ハッシュが検証前後で一致**（変換されていません） |

スクリーンショット: [docs/verification/](verification/)（設定 / スキャン / 一時停止 / 完了 / 公開ページ / ロールバック済み）。

---

## 2. 実装したもの

設計書の構成どおりです（`src/` が `extension/plugins/ArchivesToMedia/` にリンクされるプラグイン本体）。

| 層 | 内容 |
|---|---|
| Domain | `TargetType` / `ItemStatus` / `JobStatus` / `ReasonCode` / `JobOptions` / `Candidate` / `Locator`（Column / Field） |
| Schema | `a2m_job` / `a2m_item` / `a2m_path_map`（install / uninstall / update） |
| Repository | `JobRepository` / `ItemRepository` / `PathMapRepository` |
| Media | `SourcePathSelector`（large 優先・パス検証）/ `ArchiveMediaResolver`（コアの `MediaSourceImporter` で検証・重複判定）/ `BlogOwnershipFixer` / `MediaMetaWriter` / `MediaReferenceChecker` |
| Scanner | 画像・ファイルユニット / 画像・ファイルフィールド（キーセットページング）、`ScanCoordinator`（ドライラン見込み含む） |
| Converter | `UnitImageConverter` / `UnitFileConverter` / `FieldMediaConverter`（別名・同名・カスタム、グループ追記、多言語区切り） |
| Job | `JobRunner`（チャンク・スナップショット照合・編集ロック・キャッシュクリア・Lock）/ `Rollbacker` / `JobService` |
| 入口 | `AdminApi` / `ApiEndpoint`（権限・例外 → HTTP）、`POST/ArchivesToMedia/Api.php`・`ExportCsv.php`（薄いラッパー） |
| ServiceProvider | Twig 登録、InjectTemplate、アセット API、`init()` 内の `App::singleton` / `App::bind` による DI |
| フロント | `frontend/`（React 19 + `@ablogcms/components` 3.2.37、Vite の ES module 1 ファイル）、素の CSS（`src/assets/css/`、`a2m-` 接頭辞）。ウィザード 4 ステップ + 過去の実行 + ジョブ詳細（概要 / アイテム / ログ） |
| 開発・CI | PHPUnit（Unit / Integration）、PHPStan level max + strict-rules、phpcs、Vitest（カバレッジ閾値 90%）、ESLint / Prettier / Stylelint、`test.yml` に frontend ジョブ、`release.yml` に build |

## 3. 設計からの変更点と理由

安全側に倒した判断・実装上の都合で変えた点です。

| # | 設計 | 実装 | 理由 |
|---|---|---|---|
| 1 | GET ハンドラ（`GET/Admin/...`）で JSON を返す | **すべて POST**（`ACMS_POST_ArchivesToMedia_Api` の 1 本 + CSV） | GET モジュールはテーマのテンプレートへの配置が必要で、プラグイン単体で完結しない。POST は CSRF 検証がフレームワークに組み込まれ、読み取りも含めて安全に統一できる |
| 2 | 個別ハンドラ（CreateJob / Scan / Run …） | 単一の `Api` + 操作名のホワイトリスト（`ApiEndpoint::ACTIONS`） | 薄さと権限・例外処理の一元化。ロジックは `AdminApi` / `ApiEndpoint` に置きテスト可能に |
| 3 | `a2m_job_id` などを `sequence_plugin` で採番 | `AUTO_INCREMENT` | 依存を減らし、`INSERT IGNORE` と相性が良い |
| 4 | 編集ロックは「lock テーブル」 | `entry` / `entry_rev` の `entry_lock_uid` / `entry_lock_datetime`（期限はコアの `Entry\Lock` に従う） | 実際のコアの編集ロックの置き場所がこちら。承認フロー次第で `entry` と `entry_rev` に分かれるので、**両方**を見る |
| 5 | `MediaSourceImporter::import()` で登録 | `importDryRun()` で検証・重複判定し、登録は一時ファイル経由で `Media\Helper::registerLocalFile()` を直接呼ぶ | `import()` はファイル名を source の basename で決めるため、`large-` 接頭辞付きで登録されてしまう。ファイル名ヒント（`@downloadName` など）も使いたい。検証はコアと同じ経路のまま |
| 6 | ジョブ詳細は別 ADMIN（`app_archives_to_media_job`） | 1 画面の SPA（ハッシュルーティング `#/jobs/{id}`） | 追加の ADMIN ページにはテーマ側のテンプレートが要る。メニューは `app_archives_to_media_index` のみ |
| 7 | `Dataview`（tanstack table）でアイテム一覧 | `Table` + 簡易ページネーション | 依存が重く、プラグインの責務に対して過剰。フィルター・ページ・選択・一括除外は実装済み |
| 8 | `acms-admin` ハンドルに依存して JS を読む | **ハンドルがあるときだけ**依存に加える | 実機のコアには標準ハンドルが登録されておらず、毎回ログに警告が残ったため |
| 9 | アセット URL は `/{DIR_OFFSET}` + `PLUGIN_DIR` | `AssetUrl::base()` で余分な `/` を除いて組み立て | 実機の `PLUGIN_DIR` は先頭に `/` を持ち、`//extension/...`（別ホスト扱い）になっていた（実機で検出して修正） |
| 10 | 同名モードで旧キー（@path〜@squareFileSize、@secret）を削除 | 同じ所属・sort の `{fd}` と `{fd}@*` の行を**すべて**削除（設定でオフ可）し、スナップショットから完全復元 | 未知の派生キーが残って表示や再スキャンを乱すのを避ける。ロールバックで完全に戻せる |
| 11 | ロックは 10 分で失効（`Common\Lock`） | `flock` ベース（プロセス終了で解放） | コアの `Lock` の実装どおり。ブラウザを閉じても固まらない |
| 12 | 旧キーのクリーンアップ機能（§10.4） | **未実装**（別機能・任意としていたため） | 今回のスコープ外 |

## 4. 自動チェックの結果

| コマンド（実行場所） | 結果 |
|---|---|
| `composer check`（web コンテナ、テスト用 DB `db_acms_test`）: phpcs | エラー 0 |
| 〃 PHPStan（level max + strict-rules、src と tests） | エラー 0 |
| 〃 PHPUnit（Unit + Integration） | **258 tests / 1037 assertions すべて成功** |
| PHP カバレッジ（pcov、`src/` 全体） | **行 約 95%**（Classes 約 65% は POST ハンドラ等のため。ロジック層は 90〜100%） |
| `npm run build` | `src/assets/dist/archives-to-media.js` 約 480 KB（CSS は出力されない） |
| `npm run lint`（ESLint + Prettier + Stylelint） | エラー 0 |
| `npx tsc --noEmit` | エラー 0 |
| `npm run test:coverage`（Vitest） | **125 tests 成功。Statements 99.65% / Branches 94.7% / Functions 95% / Lines 99.65%**（閾値 90%） |
| GitHub Actions `Test` | quality / phpunit（PHP 8.1〜8.5）/ frontend がすべて成功（最終コミットの結果は §8 参照） |

カバレッジの補足:
- POST ハンドラ（`Api.php` / `ExportCsv.php`）は薄いラッパーで、PHPUnit では直接テストしていません（設計書 §14 の方針）。ブラウザでの通し確認で動作しています。
- PHP のカバレッジ計測用に、web コンテナへ **pcov を `pecl install` しました**（`php.ini` には登録していません。計測時だけ `-d extension=pcov.so` で読み込みます。コンテナを作り直すと消えます）。

## 5. レビューの指摘と対応

レビューは 2 系統行いました。

### 5.1 code-review（high）— 10 件

| 指摘 | 対応 |
|---|---|
| `Db::all()` が失敗を空配列として返す（コアの `query()` は読み取り失敗でも例外を投げない）→ 実行が途中で「完了」になりうる | **修正**: PDO を直接使い、失敗は例外に |
| `field_key` の比較が大文字小文字を区別せず、`Photo` と `photo` を取り違える | **修正**: `BINARY` 比較にし、往復テストを追加 |
| ロールバック時のメディア削除が例外を投げるとジョブが永久に Failed | **修正**: メディアごとに catch し、失敗した media_id をジョブのメッセージに残す |
| 子ブログを含めるジョブで、各子ブログの管理権限を確認していない | **修正**: `JobService` で `sessionWithAdministration($bid)` を全子孫に確認（403） |
| 画面の「一時停止」がサーバーに記録されない（一覧が「実行中」のまま） | **修正**: 停止時に `pause` を呼ぶ |
| StrictMode の再マウントでループが止まる（開発ビルドのみ） | **修正**: 停止要求を持ち越さない |
| 「絞り込み結果をすべて除外」が絞り込み無しだと全件に作用 | **修正**: 絞り込みが無いときは無効化 |
| 未使用コード（`legacySuffixes()` / `LEGACY_*`） | **修正**: 削除 |
| `utf8mb4` の `varchar(512)` 複合ユニークキーが古い MySQL（DYNAMIC 行形式でない環境）で作成失敗しうる | **直さない**: a-blog cms 3.2 の要件（MySQL 5.7+ / MariaDB 10.x）では DYNAMIC が既定。必要になったらパスのハッシュ列をキーにする |
| `field_key LIKE '%@path'` がチャンクごとに全表走査 | **直さない**: 設計書どおりのキーセットページング。巨大サイトでは遅くなりうるので §6 に記載 |

### 5.2 独立サブエージェントレビュー

§7 にまとめます。

## 6. 未完了・既知の問題・判断してほしいこと

**未完了**
- 旧データのクリーンアップ機能（設計書 §10.4）は未実装です。
- E2E（Playwright）は未実装です。代わりに、ブラウザで通し操作を手で確認し、画面の挙動は Vitest + Testing Library でテストしています。
- 同名モード・リビジョン・カスタムモードは、**自動テストでは確認済み**ですが、ブラウザでの通し操作は別名モードのみです。

**既知の制約・注意**
- 子ブログ `a2m-test` のエントリー `a2m-locked` は編集ロック中の状態でシードしています（48 時間で自然に失効します）。ロックが切れると、このエントリーの変換も通ります（期待する結果が「ENTRY_LOCKED 1 件」から変わります）。再シードすれば元に戻ります。
- フィールドのスキャンは `field_key LIKE '%@path'` の全表走査をチャンクごとに行います。field が数百万行のサイトでは 1 チャンクに数秒かかる可能性があります。
- 実機のコアには、ドキュメントにある標準アセットハンドル（`acms-admin` など）が登録されていません。そのため、JS は `type="module"` の遅延実行に頼って、コアの管理画面 JS（`window.csrfToken` / `ACMS.i18n`）の後に実行されます。
- 管理画面は `@ablogcms/components` が `ACMS.i18n` を使うため、コアの管理画面 JS が読み込まれている前提です。

**判断してほしいこと**
1. 同名モードの既定の挙動（旧キー削除オン）で良いか。
2. 親ブログ（blog_id=1）で実行するときの運用（ステージングで全工程を試してから、DB バックアップ → 実行 → 表示確認）。
3. 旧データのクリーンアップ（§10.4）を次のフェーズで作るか。
4. 設計書 `docs/design.html` / `docs/ui-mock.html` は、指示に従いコミットせず（`.gitignore`）手元のファイルとして残しています。**最初の設計書コミット（`7b3070d`）の履歴には HTML が残っています**（force push しない方針のため履歴は書き換えていません）。

## 7. 独立レビュー（サブエージェント）

（このセクションは、レビュー完了後に追記します。）

## 8. 運用メモ

- 検証には、ブラウザペインに**既にログインしていたユーザー本人のセッション**を使いました（サインアウト・資格情報の変更はしていません）。管理操作（プラグインのインストール・子ブログ作成・変換の実行）はそのセッションで行っています。検証用ユーザー `a2m-tester`（user_id=11、管理者）も作成してあり、朝の確認に使えます。
- ブラウザペインでは、ネイティブの `confirm()` が無効のため、拡張アプリの「インストール」「有効化」ボタン（確認ダイアログ付き）はそのままでは押せません。実機の通常のブラウザでは問題ありません。
- 変換に使った実ファイルは `ablogcms/archives/a2m-test/` にあります（親リポジトリの `.gitignore` 対象）。

## 9. バックアップと復元

| 項目 | 内容 |
|---|---|
| ローカル DB のダンプ（変換前） | セッションのスクラッチパッド直下の `backup-before-a2m.sql`（約 9 MB。リポジトリの外。実際のフルパスは、公開リポジトリにローカルの絶対パスを入れない方針のため、チャットの最終報告に書きました。**一時ディレクトリなので、必要なら別の場所へ退避してください**） |
| 取得時刻 | 2026-10-18 02:15 頃（プラグインのインストール・子ブログ作成の**前**） |

復元コマンド（**ローカル DB `db_my-blog-site` を丸ごと上書きします**。実行前に必ず内容を確認してください）:

```bash
docker exec -i my-blog-site-backend-db-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" db_my-blog-site' \
  < /path/to/backup-before-a2m.sql   # ← 上のダンプのフルパスに置き換える
```

> 復元すると、プラグインのインストール状態・子ブログ `a2m-test`・検証用ユーザーも元（無し）に戻ります。親ブログのデータは今回の作業で変更していないので、通常は復元の必要はありません。
