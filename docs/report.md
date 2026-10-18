# ArchivesToMedia 実装レポート

作成: 2026-10-18（夜間の自動実装）／対象: ArchivesToMedia 0.1.0／設計書: [design.md](design.md)

> **結論**: 設計書の決定事項（UI パターン A + ジョブ詳細、別名モード既定、リビジョン既定オフ、enlarged=auto、本文 HTML 置換は対象外）どおりに実装し、ローカルの子ブログ `a2m-test` で「設定 → スキャン → 確認 → 実行（一時停止・再開）→ 完了 → クリーンアップ → ロールバック」を通しで確認しました。別名・同名・カスタム名の 3 モードとリビジョンも実機で確認し、Playwright の E2E（4 件）も通っています。ロールバック後の DB は変換前と**完全に一致**し、親ブログ（既存コンテンツ）のデータは**一切変わっていません**（ハッシュで確認）。

---

## 1. 朝の動作確認手順

### 1.1 ログインして画面を開く

| 項目 | 内容 |
|---|---|
| ログイン URL | https://web.my-blog-site-backend.localhost/a2m-test/login/ （親ブログの `/login/` でも可） |
| 検証用管理者 | ユーザー ID `a2m-tester`（パスワードは `plugins/ArchivesToMedia/.env.local` の `A2M_ADMIN_PASS`。コミットされません） |
| 変換画面（子ブログ a2m-test） | https://web.my-blog-site-backend.localhost/a2m-test/admin/app_archives_to_media_index/ |
| 過去の実行 | 上の URL の末尾に `#/jobs` |

- 拡張アプリは**ブログごとに有効化**します。`a2m-test`（blog_id=4）では有効化済みです。別のブログで試すときは、そのブログの「拡張アプリ」画面で ArchivesToMedia を有効化してください。
- 検証用ユーザーは「どのブログからでもログイン」を有効にしてあります（子ブログへログインするために必要）。ログインの失敗が 5 回続くと 5 分間ロックされます。

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
| 同名モード（リビジョン込み） | 45 件変換 → `hero` / `hero@media` に置き換わり、旧キーは削除。ロールバックで **子ブログの column / field / column_rev / field_rev の全行ハッシュが変換前と一致** |
| カスタム名モード（リビジョン込み） | `hero→heroMedia`・`cover→coverImage`（指定外は `_media`）。ロールバックで完全一致 |
| リビジョン（別名モード） | `column_rev` 2 世代・`field_rev` 2 世代が変換され、同じ画像は同じメディアを再利用。ロールバックで完全一致、作成メディアと対応表も削除 |
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
| Cleaner | 旧キーのクリーンアップ（別名モードの旧 `{fd}@*`）。削除した行を `item_cleanup` に控え、ロールバックで戻せる |
| ServiceProvider | Twig 登録、InjectTemplate、アセット API、`init()` 内の `App::singleton` / `App::bind` による DI |
| フロント | `frontend/`（React 19 + `@ablogcms/components` 3.2.37、Vite の ES module 1 ファイル）、素の CSS（`src/assets/css/`、クラス名は `acms-admin-a2m-` 接頭辞）。ウィザード 4 ステップ + 過去の実行 + ジョブ詳細（概要 / アイテム / ログ） |
| E2E | Playwright（`e2e/`、`npm run e2e`、ローカル専用。実行前に `a2m-test` を再シードして管理者でログイン） |
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
| 13 | API エラーは JSON 本文のみ・HTTP 200 | `Response::safeHeaders()` で正しい HTTP ステータス（400/403/404/409/422/500）を付けて返す | `Response::json()` はステータスを 200 に固定するため（§7.1） |
| 10 | 同名モードで旧キー（@path〜@squareFileSize、@secret）を削除 | 同じ所属・sort の `{fd}` と `{fd}@*` の行を**すべて**削除（設定でオフ可）し、スナップショットから完全復元 | 未知の派生キーが残って表示や再スキャンを乱すのを避ける。ロールバックで完全に戻せる |
| 11 | ロックは 10 分で失効（`Common\Lock`） | `flock` ベース（プロセス終了で解放） | コアの `Lock` の実装どおり。ブラウザを閉じても固まらない |
| 12 | 旧キーのクリーンアップ機能（§10.4） | 実装（結果画面の「旧データを削除」。ロールバックで戻せる） | 当初は後回しにしていたが、すべて実装する方針に変更 |
| 14 | 実行時は通常の読み取りで再照合 | 再照合の前に対象行を `SELECT ... FOR UPDATE` でロック | 競合の窓を狭めるため（`ConverterInterface::lock()`） |
| 15 | CSV は文字列で組み立て | `JobService::writeCsv()` が 200 件ずつストリームへ書き出し | 大規模サイトでのメモリ使用を避ける |
| 16 | アンインストールは無条件に DROP | ロールバックできる変換済みアイテムがあると中止（`ARCHIVES_TO_MEDIA_FORCE_UNINSTALL` で強制） | ロールバック不能になるのを防ぐ |
| 17 | メディア削除の参照判定はメディアユニット・フィールドのみ | ブロックエディターの `data-mid` も参照として見る | 使用中のメディアを消さないため |

## 4. 自動チェックの結果

| コマンド（実行場所） | 結果 |
|---|---|
| `composer check`（web コンテナ、テスト用 DB `db_acms_test`）: phpcs | エラー 0 |
| 〃 PHPStan（level max + strict-rules、src と tests） | エラー 0 |
| 〃 PHPUnit（Unit + Integration） | **290 tests / 1213 assertions すべて成功** |
| PHP カバレッジ（pcov、`src/` 全体） | **行 95.0%**（2044 / 2152 行。Classes 64% は POST ハンドラ・例外クラス等のため。ロジック層は 90〜100%） |
| `npm run build` | `src/assets/dist/archives-to-media.js` 約 480 KB（CSS は出力されない） |
| `npm run lint`（ESLint + Prettier + Stylelint） | エラー 0 |
| `npx tsc --noEmit` | エラー 0 |
| `npm run test:coverage`（Vitest） | **133 tests 成功。Statements 99.5% / Branches 94.7% / Functions 93.8% / Lines 99.5%**（閾値 90%） |
| `npm run e2e`（Playwright、ローカル） | **4 tests 成功**（通し変換とロールバック / 一時停止と再開 / ジョブ詳細と CSV / トークン無し POST の拒否） |
| GitHub Actions `Test` | quality / phpunit（PHP 8.1〜8.5）/ frontend がすべて成功（最終コミットの結果は §8 参照） |

カバレッジの補足:
- POST ハンドラ（`Api.php` / `ExportCsv.php`）は薄いラッパーで、PHPUnit では直接テストしていません（設計書 §14 の方針）。ブラウザでの通し確認と E2E で動作しています。
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
| `utf8mb4` の `varchar(512)` 複合ユニークキーが古い MySQL（DYNAMIC 行形式でない環境）で作成失敗しうる | **修正**: パスの sha1 ハッシュ列（`map_path_hash`）にユニークキーを張る（既存テーブルは `install()` / `update()` で移行） |
| `field_key LIKE '%@path'` がチャンクごとに全表走査 | **修正**: 先に `DISTINCT field_key` で対象キーを絞り、`field_key IN (...)` で読む |

### 5.2 独立サブエージェントレビュー

§7 にまとめます（2 周目の修正まで反映済み）。

## 6. 既知の制約・判断してほしいこと

**未完了**
- なし（設計書の範囲と、追加で挙げた項目はすべて実装しました）。CI に E2E は載せていません（ローカルの a-blog cms と Docker の DB が必要なため）。

**既知の制約・注意**
- 子ブログ `a2m-test` のエントリー `a2m-locked` は編集ロック中の状態でシードしています（48 時間で自然に失効します）。ロックが切れると、このエントリーの変換も通ります。再シードすれば元に戻ります。
- 行ロック（`SELECT ... FOR UPDATE`）を取りますが、編集ロック機能（Professional 版以上・設定オン）が無いサイトでは、**実行中にエントリーを保存しない**運用を推奨します。
- 「作成メディアを削除」は、メディアユニット・メディアフィールド・ブロックエディター（`data-mid`）・カスタムユニットのメディアフィールドからの参照を見て判定します。それ以外の形（本文 HTML に直接書いた URL など）は判定できません。既定はオフです。
- アンインストールは、ロールバックできる変換済みアイテムがあると中止されます。不要なら `config.system.php` で `ARCHIVES_TO_MEDIA_FORCE_UNINSTALL` を `true` にします。
- プラグインのファイルを更新したら、管理画面の拡張アプリで**「アップデート」を実行**してください。実行しないと、新しい列（例: `item_cleanup`）が無く 500 になります（今回の開発 DB でも、更新前に API が 500 を返したため `Schema::install()` を実行しました）。
- コアのメッセージ（取り込みエラーの文言）で理由コードを判定しています。文言が変わると `MEDIA_REGISTER_FAILED` に落ちるだけですが、対応表の語句がコアのソースに実在することをテストで確認しています。
- 実機のコアには、ドキュメントにある標準アセットハンドル（`acms-admin` など）が登録されていません。そのため、JS は `type="module"` の遅延実行に頼って、コアの管理画面 JS（`window.csrfToken` / `ACMS.i18n`）の後に実行されます。
- 管理画面は `@ablogcms/components` が `ACMS.i18n` を使うため、コアの管理画面 JS が読み込まれている前提です。
- 正しい管理画面 URL は `admin/app_archives_to_media_index/`（`app_` 接頭辞付き）です。接頭辞が無い URL では画面が描画されません。

**大量データについて**
- スキャン・実行・ロールバック・クリーンアップはすべてチャンク分割（既定 20 件。時間の上限もあり、超えたら途中で切り上げて次のリクエストへ）で、進捗は DB に残るので再開できます。スキャンは OFFSET ではなくカーソル（キーセットページング）で、フィールドの対象キー一覧はカーソルに入れて引き継ぎます。
- 実測（ローカル、ユニット約 3 万件＋フィールド約 3 万行を子ブログに入れて、スキャンのみ）: ユニットは 1 チャンクあたり約 0.3 秒で約 3 万件を数十秒で処理。フィールドは 1 チャンク（20 件）あたり約 0.17〜0.24 秒で、チャンクごとの時間は増え方が緩やかでした（このベンチは 3 万行を 1 エントリーに詰めた人工データで、実サイトより不利な条件です）。実行・ロールバックの実測はしていません。
- 数十万件規模では、スナップショット（`item_before` / `item_after`）で `a2m_item` が大きくなります（ロールバックに必要な情報のため削っていません）。

**テンプレート移行のヒント（結果画面）**
- 判定はしていません。変換対象に含まれたフィールド名ごとに、固定の書き換え例（旧 `{fd@path}` → 新 `{fd_media@path}`、入力欄）を表示するだけです。テンプレートの中身は読んでいません。
- 入力欄は、コアのメディアフィールドの書き方（`js-media-field` / `js-droparea` / `js-insert` / `js-value`）で表示します（以前は hidden の直書きで誤っていたため修正）。

**判断してほしいこと**
1. 同名モードの既定の挙動（旧キー削除オン）で良いか。
2. 親ブログ（blog_id=1）で実行するときの運用（ステージングで全工程を試してから、DB バックアップ → 実行 → 表示確認）。
3. 設計書 `docs/design.html` / `docs/ui-mock.html` は、指示に従いコミットせず（`.gitignore`）手元のファイルとして残しています。**最初の設計書コミット（`7b3070d`）の履歴には HTML が残っています**（force push しない方針のため履歴は書き換えていません）。

## 7. 独立レビュー（サブエージェント）

実装者とは別のエージェントが、設計書との差分・データ破損・セキュリティ・冪等性の 4 観点でレビューしました（High 2・Medium 7・Low 6）。意図的な逸脱（AUTO_INCREMENT、POST のみ、`entry_lock_*` での判定、一時ファイル経由の登録、Dataview 不使用）は妥当と評価されました。パストラバーサル・CSRF・SQL・BID スコープ・CSV 数式・XSS は「問題なし」でした。

| 重大度 | 指摘 | 対応 |
|---|---|---|
| High | 再実行（requeue）が `item_source_paths` を更新せず、スキャン後に差し替えられた画像の**旧パス**を書き込みうる | **修正**: requeue で現在のパスに更新、実行前にもパスの一致を確認。テスト追加 |
| High | `Failed` 状態でも再スキャン・オプション変更でき、変換済みの undo 情報（アイテム）が消える | **修正**: `Failed` を再スキャン不可に、変換済みを含むジョブのリセットは拒否。テスト追加 |
| Medium | ロールバック失敗由来の Error を再実行すると、変換済みユニットを二重変換して壊す | **修正**: `apply()` で `column_type` を再検証（変換済みはスキップ）。テスト追加 |
| Medium | トランザクション内で編集ロックを再確認していない／行ロックが無い | **修正**: 書き込み直前（トランザクション内）にも編集ロックを再確認し、再照合の前に `SELECT ... FOR UPDATE` で行ロックを取る |
| Medium | パス対応表が大文字小文字を区別しない（`Photo.JPG` と `photo.jpg` が衝突） | **修正**: `utf8mb4_bin` に変更（既存テーブルは `install()` / `update()` が ALTER）。テスト追加 |
| Medium | フィールドスキャナーのカーソル `sk` が所属の種類を区別せず、同じ ID のエントリー／カテゴリーのフィールドを取りこぼしうる | **修正**: `IFNULL` で位置を保つ。ページ境界を跨ぐテストを追加 |
| Medium | メディア登録後の補正（所属ブログ・alt・タグ）が失敗すると、再実行で二度と補正されない | **修正**: 補正を済ませてから対応表に記録し、失敗時は登録したメディアを削除して次回やり直す。テスト追加 |
| Medium | ロールバックのステータス更新がトランザクション外で、中断すると永久に `ROLLBACK_CONFLICT` | **修正**: 復元とステータス更新を同一トランザクションに。「既に before と一致」なら冪等に成功扱い。テスト追加 |
| Medium | スキャンにアイテム単位のエラー隔離が無く、1 件の例外で詰まる／カーソル列が 255 文字 | **修正**: 例外はエラーのアイテムとして記録して進む。`job_scan_cursor` を `text` に。テスト追加 |
| Low | 500 応答が例外メッセージ（SQL など）を画面に返す | **修正**: 一般メッセージにし、詳細は監査ログ |
| Low | `translate()` がコアの日本語メッセージの部分一致に依存 | **修正**: 対応表を定数にして、語句がコアのソースに実在することをテストで固定。事前検査で大半は安定した理由コード |
| Low | メディア削除の参照判定がユニット・フィールド以外（ブロックエディター等）を見ていない／削除は最終チャンクのみ | **修正**: ブロックエディターの `data-mid` とカスタムユニットのメディアフィールドも参照として判定（テスト追加）。削除が最終チャンクのみである点は仕様 |
| Low | 実行中にスキップされたアイテムの先に登録されたメディアが孤立する | **直さない**: 設計上の許容（タグ `archives-import` で検索可）。対応表に残るので再実行で再利用される |
| Low | CSV を全件メモリ上で組み立てる | **修正**: 200 件ずつストリームへ書き出す |
| Low | アンインストールがロールバック情報（アイテム）ごと DROP | **修正**: ロールバックできるアイテムがあるとアンインストールを中止（強制フラグあり） |

### 7.1 実機で追加に見つけた不具合（レビュー後の確認で発見）

- **エラー応答の HTTP ステータスが常に 200** だった（`Response::json()` はステータスを 200 に固定）。画面側のクライアントは `response.ok` で判定しているため、エラー応答を成功として扱ってしまう状態でした。`Response::safeHeaders()` で自前に出力するよう修正し、404 / 422 / 400 が返ることを実機で確認しました。
- 検証用ユーザーの `user_pass_generation` が未設定でログインできなかったため、3 に更新しました（シード用のユーザーを作る側の問題で、プラグインのコードには影響しません）。
- E2E の準備中に、検証用ユーザーが子ブログにログインできない（「どのブログからでもログイン」がオフ）ことと、`.env.local` のパスワードが DB のハッシュと合っていないことが分かりました。検証用ユーザーのパスワードを作り直し（`.env.local` を更新）、ログインを有効にしました。失敗が重なって 5 分間ロックされたため、ロックは回避せず待ちました。
- 開発 DB のプラグイン用テーブルが新しい定義（`item_cleanup` 列）に更新されておらず、API が 500 を返しました。`Schema::install()` で更新して解消しました（§6 の「アップデートを実行」の注意の根拠です）。
- 画面を開く URL は `app_` 接頭辞付きでないと描画されません。

### 7.2 今回の追加実装で入れた確認（最終パス）

- リビジョンを含めた 3 モード（別名・同名・カスタム）の実機確認と、ロールバック後の完全一致（ハッシュ）。
- 再シード用スクリプトに、リビジョン付きエントリー（`a2m-rev`）を追加。

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
