## 概要と背景

12実装単位の対応を照合し、基本13画面と公開APIを横断して、未接続・表示不整合・テスト不足を仕上げる。

- 実装単位: 12 / 12
- 対象: F-28、全基本機能の接続
- 現状: テストはUserModelTestと標準のExampleTestが中心で、F-01〜28を通した業務機能の接続・自動テストは未実装。設計書や完成済みBladeの存在は実装完了を示さない。
- 設計: [README.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/README.md)、[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)、[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#12-基本要件を通して確認する仕上げ)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-12-01: 会員登録後にジャンル作成・書籍登録・レビュー投稿・お気に入り追加を行って一覧から詳細へ移動すると、作成した内容と自分の登録状態が表示される。
- [ ] AC-12-02: その書籍を別利用者で開いてレビュー投稿・いいね・お気に入り追加をすると、各操作が反映され、書籍と先の利用者のレビューの編集・削除操作は表示されない。
- [ ] AC-12-03: 関連データを持つ書籍を作成者が削除して、書籍一覧・関係する利用者のお気に入り・ランキングに再度アクセスすると、対象書籍は表示されず、ジャンル一覧にはジャンル本体が残る。
- [ ] AC-12-04: PC幅とモバイル幅で基本13画面のリンク・メニュー・フォームを順に操作すると、設計どおりの画面・戻り先・結果表示になり、未実装の仮応答や描画エラーが表示されない。

確認時期・データ準備: 通常操作の確認用データと、初回Seederの件数確認データを分ける。単位1〜11の未確認ACは理由付きで引き継ぎ、横断確認の実施をもって未回答仕様を合格扱いにしない。

## スコープの切り分け

### 今回実装するもの

- 単位1〜11のF・SC・AP・AC・Tの対応を照合し、各単位に属する不足・不具合を補う。開発中の仮経路・仮データ・未実装応答を完成機能へ残さない。
- 会員登録→ジャンル→書籍→レビュー→いいね／お気に入りを本人・別利用者・ゲストで通し、画面表示とサーバー認可を一致させる。
- WebとAPIをまたぐ入力・関連・更新・削除、平均・件数・いいね・お気に入り・ジャンル書籍数・ランキングの整合を確認する。登録者変更はQ-18回答後に検証する。
- PC幅・モバイル幅で基本13画面のナビ・メニュー・フォーム・戻り先、キャンセル・削除中止を確認し、単位1・3などの後続画面依存ACを回収する。
- モデル・画面・CRUD・認証・API・初期データなどの全テスト通過とカバレッジ60%超の目標を実行結果で確認する。手動AC・自動テスト・未実行／仕様保留を分けて報告する。

### 対象外・維持する既存動作

- 応用のWeb検索・ISBN外部連携・CSV・読書計画・通知・Sanctum／API所有者認可・管理者・追加認証フローを基本要件の必須検証へ混在させない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-12-1〜T-12-11（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#12-基本要件を通して確認する仕上げ)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [横断する未確定事項](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#未確定事項の確認先一覧): （※仕様保留中）Q-18・19・22〜26とDB物理定義の回答状況を確認する。既存の未回答事項を独断で解決せず、採用決定を設計書へ先に反映し、未回答は保留理由と未確認範囲を記録する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）CSRF／セッション失効、不正JSON・非対応メソッド・想定外障害の応答と再入力、ログの保存先・期間・記録内容。既定画面や日次14日などの推奨案は未承認。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2・3・4・5・6・7・8・9・10・11を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
