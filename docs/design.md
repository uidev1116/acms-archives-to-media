# ArchivesToMedia プラグイン設計書

a-blog cms の `archives/` 配下の画像・ファイルを参照しているユニットとカスタムフィールドを見つけ、そのファイルをメディアに登録して、参照データをメディア形式へ書き換える拡張アプリです。

対象: a-blog cms 3.2.29 以降（アセット API、Twig 管理画面、`Media\Helper::registerLocalFile()` / `MediaSourceImporter` を使うため）／PHP 8.1+／2026-10-18 初版・同日改訂（アセット API・Twig・素の CSS・公式テスト基盤を反映）

## 1. 概要

### 1.1 背景

a-blog cms では、画像ユニット・ファイルユニット・画像フィールド・ファイルフィールドがファイルを `archives/` に保存し、そのパスを DB に文字列で持っています。メディア管理に一本化すると、次のことができるようになります。

- 同じファイルを複数のエントリーで再利用できる
- メディア管理画面でタグ付けや差し替えができる
- 焦点座標や代替テキストをメディア側で一元管理できる

ただし既存データを手作業で移すのは現実的ではないので、プラグインで一括変換します。

### 1.2 ゴール

- 既存の archives 参照を、**データだけ**メディア参照へ移行する。
- 変換前に何が変わるかを確認でき（ドライラン）、変換後は元に戻せる（ロールバック）。
- 共用レンタルサーバーのタイムアウトに耐えるよう、チャンク単位で再開できる。
- 管理画面 UI は公式の `@ablogcms/components`（React 19）で作る。

### 1.3 プラグイン名と識別子

| 項目 | 値 |
|---|---|
| 拡張アプリ名 | `ArchivesToMedia` |
| 名前空間 | `Acms\Plugins\ArchivesToMedia` |
| 管理画面 ADMIN | `app_archives_to_media_index`（一覧／ウィザード）、`app_archives_to_media_job`（ジョブ詳細） |
| アセットのハンドル | `archives-to-media`（JS）、`archives-to-media-style`（CSS） |
| Twig の名前空間 | `@archives_to_media` |
| DB テーブル接頭辞 | `%{PREFIX}a2m_` |
| ロック | `CACHE_DIR . 'archives-to-media-lock-{bid}'`（`Common\Lock`） |

## 2. スコープ

#### 対象 変換するもの

- **画像ユニット**（`column_type` が `image` / `image_*`）→ メディアユニット
- **ファイルユニット**（`file` / `file_*`）→ メディアユニット
- **画像フィールド**（`{fd}@path` を持つカスタムフィールド）→ メディアフィールド
- **ファイルフィールド**（`{fd}@path` + `{fd}@baseName`）→ メディアフィールド
- フィールドの所属: エントリー／カテゴリー／ブログ／ユーザー／モジュール／カスタムユニット（`field_unit_id`）
- フィールドグループ（`@groupName`）に入っているフィールド
- オプション: リビジョン（`column_rev` / `field_rev`）

#### 対象外 しないこと

- **テンプレートの書き換え**（入力欄の `:extension=media` 化や表示側の変数名の変更）
- ユニット定義（エディター設定）の追加・変更
- 本文 HTML（WYSIWYG・リッチエディター・テキストユニット）の中の `<img src="/archives/...">`
- archives の元ファイルの削除・移動
- 外部ストレージ（S3 など）上のファイル。ローカルファイルシステムのみ対象
- カスタムフィールドの「画像の URL を文字列で入れたテキストフィールド」

本文 HTML の置換は、将来の拡張として残せる設計にします。変換時に保存する [パス→メディア対応表（`a2m_path_map`）](#data) を、そのまま置換辞書として使えます。

## 3. 重要な前提と制約

### 3.1 メディアフィールドは表示時に `{fd}@path` を上書きする

`Media\Helper::injectMediaField()` は、`{fd}@media` を持つフィールドについて、表示時に次のキーを**メディアの値で上書き**します。

`{fd}@path` / `@alt` / `@fileSize` / `@extension` / `@thumbnail` / `@width` / `@height` …

上書き後の `@path` は `Url::resolveUrl($path, MEDIA_LIBRARY_DIR)` で解決済みの URL です。

**同じフィールド名のままメディア化すると、テンプレートを直す前に表示が壊れます。**

たとえば現在のテンプレートが `%{ARCHIVES_DIR}{main_image@path}` と書いている場合、`main_image@media` を書き込んだ瞬間に `@path` がメディアの URL に置き換わり、`/archives//media/…` のような壊れた URL になります。`@largePath` などは上書きされないので、旧形式とメディア形式が混ざった中途半端な状態になります。

**そこで、旧キーを残して段階的に移行したい場合のために、別名のフィールド**（`{fd}_media`）への書き込みを用意します。ただし画面の既定は**同名**です（メンテナンス画面にした状態や、テスト環境で実行して切り替える運用を想定するため）。別名では、旧キーはそのまま残り、テンプレートを直すまでは旧キーで表示され続け、直した後は新しいフィールドを使います。

同じ名前で上書きするモードも用意しますが、テンプレートを同時に差し替える前提の上級者向けオプションです（確認ダイアログあり）。

### 3.2 テンプレート未更新のまま再保存すると、書き込んだメディア値が消える

エントリーを保存すると、そのとき POST されたフィールドで全体が置き換わります。テンプレートを直す前に管理画面でエントリーを再保存すると、`{fd}_media` は POST に含まれないので消えます。

旧キーは残るので**表示は壊れません**。消えたものはスキャンで「未変換」として再び検出されるので、テンプレート更新後にもう一度実行すれば揃います。画面にはこの注意を常に表示します。

### 3.3 メディア登録はコアの経路に乗せる

- ファイルの検証と登録は、コアの `MediaSourceImporter` に任せます。インスタンスはプラグイン側で、`localPathBase = SCRIPT_DIR . ARCHIVES_DIR` として生成します。`media.source-importer` は CSV インポート用のベースディレクトリで登録されているので流用しません。これでパストラバーサル対策、サイズ上限、MIME 判定、SHA-256 による重複再利用（`media_hash`）がコアと同じになります。
- ドライランでは `importDryRun()` → `MediaImportPreview` を使い、「新規登録」か「既存メディアの再利用」かの見込みを出します。
- `registerLocalFile()` は `BID` / `SUID` を使ってメディアを登録します。子ブログのデータを変換するときは、登録後に `media_blog_id` をデータ所有ブログへ補正します（§8 `BlogOwnershipFixer`）。

### 3.4 元ファイルは消さない

変換後も、archives の実ファイルと旧キー（オプションで残す場合）はそのままにします。旧データの掃除は、テンプレート移行が終わってから別機能「旧データのクリーンアップ」で行います（§10.4）。

## 4. 変換仕様（データマッピング）

### 4.1 共通: 取り込むファイルの選び方（SourcePathSelector）

画像は、リサイズ前に近い `large-` 版が残っていればそれを優先します。

| 優先順位 | 候補 | 備考 |
|---|---|---|
| 1 | `{dir}/large-{basename}` | 画像ユニット・画像フィールドの両方。画像編集の「元に戻す」でもオリジナル相当として使われるファイル |
| 2 | `{dir}/{basename}`（通常サイズ） | large が無い場合 |
| — | `tiny-` / `square-` | 使わない。メディア側でサムネイルを生成し直す |

ファイル名（`registerLocalFile` の第2引数）は次の順で決めます。

- ファイルフィールド: `@downloadName` → `@baseName` → パスの basename
- 画像: パスの basename（`large-` を除く）

### 4.2 画像ユニット → メディアユニット

多言語ユニットのデータは `:acms_unit_delimiter:` 区切りで入っています（`explodeUnitDataTrait`）。区切った位置ごとに 1 ファイル → 1 メディアとして対応させ、同じ区切りで書き戻します。

| 画像ユニット（変換前） | 意味 | メディアユニット（変換後） | 変換ルール |
|---|---|---|---|
| `column_type` | image / image_* | `column_type` | マッピング設定で選んだメディアユニット定義の ID（例: `media`、`media_2`） |
| `column_field_2` | パス | `column_field_1` | パスごとにメディアを登録して得た `media_id` |
| `column_field_1` | キャプション | `column_field_2` | 上書きキャプションとしてそのまま移す |
| `column_field_4` | alt | `column_field_3` | 上書き alt としてそのまま移す |
| `column_field_3` | リンク | `column_field_7` | 上書きリンクとしてそのまま移す |
| （なし） | 拡大表示 | `column_field_4` | リンクが空で large 版がある → `yes`、それ以外 → `no`（オプションで固定値にもできる） |
| （なし） | アイコン表示 | `column_field_5` | `no` |
| `column_field_5` | 表示サイズ | `column_field_6` | そのまま移す |
| `column_field_6` | EXIF | — | 捨てる（スナップショットには残すのでロールバックで戻る） |
| `column_field_7`, `column_field_8` | 未使用 | `column_field_8` | 空にする |
| `column_size` / `align` / `attr` / `group` / `sort` / `parent_id` | レイアウト | 同じ | 変更しない（`column_id` も変えない） |

オプション「メディア本体に alt を設定」がオンのとき、**新規に作ったメディアだけ** `media_field_3`（alt）と `media_field_1`（キャプション）にユニットの値を入れます。再利用したメディアは書き換えません。

### 4.3 ファイルユニット → メディアユニット

| ファイルユニット | メディアユニット | 変換ルール |
|---|---|---|
| `column_field_2`（パス） | `column_field_1` | media_id（type は file / svg / image のどれにもなりうる） |
| `column_field_1`（キャプション） | `column_field_2` | そのまま移す |
| — | `column_field_5`（アイコン表示） | `yes`（ファイルユニットの見た目に近づける） |
| — | `column_field_4` | `no` |

type が `file` のメディアは非公開領域の `MEDIA_STORAGE_DIR` に保存され、パーマリンク経由でダウンロードされます。そのため変換後は、元の `/archives/...` 直リンクではなくダウンロード URL になります。外部から直リンクされている PDF などがある場合に備えて、元ファイルは削除しません。

### 4.4 画像フィールド → メディアフィールド

対象は `field`（オプションで `field_rev`）の行のうち、`field_key` が `{fd}@path` で、値が archives の相対パスに一致するものです。`field_sort` ごとに 1 件として扱います。

| 変換前（そのまま残す） | 書き込むキー（別名モードの場合） | 値 |
|---|---|---|
| `{fd}@path`, `@largePath`, `@x`, `@y`, `@alt`, … | `{dest}` | media_id（`field_type` は NULL） |
|  | `{dest}@media` | media_id（`field_type = 'media'`） |

- `{dest}` の既定は `{fd}_media`。フィールドごとに名前を指定することもできる。
- `field_sort`・`field_search`・所属 ID（`field_eid` / `cid` / `uid` / `bid` / `mid` / `unit_id`）・`field_blog_id` は元の行からコピーする。
- `{fd}@alt` は、メディアフィールドに上書き alt の仕組みが無いので、**新規メディアの `media_field_3`** に入れる。再利用したメディアの alt と違う場合は、アイテムに警告「alt 不一致」を記録する（値は書き換えない）。
- **フィールドグループ**: `field_key` が `@xxx` で、値に `{fd}` を含む行がグループ定義です。オプション「グループ定義に追記」がオンなら、同じ所属に `@xxx` = `{dest}` の行を追加します。グループのループの中で `{dest}@path` などが展開されるようになります。
- 同名モードの場合は `{dest} = {fd}` とし、旧キー（`@path` から `@squareFileSize` までと `@secret`）を削除する。

### 4.5 ファイルフィールド → メディアフィールド

画像フィールドとの見分け方: 同じ所属・同じ `{fd}` に `{fd}@baseName` があり、`@x` が無ければファイルフィールドとみなします。書き込むキーは §4.4 と同じ（`{dest}` と `{dest}@media`）です。`@downloadName` は、新規メディアのファイル名として使います。

### 4.6 判定しないもの・スキップするもの

| 状況 | 扱い | アイテムの理由コード |
|---|---|---|
| パスが空 | スキャン対象外 | — |
| 実ファイルが無い | スキップ | `SOURCE_NOT_FOUND` |
| ARCHIVES_DIR の外を指している（`../` やシンボリックリンク） | スキップ | `SOURCE_FORBIDDEN` |
| サイズ上限を超えている | スキップ | `SOURCE_TOO_LARGE` |
| 許可されていない MIME | スキップ | `SOURCE_INVALID_MIME` |
| エントリーが編集ロック中（`lock` テーブル） | スキップ（後で再実行） | `ENTRY_LOCKED` |
| スキャンした後にデータが変わった | スキップ | `STALE_SNAPSHOT` |
| `{dest}` が既に存在する（値が違う） | スキップ | `DEST_CONFLICT` |
| 多言語ユニットで一部の言語のパスだけ失敗した | そのユニット全体をスキップ（一部だけ変換しない） | `PARTIAL_SOURCE` |
| エントリーが別の処理（保存・リビジョン切り替え）で更新中でロックを取れない（§12） | スキップ（後で再実行） | `ENTRY_LOCKED` |
| メディア登録を別の処理が実行中で、名前付きロックを取れない | スキップ（後で再実行） | `MEDIA_REGISTER_FAILED` |

変換はできたが、確認してほしいものは警告として `item_reason` に残す（アイテムは `converted` のまま）。

| 状況 | 理由コード |
|---|---|
| 再利用したメディアの alt が、元のフィールドの alt と違う（値は書き換えない） | `ALT_MISMATCH` |
| 再利用したメディアが、データの所有ブログと別のブログのもの（コアの重複検出は実行ブログから選べる範囲で探すため） | `MEDIA_OTHER_BLOG` |

ロールバックとクリーンアップでは、`ROLLBACK_CONFLICT`（変換後に編集されて戻せない）、`CLEANUP_BLOCKED`（新しいフィールドが無いため旧データを消せない）を使う。

## 5. 処理フロー

> 処理フロー: 1. 設定（対象・書き込み先・オプション）→ 2. スキャン（ドライラン）→ 3. 確認 → 4. 実行（チャンク・中断／再開）→ 5. 結果（ログ・CSV・ロールバック）。設定を変えて再スキャン、スキップ・エラー分だけ再実行も可能。

### 5.1 ジョブの状態遷移

```
draft ──scan──▶ scanning ──▶ scanned ──run──▶ running ⇄ paused
                   │                            │
                   └──▶ failed ◀────────────────┤
                                                ▼
                                            completed ──rollback──▶ rolling_back ──▶ rolled_back
                                                │
                                                └──finalize──▶ finalized（スナップショットを捨てる。ロールバック不可）

```

どの状態の操作もチャンク単位（既定 20 アイテム／リクエスト）で、ブラウザ側のループから POST を繰り返し呼びます。タブを閉じると `paused` 相当のまま残り、ジョブ詳細画面の「再開」から続けられます。

### 5.2 1 アイテムの実行シーケンス

```
JobRunner::runChunk(job)
  └ （開始時）JobRepository::transition(…→running)  … 読み込み後に状態が変わっていれば StateException
  └ for item in ItemRepository::nextPending(job, 20)
      ├ EntrySerializer::run(eid)         … コアの UnitLock（GET_LOCK）。取れなければ ENTRY_LOCKED
      ├ DB::beginTransaction()
      ├ Locator::loadCurrent()           … 現在の行を取得
      ├ Snapshot::equals(item.before)?   … 違えば STALE_SNAPSHOT
      ├ EntryLockGuard::isLocked()?      … ロック中なら ENTRY_LOCKED
      ├ ArchiveMediaResolver::resolve(paths)  → media_id[]（PathMap → MediaSourceImporter）
      ├ Converter::apply(candidate, mids)    → after スナップショット
      ├ ItemRepository::markConverted(item, mids, after)
      ├ DB::commit()
      └ CacheInvalidator::collect(owner)   … チャンクの最後にまとめてクリア

```

メディアの登録（判定から登録まで）は、名前付きロック（`GET_LOCK`）で他の処理と直列化します。ファイルの保存はトランザクションの外で起きる副作用です。DB をロールバックすると「孤立メディア」ができる可能性がありますが、パス→メディア対応表に先に登録しておくので、次の実行で再利用されて重複しません。

## 6. 画面設計

UI は React 19 + `@ablogcms/components`（3.2.37 時点）で作ります。画面の構成は 4 パターン用意し、モックで比べられるようにしました。どれを選ぶかは §15 の未決事項です。

UI モック（4 パターン）はローカル用の HTML（`docs/ui-mock.html`）で確認します。HTML はリポジトリにコミットしません。

### 6.1 画面一覧（どのパターンでも必要な画面要素）

| 画面要素 | 内容 | 主な使用コンポーネント |
|---|---|---|
| 対象設定 | 変換対象の種類（4 種）、ユニット定義のマッピング、フィールド名のマッピング、オプション | `Fieldset`, `Field`/`FieldLabel`/`FieldControl`, `Checkbox`, `Radio`, `Select`, `RichSelect`, `Input`, `Table` |
| スキャン結果サマリー | 種類別の件数、新規／再利用の見込み、スキップの見込みと理由 | `Table`, `Badge`, `Alert` |
| アイテム一覧 | 候補ごとの所属・旧パス・サムネイル・状態。絞り込み、並び替え、除外の一括指定 | `Dataview`（tanstack table v9 ベース）, `Filter`, `Pagination`, `DropdownMenu` |
| 実行進捗 | 全体と種類別の進捗、ログの流し見、一時停止／再開 | `ProgressBar`（`striped` / `animated`）, `ButtonV2`, `Spinner` |
| 確認ダイアログ | 実行前の最終確認、同名モードの警告、ロールバックの確認 | `Modal` / `ModalHeader` / `ModalBody` / `ModalFooter` |
| 結果 | 成功・スキップ・エラーの件数、CSV ダウンロード、ロールバック、テンプレート移行のヒント（変数名の対応表） | `Announce`, `Toast`, `CopyToClipboard`, `SyntaxHighlight` |
| ジョブ履歴 | 過去のジョブ一覧と状態 | `Table`, `Badge`, `Pagination` |

### 6.2 テンプレート移行のヒント（データのみ移行を補う）

テンプレートは書き換えませんが、変換結果の画面には「旧変数 → 新変数」の対応表を**表示だけ**します。コピーして使えます。

```
<!-- 旧 -->  <img src="%{ARCHIVES_DIR}{main_image@path}" alt="{main_image@alt}">
<!-- 新 -->  <img src="{main_image_media@path}" alt="{main_image_media@alt}">
<!-- 入力欄 --> <input type="hidden" name="main_image_media" value="{main_image_media}">
           <input type="hidden" name="main_image_media:extension" value="media">  … メディア選択 UI は公式スニペット参照
```

### 6.3 フロントエンドの構成

```
plugins/ArchivesToMedia/
├ frontend/                     … TypeScript + React のソース（ビルド対象。配信しない）
│  ├ main.tsx                   … #a2m-root にマウント。初期値は JSON の script タグから受け取る
│  ├ api/client.ts              … fetch ラッパー（ACMS_POST, formToken, JSON）
│  ├ hooks/
│  │  ├ useJob.ts               … ジョブの取得・ポーリング
│  │  └ useChunkRunner.ts       … チャンク POST のループ（AbortController で一時停止）
│  ├ features/settings/         … TargetSettings, UnitTypeMapping, FieldNameMapping, OptionsFieldset
│  ├ features/scan/             … ScanSummary, ItemDataview
│  ├ features/run/              … RunProgress, LiveLog
│  ├ features/result/           … ResultSummary, TemplateHints, RollbackModal
│  └ pages/                     … パターンに応じた画面構成（Wizard / JobList+JobDetail など）
└ src/assets/                   … 配信されるファイル（src/ が extension/plugins/ArchivesToMedia にリンクされるため）
   ├ css/archives-to-media.css  … 素の CSS（Sass は使わない。ビルドもしない）
   └ dist/archives-to-media.js  … Vite の出力（ES module 1 ファイル）
```

このリポジトリの `tools/setup.js` は `plugins/{Name}/src` を `ablogcms/extension/plugins/{Name}` にシンボリックリンクします。そのため、ブラウザから読み込むファイルは必ず `src/` の下に置きます。

- **JS**: Vite のライブラリモードで `src/assets/dist/archives-to-media.js`（ES module）を出力します。React 19 と `@ablogcms/components` はバンドルに含めます（管理画面側の React を共有する公開 API が無いため）。
- **CSS**: Sass は使わず、素の CSS ファイル（`src/assets/css/archives-to-media.css`）を 1 枚そのまま配信します。見た目の大半は、管理画面の `acms-admin.css` にある、コンポーネントが出力するクラス（`acms-admin-*`）でまかなえます。独自 CSS は、components に無い部品（ステッパー、統計タイル、ログ表示）だけにします。
   - クラスはすべて `acms-admin-a2m-` 接頭辞にする（管理画面のクラス体系 `acms-admin-*` に並べつつ、`a2m` を挟んでコアのクラスと衝突させない）。
   - 色や余白は `:root` ではなく `.a2m-root` にスコープしたカスタムプロパティで持つ（管理画面のほかの画面を汚さない）。
   - 入れ子が必要な箇所は、ネイティブの CSS Nesting ではなくフラットなセレクタで書く（管理画面の対応ブラウザに合わせる）。
   - CSS を JS から `import` しない（Vite の CSS 処理を通さず、アセット API で `<link>` として読ませる）。

- **lint**: JS/TS は Prettier と ESLint、CSS は Stylelint の `stylelint-config-standard`（SCSS 用の設定は使わない）。

### 6.4 管理画面への組み込み（Twig + InjectTemplate + アセット API）

画面の器は Twig テンプレートで描き、InjectTemplate の callable で `admin-main` に差し込みます。JS と CSS は**アセット API**（`Acms\Services\Facades\Asset`）で登録します。`afterBuild` での文字列置換や、テンプレートに直書きする `<script>` は使いません。

```
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Facades\Asset;
use Acms\Services\Facades\Application as App;
use Acms\Services\Facades\Twig;

public function init()
{
    // テンプレートディレクトリ（ファイルシステムのパスなので PLUGIN_LIB_DIR）
    Twig::addTemplatePath(PLUGIN_LIB_DIR . 'ArchivesToMedia/template', 'archives_to_media');

    $this->registerServices();

    // 自分の管理画面を開いているときだけ差し込み・アセット登録を行う
    if (!defined('ADMIN') || !in_array(ADMIN, ['app_archives_to_media_index', 'app_archives_to_media_job'], true)) {
        return;
    }

    $inject = InjectTemplate::singleton();
    $inject->add('admin-topicpath', static fn (): string => Twig::renderTemplate('@archives_to_media/admin/topicpath.twig'));
    // 初期データは PHP 側で JSON_HEX_TAG などを付けて文字列にし、Twig では |raw で差し込む（実装は ServiceProvider::renderAdminMain）
    $inject->add('admin-main', fn (): string => $this->renderAdminMain());

    // URL は「/{DIR_OFFSET}相対パス」。ローカルファイルなので version（キャッシュ対策のクエリ）は自動で付く
    $base = '/' . DIR_OFFSET . PLUGIN_DIR . 'ArchivesToMedia/assets/';

    Asset::style('archives-to-media-style', $base . 'css/archives-to-media.css', [
        'position' => 'head',
    ]);
    Asset::script('archives-to-media', $base . 'dist/archives-to-media.js', [
        'deps' => ['acms-admin'],        // 管理画面のスクリプトの後に読み込む
        'position' => 'body_end',
        'attrs' => ['type' => 'module'],
    ]);
}
```

```
{# template/admin/main.twig — ページ全体ではなく中身の断片だけを書く #}
<header><h1 class="acms-admin-admin-title">アーカイブ → メディア変換</h1></header>
<div id="a2m-root" class="a2m-root">
  <noscript>この画面を使うには JavaScript を有効にしてください。</noscript>
</div>
<script type="application/json" id="a2m-bootstrap">{{ bootstrap_json|raw }}</script>
```

- ハンドルはプラグイン名を接頭辞にして、ほかのプラグインと重複させない（重複すると後から登録した方が無視される）。
- 標準ハンドル（`acms-admin` など）を `dequeue()` しない。
- 初期データは JSON の script タグで渡し、React 側の `main.tsx` で `JSON.parse` する。`json_encode` は `JSON_HEX_TAG` 相当のエスケープをかけて、`</script>` の混入を防ぐ。
- `checkRequirements()` では、アセット API（3.2.29 以降）が使えることを `class_exists(\Acms\Services\Facades\Asset::class)` で確かめる。

## 7. データ設計

インストール時（`ServiceProvider::install()`）に 3 テーブルを作り、アンインストール時に削除します。

### 7.1 `a2m_job` — 変換ジョブ

| カラム | 型 | 説明 |
|---|---|---|
| `job_id` | int(11) PK | `SQL::nextval('a2m_job_id', dsn(), true)`（`sequence_plugin`） |
| `job_status` | varchar(16) | draft / scanning / scanned / running / paused / completed / failed / rolling_back / rolled_back / finalized |
| `job_options` | longtext | JSON: 対象種類、ユニット型マッピング、フィールド名マッピング、各オプション（§7.4） |
| `job_total` / `job_converted` / `job_skipped` / `job_error` | int(11) | 集計値（表示用のキャッシュ） |
| `job_scan_cursor` | text | 再開位置（操作ごとに形が違う。ほかの操作のカーソルを読んでも、無視して先頭からになる）。スキャンは `{s: スキャナの番号, c: スキャナのカーソル}`、クリーンアップは `cleanup:{item_id}`、再実行は `requeue:{item_id}`、確定は `finalize:{item_id}`、ロールバックは `{"p":"items","before":N}` / `{"p":"media","after":N,"failed":[…],"extra":N}` |
| `job_message` | text | 失敗・削除失敗などの利用者向けメッセージ |
| `job_user_id` | int(11) | 実行したユーザー |
| `job_blog_id` | int(11) MUL | 実行ブログ |
| `job_created_datetime` / `job_started_datetime` / `job_finished_datetime` | datetime |  |

### 7.2 `a2m_item` — 変換アイテム（1 ユニット or 1 フィールド値）

| カラム | 型 | 説明 |
|---|---|---|
| `item_id` | int(11) PK |  |
| `item_job_id` | int(11) MUL |  |
| `item_target` | varchar(16) | `unit_image` / `unit_file` / `field_image` / `field_file` |
| `item_table` | varchar(16) | `column` / `column_rev` / `field` / `field_rev` |
| `item_locator` | varchar(512) | JSON。ユニット: `{"column_id","rev_id"}`、フィールド: `{"key","sort","owner":"eid","owner_id","unit_id","rev_id","blog_id"}` |
| `item_owner_type` / `item_owner_id` | varchar(8) / int(11) | 絞り込みと表示用（entry / category / blog / user / module） |
| `item_source_paths` | text | JSON 配列（多言語ユニットは複数） |
| `item_preview` | varchar(16) | ドライランの見込み: `new` / `reuse` / `mixed` / `skip` / `unknown`（時間の予算を超えたため、ファイル全体のハッシュ計算を後回しにした。実行時に判定する） |
| `item_media_ids` | text | 実行後の media_id（カンマ区切り） |
| `item_status` | varchar(16) MUL | `pending` / `excluded` / `converted` / `skipped` / `error` / `rolled_back` |
| `item_reason` | varchar(32) | §4.6 の理由コード、または `ALT_MISMATCH` などの警告 |
| `item_message` | text | 人が読むための詳細メッセージ |
| `item_locator_hash` | char(40) | `item_locator` の SHA-1。同じジョブ内で同じ所在を二重に登録しないためのユニークキー（スキャンの再開を冪等にする） |
| `item_before` / `item_after` | longtext | 変換前後の行スナップショット（JSON）。ロールバックと競合検知に使う。ジョブを確定（§10.5）すると NULL にする |
| `item_cleanup` | longtext | クリーンアップで消した旧キーの行（JSON）。ロールバックで戻す |
| `item_blog_id` | int(11) | データの所有ブログ |
| `item_updated_datetime` | datetime |  |

インデックス: UNIQUE `(item_job_id, item_locator_hash)`、`(item_job_id, item_status)`、`(item_job_id, item_target)`、`(item_job_id, item_id)`（CSV などを item_id 順に読む用）、`(item_job_id, item_target, item_status, item_preview, item_reason)`（種類別の集計用）。

### 7.3 `a2m_path_map` — パス → メディア対応表（ジョブをまたいで使う）

| カラム | 型 | 説明 |
|---|---|---|
| `map_blog_id` | int(11) | メディアの所属ブログ |
| `map_source_path` | varchar(512, utf8mb4_bin) | archives 相対パス（通常サイズのパスに正規化。`large-` は除く） |
| `map_path_hash` | char(40) | `map_source_path` の SHA-1（ユニークキーはこちらに張る） |
| `map_media_id` | int(11) |  |
| `map_created_by_job` | int(11) NULL | このプラグインで新規作成したメディアならジョブ ID、再利用なら NULL |
| `map_created_datetime` | datetime |  |

UNIQUE `(map_blog_id, map_path_hash)`。使い道は 3 つです。

- 同じパスを 2 回目以降に登録しない（ハッシュ照合より速い）
- ロールバック時に「このジョブが作ったメディア」を特定する
- 将来、本文 HTML を置換するときの辞書にする

### 7.4 `job_options` の JSON スキーマ

```
{
  "targets": ["unit_image", "unit_file", "field_image", "field_file"],
  "unitTypeMap": { "image": "media", "image_2": "media", "file": "media_file" },
  "field": {
    "mode": "suffix",                 // "suffix" | "same" | "custom"
    "suffix": "_media",
    "custom": { "main_image": "main_visual" },
    "includeKeys": [],                // 空なら全フィールド
    "excludeKeys": ["og_image"],
    "appendToGroup": true,
    "removeLegacyKeys": false         // mode=same のときだけ true にできる
  },
  "owners": ["entry", "category", "blog", "user", "module", "unit"],
  "includeRevisions": false,
  "includeChildBlogs": false,
  "entryStatus": ["open", "close", "draft"],   // trash は既定で除く
  "media": { "fillAltOnNew": true, "enlarged": "auto", "tags": ["archives-import"] },
  "chunkSize": 20
}
```

`media.tags` を指定すると、新規作成したメディアに `Media::saveTags()` でタグを付けます。メディア管理画面で絞り込めて便利です。

## 8. クラス設計

### 8.1 ディレクトリ構成

公式のスケルトン [appleple/acms-plugin-skeleton](https://github.com/appleple/acms-plugin-skeleton)（`composer create-project ablogcms/plugin-skeleton`）から始め、その構成に合わせて、ロジックは `src/Services/` にまとめます。GET / POST のハンドラは CSRF・セッション・グローバル変数に依存して直接テストしにくいので、薄く保って Services を呼ぶだけにします。

```
plugins/ArchivesToMedia/
├ composer.json / phpunit.xml.dist / phpstan.neon.dist / phpcs.xml.dist   … スケルトンのまま
├ package.json / vite.config.ts / tsconfig.json / .stylelintrc.json
├ src/
│  ├ ServiceProvider.php             … Twig 登録・InjectTemplate・アセット API・DI・install/uninstall
│  ├ POST/ArchivesToMedia/           … 入口（ACMS_POST, トークン必須。ApiEndpoint を呼ぶだけ）
│  │  ├ Api.php（action で振り分ける JSON API）  ExportCsv.php
│  ├ Services/
│  │  ├ Domain/
│  │  │  ├ TargetType.php  ItemStatus.php  JobStatus.php  ReasonCode.php   … enum
│  │  │  ├ JobOptions.php               … 値オブジェクト（JSON ⇄）
│  │  │  ├ Candidate.php                … スキャン結果 DTO
│  │  │  └ Locator/ LocatorInterface.php  ColumnLocator.php  FieldLocator.php
│  │  ├ Scanner/
│  │  │  ├ ScannerInterface.php
│  │  │  ├ UnitImageScanner.php  UnitFileScanner.php
│  │  │  ├ FieldImageScanner.php FieldFileScanner.php
│  │  │  └ ScanCoordinator.php          … スキャナを順に回し、カーソルを管理
│  │  ├ Converter/
│  │  │  ├ ConverterInterface.php
│  │  │  ├ UnitImageConverter.php UnitFileConverter.php
│  │  │  ├ FieldMediaConverter.php      … 画像・ファイルフィールド共通
│  │  │  ├ FieldNameResolver.php        … suffix / same / custom
│  │  │  └ FieldGroupAppender.php
│  │  ├ Media/
│  │  │  ├ ArchiveMediaResolver.php     … PathMap → MediaSourceImporter
│  │  │  ├ SourcePathSelector.php       … large- 優先
│  │  │  ├ BlogOwnershipFixer.php
│  │  │  └ MediaMetaWriter.php          … 新規メディアの alt・キャプション・タグ
│  │  ├ Job/
│  │  │  ├ JobRunner.php  Rollbacker.php  JobFactory.php  ChunkResult.php
│  │  ├ Repository/
│  │  │  ├ JobRepository.php  ItemRepository.php  PathMapRepository.php
│  │  ├ Admin/
│  │  │  └ AdminBootstrap.php           … 画面の初期データ（bid・トークン・エンドポイント・定義一覧）
│  │  └ Support/
│  │     ├ Snapshot.php  EntryLockGuard.php  CacheInvalidator.php  Schema.php
│  ├ template/admin/                  … Twig（断片のみ）
│  │  ├ main.twig  topicpath.twig
│  └ assets/
│     ├ css/archives-to-media.css     … 素の CSS
│     └ dist/archives-to-media.js     … ビルド成果物（リリース zip に含める）
├ frontend/                          … TypeScript + React のソース（§6.3）
├ tests/phpunit/
│  ├ Unit/          … TestCase（DB 不要）
│  └ Integration/   … DatabaseTestCase（DB あり・自動ロールバック）
├ scripts/          … スケルトン同梱のリリース用スクリプト（composer package / release:*）
└ .github/workflows/ test.yml  release.yml   … スケルトンのものに npm のビルド・lint を足す
```

### 8.2 主要インターフェース

```
interface ScannerInterface
{
    public function target(): TargetType;

    /**
     * カーソルの続きから最大 $limit 件の候補を返す。
     * @return array{candidates: list<Candidate>, nextCursor: ?string}
     */
    public function scan(JobOptions $options, int $blogId, ?string $cursor, int $limit): array;
}

interface ConverterInterface
{
    public function supports(TargetType $target): bool;

    /** 現在行のスナップショットを返す（スキャン時の before と実行直前の比較に使う） */
    public function snapshot(LocatorInterface $locator): array;

    /**
     * @param list<int> $mediaIds  item_source_paths と同じ順序
     * @return array 変換後スナップショット（item_after）
     */
    public function apply(Candidate $candidate, array $mediaIds, JobOptions $options): array;

    /** item_before を書き戻す。現在行が item_after と違えば ConflictException */
    public function restore(LocatorInterface $locator, array $before, array $after): void;
}

final class ArchiveMediaResolver
{
    public function __construct(
        private MediaSourceImporter $importer,      // localPathBase = SCRIPT_DIR . ARCHIVES_DIR
        private PathMapRepository $pathMap,
        private SourcePathSelector $selector,
        private BlogOwnershipFixer $ownership,
        private MediaMetaWriter $metaWriter,
    ) {}

    /** @throws SourceException 理由コード付き */
    public function resolve(string $archivesPath, int $ownerBlogId, int $jobId, MediaMeta $meta): ResolvedMedia;

    public function preview(string $archivesPath, int $ownerBlogId): MediaImportPreview;
}
```

### 8.3 クラスの責務

| クラス | 責務 | 依存 |
|---|---|---|
| `ServiceProvider` | Twig テンプレートパスの登録、自分の画面のときだけ InjectTemplate（callable）とアセット API で差し込む（§6.4）、DI 登録、install / uninstall（`Schema` 経由で DDL）、要件チェック（PHP 8.1、アセット API の有無、ARCHIVES_DIR 読み取り、MEDIA_* 書き込み） | `Twig`, `InjectTemplate`, `Asset`, `Container` |
| `AdminBootstrap` | 画面の初期データ（BID、formToken、各エンドポイント URL、メディアユニット定義、画像・ファイル系フィールドのキー）を配列で組み立てる | `Config`, `SQL` |
| `UnitImageScanner` | `column`（`column_rev`）から `column_type` が `image` 系かつ `column_field_2 != ''` の行を `column_id` 順に取得し、エントリーの状態・ブログで絞り込む | `SQL`, `DB` |
| `FieldImageScanner` | `field_key LIKE '%@path'` を所属単位で取得し、同じ所属の `@x` の有無で画像かファイルかを判定する。`{dest}@media` が既にある値は除く | `SQL`, `DB` |
| `ScanCoordinator` | スキャナを順に回し、候補ごとに `snapshot()` と `resolver->preview()` を取って `a2m_item` を作る | Scanner[], Converter[], Resolver, ItemRepository |
| `UnitImageConverter` | §4.2 のマッピングで `column` を UPDATE する（`column_id` は変えない） | `SQL` |
| `FieldMediaConverter` | §4.4 / §4.5。所属単位で `{dest}` と `{dest}@media` を INSERT し、グループに追記する。同名モードなら旧キーを DELETE する | `FieldNameResolver`, `FieldGroupAppender` |
| `ArchiveMediaResolver` | パス対応表を引き、無ければ large 版を選んでコアに登録し、所属ブログを補正し、メタデータを書き、対応表に記録する | Core `MediaSourceImporter` |
| `BlogOwnershipFixer` | 登録したメディアの `media_blog_id` がデータ所有ブログと違えば UPDATE する（再利用したメディアは対象外） | `SQL` |
| `JobRunner` | ロックを取り、pending のアイテムをチャンクで処理し、集計を更新して `ChunkResult` を返す | Resolver, Converter[], `EntryLockGuard`, `CacheInvalidator` |
| `Rollbacker` | converted のアイテムを新しい順に `restore()` する。オプションで、そのジョブが作ったメディアのうち参照が無いものを `Media::deleteItem()` で削除する | Converter[], `PathMapRepository` |
| `CacheInvalidator` | チャンク内で触った所属のフィールドキャッシュ（`FieldRepository::deleteFieldCache`）とページキャッシュを、チャンク終了時にまとめてクリアする | Core Cache |
| `EntryLockGuard` | コアの `lock` テーブルで編集中のエントリーを判定する | `SQL` |

### 8.4 サービスコンテナへの登録

プラグインの `ServiceProvider` では `register()` は呼ばれず、`init()` だけが呼ばれます。そのため、登録はすべて `init()` の中で `Application` ファサード（`App::singleton()` / `App::bind()`）を使って行います。エイリアスは `archives-to-media.` 接頭辞で揃えます。

```
use Acms\Services\Facades\Application as App;
use Acms\Services\Common\Lock;
use Acms\Services\Media\MediaSourceImporter;
use Acms\Services\Storage\Filesystem;

private function registerServices(): void
{
    // コアの media.source-importer は CSV インポート用のベースで登録済みなので流用せず、
    // ARCHIVES_DIR をベースにした専用インスタンスを作る
    App::singleton('archives-to-media.source-importer', static function () {
        return new MediaSourceImporter(
            new Filesystem(),
            App::make('media'),
            SCRIPT_DIR . ARCHIVES_DIR,
            (int) config('archives_to_media_max_filesize', 50 * 1024 * 1024),
        );
    });

    App::singleton('archives-to-media.path-map', PathMapRepository::class);
    App::singleton('archives-to-media.media-resolver', static fn () => new ArchiveMediaResolver(
        App::make('archives-to-media.source-importer'),
        App::make('archives-to-media.path-map'),
        new SourcePathSelector(),
        new BlogOwnershipFixer(),
        new MediaMetaWriter(),
    ));
    App::singleton('archives-to-media.job-runner', static fn () => new JobRunner(/* … */));
    App::singleton('archives-to-media.rollbacker', static fn () => new Rollbacker(/* … */));
    App::singleton('archives-to-media.admin-bootstrap', AdminBootstrap::class);

    // 多重実行防止（WXRImport・コアの webp/csv インポートと同じ Common\Lock パターン）。
    // ブログ単位でロックするので、BID を受け取るファクトリにする
    App::bind('archives-to-media.lock', static fn () => new Lock(CACHE_DIR . 'archives-to-media-lock-' . BID));
}
```

- **コアのサービスは差し替えない。** `App::override()` / `App::extend()`（3.2.30 以降）は使いません。`media` や `media.duplicate-detector` の挙動はコアのままにして、プラグイン側はそれを呼ぶだけにします。サイト全体のメディア登録に影響を出さないためです。
- 同じ理由で、コアのエイリアス（`media.source-importer` など）を `bind()` / `singleton()` で再登録しません。再登録すると、ほかのプラグインの override / extend も消えてしまいます。
- GET / POST のハンドラは `App::make('archives-to-media.job-runner')` のように取得し、自分では `new` しません。テストではコンテナに差し替えを登録して、偽物を入れられるようにするためです。
- コアの Facade（`Media`、`Database`、`Config` など）は、そのまま Facade 経由で使います。

## 9. エンドポイント

入口は 2 つの ACMS_POST モジュールです。どちらも `formToken`（CSRF）をコアの `ACMS_POST` が検証し、`sessionWithAdministration()` で管理者であることを確かめます。

| モジュール | 用途 | 出力 |
|---|---|---|
| `ACMS_POST_ArchivesToMedia_Api` | `action` で操作を振り分ける JSON API | JSON（エラーは HTTP ステータス 403 / 404 / 409 / 422 / 500 と `{error, code}`） |
| `ACMS_POST_ArchivesToMedia_ExportCsv` | 結果 CSV（jobId） | UTF-8 BOM 付き CSV（数式として解釈されうる値は無害化） |

`Api` の `action`（`ApiEndpoint::ACTIONS`）:

| action | 入力 | 内容 |
|---|---|---|
| `definitions` | — | メディアユニットの種類、元ユニット種類、フィールド名（管理権限のあるブログだけ） |
| `jobs` / `job` | page / jobId | 一覧 / 概要（件数・集計・クリーンアップ対象） |
| `create` / `update` | options(JSON) / jobId | ジョブの作成 / オプション更新（再スキャン用にアイテムを消す） |
| `scan` | jobId | スキャン 1 チャンク |
| `exclude` | jobId, ids または filter, excluded | 除外の切り替え |
| `items` | jobId, target, status, reason, owner, preview, q, sort, desc, page, perPage | アイテム一覧 |
| `run` / `pause` | jobId | 実行 1 チャンク / 一時停止 |
| `requeue` | jobId | スキップ・エラー分を pending に戻す（時間の予算で区切り、`done` が false の間は続けて呼ぶ） |
| `rollback` | jobId, deleteCreatedMedia | ロールバック 1 チャンク |
| `cleanup` | jobId | 旧データのクリーンアップ 1 チャンク |
| `finalize` | jobId | 変換を確定（§10.5） |
| `delete` | jobId | ジョブの削除 |

アイテム一覧のページ番号には上限（10000）があります。

## 10. 冪等性・再実行・ロールバック

### 10.1 冪等性

- スキャナは「既に変換済み」のものを候補に入れない。ユニットは `column_type` が media 系、フィールドは同じ所属・同じ sort に `{dest}@media` があるもの。
- 同じパスは `a2m_path_map` で必ず同じメディアに解決し、さらにコアの `media_hash` でも重複を再利用する（二重の防御）。

### 10.2 再実行

ジョブ詳細の「スキップ・エラー分を再実行」は、対象アイテムを `pending` に戻し、スナップショットを取り直してから実行します。テンプレート更新後に再保存で消えた分は、新しいジョブのスキャンで拾います。

### 10.3 ロールバック

- 位置はジョブのカーソルに持つ（items フェーズ → media フェーズ）。エントリーが別の処理で更新中でロックを取れないアイテムは、エラーにせず `converted` のまま残してカーソルで飛ばし、最後まで進んだあとも残っていれば、ジョブを `rolling_back` のまま（`job_message` に理由を入れて）区切り、「あとでもう一度ロールバック」できるようにする（`paused` は変換の一時停止を表す状態で、`run` が通ってしまうので使わない）。メディア削除の途中（変換済みが 0 件）でも、`rolling_back` のジョブにはロールバックの再開ができる。ロールバック中に例外が起きたときも `failed` にはせず、`rolling_back` のまま理由を `job_message` に残す（`failed` は変換の実行の失敗だけ）。前のロールバックが media フェーズで止まったあとに変換済みが増えていたら、先に items フェーズでそれを戻す。
- 「作成メディアを削除」の参照判定と削除は、200 件ずつチャンクに区切って進める。

- `converted` のアイテムを item_id の降順で `restore()` する。現在行が `item_after` と違う場合（変換後に編集された）は戻さず `ROLLBACK_CONFLICT` とする。
- オプション「このジョブで作成したメディアを削除」: `map_created_by_job = jobId` のメディアのうち、ユニット（media 系の `column_field_1`）とフィールド（`%@media`）のどこからも参照されていないものだけ削除し、対応表の行も消す。既定はオフ。

### 10.4 旧データのクリーンアップ（別機能・任意）

テンプレートの移行が終わった後で、別名モードで残した旧キー（`{fd}@path` 系）を削除します。アイテム単位で記録しておくので、どのジョブのものかを指定して消せます。archives の実ファイルは、どのモードでも削除しません（他の参照や外部リンクがありうるため）。

### 10.5 変換の確定（finalize）

`a2m_item` の `item_before` / `item_after` / `item_cleanup` は行全体のスナップショットで、数十万件のサイトでは大きくなります。変換結果を確認し、テンプレートの移行も終わったら、`finalize` で完了済み（`completed`）のジョブを確定できます。

- スナップショットを NULL にして容量を回収し、ジョブを `finalized` にする。
- 件数が多くても 1 リクエストで終わらせないよう、2000 件ずつ・時間の予算で区切る。途中で止まっても、確定済み（`finalized`）のジョブに対して `finalize` を呼び直せば、残りを回収できる（`done` が true になるまで繰り返す）。
- 別名モードで旧データのクリーンアップが済んでいない（`cleanable` > 0）ジョブは、確定するとクリーンアップできなくなるため、`keepLegacyKeys`（旧データを残したまま確定する確認）が無ければ拒否する。
- 確定後は、ロールバック・再実行・クリーンアップはできない。結果の件数と CSV、作成したメディアは残る。
- 確定済みのジョブは、変換済みのアイテムが残っていても削除できる。アンインストールの「ロールバックできる変換済みアイテムが残っている」判定にも数えない。

## 11. エラー処理・ログ

- アイテム単位の失敗はそのアイテムの `error` / `skipped` に閉じ込め、ジョブは止めない。
- チャンク全体の失敗（DB 接続断など）では、ジョブを `failed` にし、例外メッセージを返す。再開ボタンで続きからやり直せる。
- ジョブの開始・完了・ロールバックは `AcmsLogger::info` で監査ログに残す（件数、オプション、ユーザー）。アイテム単位のエラーは `AcmsLogger::notice`。
- `MediaSourceImportException` のメッセージを理由コードに対応させる（存在しない → `SOURCE_NOT_FOUND` など）。画面・CSV・ジョブに記録するのは理由コードの文言だけで、コアの文言（サーバーのパスなどを含みうる）は監査ログにだけ残す。
- 画面・CSV・ジョブの記録に出してよい文言は、`PublicMessage` を実装した例外（`SourceException`、`StateException`、`InvalidInputException` など）のものだけ。それ以外の例外（DB やコアが投げるもの）は定型文にし、詳細を監査ログに残す（`ErrorMessage`）。
- DB への書き込みで、列に入らない値を黙って切り詰めない。コアは接続ごとに `sql_mode` を非 strict にするので、長さを PHP 側で確かめ、超えるときは例外にする（スキャンでは、パスを外したエラーのアイテムとして残す）。

## 12. 権限・セキュリティ

- 使えるのは管理者（`sessionWithAdministration()`）だけ。子ブログを含めるジョブは、書き換え系の操作（スキャン・実行・再実行・ロールバック・クリーンアップ・確定）のたびに、含める全ブログへの管理権限を確認する。読み取り（アイテム一覧・CSV・定義）は、操作する人に管理権限のある子ブログの行だけを返す。
- 全 POST で CSRF トークンを検証し、`jobId` の `job_blog_id` が現在の BID（または権限のある子孫）であることを確認する。
- ファイルパスは DB の値ですが、改ざんされている可能性を前提に、必ずコアの `validateDirectoryTraversalPath`（realpath 後の再検証込み）を通す。
- SVG はコアの `sanitizeSvg` の経路に乗るので、そのまま安全側に倒れる。
- 実行中は `Common\Lock`（`flock`）でブログ単位の多重実行を防ぐ。プロセスが終わればロックは自動で外れるので、ブラウザが閉じられても固まらない。
- 状態を変える操作（実行・ロールバックの開始、再スキャン、確定）は、`JobRepository::transition()` で「今の状態が期待どおりか」を行ロックで確かめながら進める。別のタブで状態が変わっていれば `StateException` で拒否し、状態を上書きしない。
- エントリーに属する書き込み（ユニット、エントリー所属のフィールド、ロールバック、クリーンアップ）は、コアの `UnitLock`（MySQL の `GET_LOCK`、エントリー単位）の中で行う。コアのエントリー保存・リビジョン切り替えと直列化するため。編集ロック（`entry_lock`）は Professional 版以上の機能なので、これとは別に必要。
- メディアの登録（重複の判定から登録まで）は、名前付きロックで直列化する。別のブログで動く同じ処理が同じ内容を同時に登録しても、既存のメディアを新規作成と取り違えない。

## 13. 性能・運用

- 1 リクエストの処理目安は 20 アイテム、または 20 秒。経過時間を見て早めに打ち切る（`max_execution_time` 30 秒の環境向け）。
- スキャンはキーセットページングで行い、OFFSET は使わない。並び順と比較には、主キー（`column_id`）や所属 ID の素のカラムを使う（`BINARY` や計算式を通すとインデックスが使えず、チャンクごとに全件の並べ替えになる）。フィールドは所属の種類ごとに所属 ID の昇順で「`@path` を持つ所属」を取り、その所属の行をまとめて読む。
- スキャンの候補の登録は、5 件ずつ時間を確かめながら行う（ドライランはファイル全体のハッシュを計算するため）。
- アイテム一覧は、並べ替えと切り出しを `item_id` だけで行い、あとから行を読む。絞り込みが無ければ総数を数え直さない。CSV は `item_id` のキーセットで読む。
- ロールバックの「作成メディアを削除」の参照判定は、メディアを 200 件ずつまとめて、テーブルごとに 1 回の走査で調べる。
- ドライランの `importDryRun` はファイル全体のハッシュ計算をするので、スキャンも同じくチャンク処理にする。
- 本番前にはステージングで、DB バックアップ → 実行 → 表示確認 → ロールバックまでを一通り試すことを、画面と README の両方で案内する。

## 14. テスト方針

公式のテスト基盤 `ablogcms/testing-framework`（`require-dev` で `3.2.*`）を使います。ハンドラ（GET / POST）は直接テストせず、Services を対象にします。

| レベル | 基底クラス | 対象 | 内容 |
|---|---|---|---|
| Unit | `Acms\TestingFramework\TestCase` | `SourcePathSelector`, `FieldNameResolver`, `JobOptions`, 各 Converter の行マッピング（純粋関数部分） | 多言語の区切り、空値、large の有無、名前衝突 |
| Integration | `DatabaseTestCase`（テストごとに自動ロールバック） | Scanner、Converter、`JobRunner`、`Rollbacker`、`ServiceProvider` の DI 登録 | `BlogSeeder` / `EntrySeeder` / `FieldSeeder` / `MediaSeeder` でデータを作り、フィクスチャの画像を一時 ARCHIVES_DIR に置く。スキャン → 実行 → 再スキャンで 0 件 → ロールバックで行が完全一致、の往復を確かめる |
| Integration | `DatabaseTestCase` | 表示 | 変換後のフィールドを `injectMediaField` に通し、別名モードで旧キーが変わらないことを確かめる |
| E2E | — | 管理画面 | Playwright でウィザードを通しで操作し、途中でタブを閉じて再開できることを確かめる |

- **静的解析**: `phpstan/phpstan ^2.1` と、テスト基盤同梱の `vendor/ablogcms/testing-framework/phpstan/extension.neon` を読み込み、最初から `level: max` で回す。本体のレガシー API の型が緩い箇所だけ、理由付きの `@phpstan-ignore` を使う。
- **コーディング規約**: `phpcs`（PSR-12）。CSS は Stylelint、TS は ESLint と Prettier。
- **CI**: GitHub Actions の matrix で、PHPUnit だけを PHP × a-blog cms マイナーバージョン（`appleple/acms` イメージ）で回す。phpcs、PHPStan、フロントのビルドと lint は 1 ジョブにまとめる。
- このリポジトリでは、プラグインはサブモジュールとして `extension/plugins/` にシンボリックリンクしているので、WXRImport と同じ置き方で動かす。

## 15. 未決事項

| # | 論点 | 提案 |
|---|---|---|
| 1 | UI の画面構成パターン | モック（ローカルの `docs/ui-mock.html`）の A〜D から選ぶ。 |
| 2 | フィールドの既定の書き込み先 | 同名（画面の既定。実行前に警告付きの確認ダイアログを出す）。別名（`{fd}_media`）は旧キーを残して段階的に移行する場合のオプション（§3.1）。 |
| 3 | リビジョンも変換するか | 既定はオフ。承認ワークフローを使っているサイトだけオンにする。 |
| 4 | 拡大表示（`enlarged`）の既定値 | auto（リンクが空で large 版があれば yes）。テーマのメディアユニットテンプレートの挙動を確かめて決める。 |
| 5 | 本文 HTML の `/archives/` 置換 | 今回は対象外。`a2m_path_map` を使う次のフェーズにする。 |

### 決定事項（2026-10-18）

| # | 決定 |
|---|---|
| 1 | パターン A（ウィザード型）。完了画面の「過去の実行」からジョブ詳細（タブ: 概要・アイテム・ログ。再開・スキップ分の再実行・ロールバック）に入れる |
| 2 | 画面の既定は同名（警告付きの確認あり）。別名（`{fd}_media`）は段階移行向けのオプション |
| 3 | リビジョンは既定オフ（オプションでオン） |
| 4 | 拡大表示（enlarged）は `auto` |
| 5 | 本文 HTML の置換は今回は対象外 |
