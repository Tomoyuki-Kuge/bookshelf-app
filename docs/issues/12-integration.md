## 概要と背景

12実装単位の対応を照合し、基本13画面と公開APIを横断して、未接続・表示不整合・テスト不足を仕上げる。

- 実装単位: 12 / 12
- 対象: F-28、全基本機能の接続
- 現状（2026-11-23、main `062b9aa`時点）: 共通データ基盤・認証・Seederは実装済み。書籍一覧と書籍登録フォームの読み取り以外の業務経路には一時的な501が残り、公開APIは未実装。基盤・認証・Seeder等の自動テストは存在し、PR #16の最終結果は61テスト・1,858アサーション合格。全基本機能の横断確認・カバレッジ測定は未実施。
- 設計: [README.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/README.md)、[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)、[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#12-基本要件を通して確認する仕上げ)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-12-01: 会員登録後にジャンル作成・書籍登録・レビュー投稿・お気に入り追加を行って一覧から詳細へ移動すると、作成した内容と自分の登録状態が表示される。
- [ ] AC-12-02: その書籍を別利用者で開いてレビュー投稿・いいね・お気に入り追加をすると、各操作が反映され、書籍と先の利用者のレビューの編集・削除操作は表示されない。
- [ ] AC-12-03: 関連データを持つ書籍を作成者が削除して、書籍一覧・関係する利用者のお気に入り・ランキングに再度アクセスすると、対象書籍は表示されず、ジャンル一覧にはジャンル本体が残る。
- [ ] AC-12-04: PC幅とモバイル幅で基本13画面のリンク・メニュー・フォームを順に操作すると、設計どおりの画面・戻り先・結果表示になり、未実装の仮応答や描画エラーが表示されない。

確認時期・データ準備: 通常操作の確認用データと、初回Seederの件数確認データを分ける。単位1〜11の未確認ACは理由付きで引き継ぎ、横断確認の実施をもって未回答仕様を合格扱いにしない。

## スコープの切り分け

### この単位の実装範囲

- 単位1〜11のF・SC・AP・AC・Tの対応を照合し、各単位に属する不足・不具合を補う。開発中の仮経路・仮データ・未実装応答を完成機能へ残さない。
- 会員登録→ジャンル→書籍→レビュー→いいね／お気に入りを本人・別利用者・ゲストで通し、画面表示とサーバー認可を一致させる。
- WebとAPIをまたぐ入力・関連・更新・削除、平均・件数・いいね・お気に入り・ジャンル書籍数・ランキングの整合を確認する。登録者変更と変更後のWeb権限はQ-18の確定契約に従って検証する。
- PC幅・モバイル幅で基本13画面のナビ・メニュー・フォーム・戻り先、キャンセル・削除中止を確認し、単位1・3などの後続画面依存ACを回収する。
- モデル・画面・CRUD・認証・API・初期データなどの全テスト通過とカバレッジ60%超の目標を実行結果で確認する。手動AC・自動テスト・未実行／仕様保留を分けて報告する。

- Q-23のCSRF・構文／メソッド／障害応答とログ方針、Q-18の登録者変更後のWeb権限、Q-22のAPI契約、Q-24のページ補正を横断確認する。

### 対象外・維持する既存動作

- 応用のWeb検索・ISBN外部連携・CSV・読書計画・通知・Sanctum／API所有者認可・管理者・追加認証フローを基本要件の必須検証へ混在させない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-12-1〜T-12-11（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#12-基本要件を通して確認する仕上げ)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 基盤・認証・Seeder等の既存テストと各画面確認報告はあるが、単位12の横断確認とカバレッジ測定は未実施。[PR #16](https://github.com/Tomoyuki-Kuge/bookshelf-app/pull/16)の61テスト・1,858アサーション合格を全基本機能の完成扱いにしない。本更新ではテスト・画面操作を再実行していない。

## 確定事項と残る確認事項

- [回答状況の正本](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)：Q-18・22〜26とIssue #1のDB物理条件は承認済み。Q-19は表示方針・認証7文言・ログイン入力エラー統一が確定し、未指定の個別文言のみ（※仕様保留中）。認証の未指定の上限・重複等は従来の暫定採用を維持する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：CSRF失効は既定419画面・入力非保持・再入力（認証失効時は再ログイン）。不正JSON400・非対応メソッド405・想定外障害500とAPIの正式文言、応答へのSQL・スタックトレース・認証情報非公開は確定済み。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：ログはアプリのログ領域に日次出力・14日保持。日時・経路・例外種別を記録し、パスワード・トークン・リクエスト全文を残さない。保存先・期間の設定と運用条件との照合は後続の実装確認として残す。
- [測定予定](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#カバレッジ測定の実行予定)：単位6・7が揃った段階で初回カバレッジ測定、単位12で再測定して60%超を確認する。現時点の測定済み・目標達成とは扱わない。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1・2・3・4・5・6・7・8・9・10・11を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
