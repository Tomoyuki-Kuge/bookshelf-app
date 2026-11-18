## 概要と背景

書籍詳細からレビューを複数投稿し、投稿者本人だけが編集・削除できるようにする。

- 実装単位: 7 / 12
- 対象: F-15〜17、SC-02の投稿・本人操作、SC-05
- 現状: 書籍詳細の投稿枠とreviews/edit.blade.phpはあるが、Reviewモデル・保存経路・Controller・FormRequest・ReviewPolicyは未実装。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#7-レビューを投稿管理できる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-7-01: ログインして書籍詳細から評価とコメントを投稿すると、同じ詳細に戻り、投稿内容と「レビューを登録しました。」が表示される。
- [ ] AC-7-02: 同じ利用者で同じ書籍へもう一度レビューを投稿すると、先の投稿を残したまま新しい投稿が追加表示される。
- [ ] AC-7-03: 自分のレビューを編集して更新すると、対象書籍の詳細に変更が反映され、「レビューを更新しました。」が表示される。
- [ ] AC-7-04: 自分のレビューの削除確認で承認すると、対象書籍の詳細からそのレビューが消え、「レビューを削除しました。」が表示される。

確認時期・データ準備: 複数投稿、評価0／1／5／6、コメント1,000／1,001文字、本人・別利用者・ゲスト、削除後のいいね連鎖を確認する。

## スコープの切り分け

### 今回実装するもの

- 認証済み利用者が詳細内で評価とコメントを投稿できるようにし、投稿者は認証情報、対象書籍は経路から設定する。
- 評価は必須の整数1〜5、コメントは必須の1,000文字以内とし、投稿・更新ともFormRequestと日本語messages()へ分離する。
- 同一利用者・同一書籍への複数投稿、自分／他人の書籍への投稿を許可する。既存Bladeの必須マークの有無で条件を緩めない。
- ReviewPolicyでGET編集・PUT更新・DELETEの都度投稿者本人を判定する。書籍作成者でも他人のレビューは操作できず、他人403・対象なし404とする。
- 編集の既存値、入力不正後の評価・コメント保持、対象詳細への戻り先と確定フラッシュを接続する。単独削除はそのレビューとそのいいねだけを物理削除する。

### 対象外・維持する既存動作

- 独立したレビュー投稿ページ、同一書籍への投稿回数制限、書籍作成者による他者レビュー管理、レビューAPIは追加しない。いいね操作は単位8で扱う。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-7-1〜T-7-12（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#7-レビューを投稿管理できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先): （※仕様保留中）レビューの未指定エラー文言。必須・整数・範囲・上限の確定条件と分けて、未承認の文言案を実装前に確認する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）競合・保存失敗・障害時の保存結果と応答。未承認案をテストの期待値にせず、回答・開発側決定を反映してから検証する。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2・4を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
