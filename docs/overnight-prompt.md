ArchivesToMedia プラグイン（a-blog cms 拡張アプリ）を、設計書どおりに実装してください。私は寝ています。朝に戻ったとき、すぐ動作確認できる状態になっていることがゴールです。途中で私に質問しないでください。判断に迷ったら、設計書の方針に沿って安全側を選び、その判断を最終レポートに書いてください。

## 入力
- 作業場所: plugins/ArchivesToMedia/（GitHub の uidev1116/acms-archives-to-media を clone 済みの独立リポジトリ。ブランチ main）
- 設計書: plugins/ArchivesToMedia/docs/design.html（必ず全文を読む）
- UI モック: plugins/ArchivesToMedia/docs/ui-mock.html
- 公式ドキュメント（実装前に読む）:
  - https://developer.a-blogcms.jp/document/develop_apps/
  - https://developer.a-blogcms.jp/document/develop_apps/asset-api.html
  - https://developer.a-blogcms.jp/document/develop_apps/service-container.html
- 既存プラグインの参考: plugins/WXRImport（構成・ServiceProvider・Lock・進捗 UI の作り）
- コンポーネント: npm の @ablogcms/components@3.2.37（React 19）。型定義（dist/**/*.d.ts）を読んでから使う

## 決定事項（設計書の「15. 未決事項」の回答）
- UI パターン: A（ウィザード型）。完了画面の「過去の実行」から、ジョブ詳細（B-3 相当: タブで概要・アイテム・ログ、再開・スキップ分の再実行・ロールバック）に入れるようにする
- フィールドの既定の書き込み先: 別名（{fd}_media）。同名モードは警告付きのオプション
- リビジョン: 既定はオフ（オプションでオンにできる）
- 拡大表示（enlarged）: auto
- 本文 HTML の置換: 今回は対象外

## 守ること（厳守）
- a-blog cms のコア（ablogcms/php, js, themes/system など）は編集しない
- 本番へのデプロイ、`git push`、GitHub Actions の deploy ワークフローの実行はしない
- 親リポジトリ（my-blog-site-backend）には一切コミットしない。.gitmodules も作らない（plugins/ArchivesToMedia は .git/info/exclude で親から除外済み）。親リポジトリのファイルは変更しない
- コミットと push は plugins/ArchivesToMedia リポジトリでだけ行う。main に直接コミット・push してよい。意味のある単位でこまめにコミットし、段階の区切り（下の「進め方」の各番号の終わり）で push する。force push はしない
- 公開リポジトリ（Public）なので、認証情報・DB ダンプ・ローカルの絶対パス・スクリーンショット内の個人情報をコミットしない
- Sass は使わない（素の CSS）。JS と CSS はアセット API で読み込む。DI は init() の中で App::singleton / App::bind を使う。コアのサービスを override / extend / 再登録しない
- ローカル DB を書き換える作業の前には、必ずバックアップを取る（下記）

## 進め方
1. 準備
   - Mac がスリープしないよう keep-awake を要求する（ツールがあれば）
   - Docker の web / db コンテナが動いていることを確認する（`docker ps`）。止まっていたら起動する
   - ローカル DB をダンプする: `docker exec my-blog-site-backend-db-1 sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" db_my-blog-site' > <スクラッチパッド>/backup-before-a2m.sql`（ダンプはリポジトリの外に置き、コミットしない）。ダンプのパスは最終レポートに書く
   - 公式ドキュメントと設計書を読み、実装タスクの一覧を plugins/ArchivesToMedia/.steering/<日付>-initial/tasklist.md に書く（WXRImport の .steering と同じ形式。このリポジトリにコミットしてよい）
2. 雛形
   - 公式スケルトン（`composer create-project ablogcms/plugin-skeleton`）の構成に合わせて、設計書 §8.1 の構成で作る。PHP は src/、フロントのソースは frontend/、配信ファイルは src/assets/
   - `ablogcms/extension/plugins/ArchivesToMedia` → `../../../plugins/ArchivesToMedia/src` のシンボリックリンクは張ってある。無ければ同じ形で張る（ablogcms/extension/plugins/* は親で .gitignore 済み）
   - src/.gitkeep は雛形を置いたら消す
   - composer（testing-framework 3.2.*、phpstan ^2.1、phpcs）、npm（vite、react 19、@ablogcms/components 3.2.37、typescript、eslint、prettier、stylelint）を入れる
3. 実装（設計書の順に。各段階でテストを書いてから次へ進む）
   1. Domain（enum、JobOptions、Candidate、Locator）と Schema（3 テーブル。install / uninstall）
   2. Repository（Job / Item / PathMap）
   3. Media（SourcePathSelector、ArchiveMediaResolver、BlogOwnershipFixer、MediaMetaWriter）。コアの MediaSourceImporter を ARCHIVES_DIR ベースで生成する
   4. Scanner 4 種と ScanCoordinator（ドライランの見込みを含む）
   5. Converter 4 種（§4 のマッピング表どおり。多言語区切り、フィールドグループ、同名モード）
   6. JobRunner（チャンク、スナップショットの照合、編集ロック、キャッシュクリア、Lock）と Rollbacker
   7. GET / POST ハンドラ（薄く。トークンと権限の確認）
   8. ServiceProvider（Twig 登録、InjectTemplate callable、アセット API、DI）
   9. フロント（パターン A + ジョブ詳細）。@ablogcms/components を使い、足りない部品だけ a2m- 接頭辞の素の CSS で作る
4. 自動チェック（すべて通るまで直す）
   - `vendor/bin/phpunit`（Unit + Integration。Integration は Docker 内で testing-framework の手順に従う。テスト用 DB は db_acms_test のように本番用と別にする）
   - `vendor/bin/phpstan analyse`（level max）
   - `vendor/bin/phpcs`
   - `npm run build`、`npm run lint`（eslint、prettier --check、stylelint）、`tsc --noEmit`
5. 動作確認（組み込みブラウザ。サイトは https://web.my-blog-site-backend.localhost/ 。localhost:8080 は traefik のダッシュボードなので使わない）
   - 管理画面で使うテスト用の管理者ユーザーは、このセッションでローカル DB に作る。認証情報は plugins/ArchivesToMedia/.env.local（.gitignore 済み）に記録し、チャットやレポートには書かない
   - 検証用の子ブログ（例: コード a2m-test）を作り、画像ユニット・ファイルユニット・画像フィールド（グループ内を含む）・ファイルフィールド・多言語の区切りを含むエントリーと、archives/ の実ファイルを用意する。ファイルが無いケース、large- 版が無いケースも入れる
   - 拡張アプリをインストール・有効化し、設定 → スキャン → 確認 → 実行（途中で一時停止して再開）→ 完了 → ロールバック までを通す。各段階のスクリーンショットを plugins/ArchivesToMedia/docs/verification/ に保存する（コミットしてよい。個人情報が写っていないことを確認してから）
   - 変換後の公開ページで、旧テンプレートのまま表示が壊れていないこと（別名モード）と、`{fd}_media@path` がメディアの URL を返すことを確認する
   - ロールバック後に、対象の行が変換前と完全に一致することを DB で確認する
6. レビューと修正（最大 3 周）
   - code-review スキルを high で、プラグインリポジトリの全変更に対して実行する
   - それとは別に、サブエージェントを 1 つ立てて独立にレビューさせる。観点は「設計書との差分」「データ破損のリスク（トランザクション、スナップショット照合、ロールバック）」「セキュリティ（パストラバーサル、CSRF、権限、BID のスコープ）」「冪等性」
   - 指摘を修正し、4 の自動チェックと 5 の主要シナリオを再実行する。重大な指摘が無くなるか 3 周したら終わる。直さなかった指摘は理由をレポートに書く
7. 後片付け
   - 動作確認で作ったデータは、私が朝に自分で試せるよう残す（子ブログ a2m-test は未変換の状態に戻しておく。ロールバックで戻すか、子ブログのデータを作り直す）
   - 親ブログ（既存コンテンツ）のデータは一切変換しない。もし誤って変換した場合は、手順 1 のダンプから復元してレポートに書く
   - プラグインリポジトリで最終コミットし、main に push する。`git status` がクリーンで、origin/main と一致していることを確認する

## 最終レポート
plugins/ArchivesToMedia/docs/report.html に（コミットして push する）、次の内容をまとめる（設計書と同じスタイル。日本語）。
- 朝の動作確認手順（URL、ログイン情報の場所、クリックする順番、期待する結果）
- 実装したもの・設計からの変更点とその理由
- 自動チェックの結果（コマンドと件数。失敗が残っていればそのまま書く）
- レビューの指摘と対応（直したもの・直さなかったもの）
- 未完了・既知の問題・私が判断すべきこと
- バックアップの場所と、復元のコマンド

最後に、完了したことをプッシュ通知で知らせる（ツールがあれば）。通知には、レポートのパスと一行の要約を入れる。
