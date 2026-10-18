# タスクリスト: ArchivesToMedia 初期実装

設計書: `docs/design.md`（決定事項: UI パターン A + ジョブ詳細、別名モード既定、リビジョン既定オフ、enlarged=auto、本文 HTML 置換は対象外）

## 方針

- 1 タスク = 1 コミット（意味のある単位）。各段階の終わりで push（force push はしない）。
- 各段階でテスト（Unit / Integration）を書いてから次へ進む。
- 設計書からの変更点・安全側の判断は `docs/report.md` に集約する。
- コア（ablogcms/php 等）と親リポジトリは変更しない。

## 1. 準備

- [x] keep-awake を要求
- [x] Docker の web / db が稼働していることを確認
- [x] ローカル DB のバックアップ（スクラッチパッド `backup-before-a2m.sql`）
- [x] 公式ドキュメント（アセット API・サービスコンテナ・プラグイン構造）と設計書の読み込み
- [x] 本タスクリストの作成

## 2. 雛形

- [x] 公式スケルトンから生成し、名前空間を `Acms\Plugins\ArchivesToMedia` に置換
- [x] サンプル一式を削除し、ServiceProvider を最小構成に（$version 0.1.0 / $author uidev1116 / $menu archives_to_media_index）
- [x] テスト用 DB `db_acms_test` を作成し、`composer check` が通る骨格にする
- [x] package.json / vite.config.ts / tsconfig.json / ESLint / Prettier / Stylelint
- [x] CI（test.yml / release.yml）に `npm ci && npm run build` と npm lint ジョブを追加
- [x] README を書き換え

## 3. 実装

1. [ ] Domain（TargetType / ItemStatus / JobStatus / ReasonCode / JobOptions / Candidate / Locator）と Schema（3 テーブル、install / uninstall）
2. [ ] Repository（Job / Item / PathMap）
3. [ ] Media（SourcePathSelector / ArchiveMediaResolver / BlogOwnershipFixer / MediaMetaWriter）
4. [ ] Scanner 4 種と ScanCoordinator（ドライラン見込み含む）
5. [ ] Converter 4 種（多言語区切り・フィールドグループ・同名モード）
6. [ ] JobRunner（チャンク・スナップショット照合・編集ロック・キャッシュクリア・Lock）と Rollbacker
7. [ ] POST ハンドラ（薄く。トークンと権限）
8. [ ] ServiceProvider（Twig・InjectTemplate・アセット API・DI）
9. [ ] フロント（ウィザード + ジョブ詳細）

## 4. 自動チェック

- [x] `composer check`（phpcs / PHPStan level max / PHPUnit Unit + Integration）
- [x] `npm run build` / `npm run lint` / `npx tsc --noEmit`
- [x] push 後の GitHub Actions 結果確認

## 5. 動作確認（組み込みブラウザ）

- [x] 検証用の管理者ユーザー作成（認証情報は `.env.local`）
- [x] 子ブログ a2m-test とサンプルデータ・実ファイル
- [x] インストール → 設定 → スキャン → 確認 → 実行（一時停止・再開）→ 完了 → ロールバック
- [x] スクリーンショットを `docs/verification/` に保存
- [x] 別名モードで旧テンプレートが壊れないこと、`{fd}_media@path` がメディア URL を返すこと
- [x] ロールバック後に対象行が変換前と完全一致すること

## 6. レビューと修正（最大 3 周）

- [x] code-review（high）→ 10 件中 8 件修正、2 件は理由付きで見送り（docs/report.md）
- [ ] 独立サブエージェントレビュー（実行中。結果は docs/report.md §7）
- [ ] サブエージェントの指摘の修正と再チェック

## 7. 後片付け

- [x] a2m-test を未変換状態に戻す
- [x] 親ブログのデータが変わっていないことを確認
- [x] `docs/report.md` を作成して push（サブエージェントレビュー結果は追記予定）
- [x] `git status` クリーン / origin/main と一致
