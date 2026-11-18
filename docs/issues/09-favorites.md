## 概要と背景

書籍詳細からお気に入りを追加・解除し、自分の一覧を追加日時順に閲覧・解除できるようにする。

- 実装単位: 9 / 12
- 対象: F-19〜20、SC-02内の操作、SC-10
- 現状: 書籍詳細のハートとfavorites/index.blade.phpはあるが、favorites.index／favorites.toggleと保存・取得処理は未実装。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#9-お気に入りを管理できる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-9-01: ログインして書籍詳細の未登録のハートをクリックすると、同じ詳細に戻って登録状態になり、お気に入り一覧にその書籍が表示される。
- [ ] AC-9-02: 登録済み書籍の詳細でハートをクリックすると、同じ詳細に戻って未登録状態になり、お気に入り一覧からその書籍が消える。
- [ ] AC-9-03: 解除後も現在のページに別の書籍が残る状態でお気に入り一覧から解除すると、一覧に戻って対象だけが消え、成功フラッシュは表示されない。
- [ ] AC-9-04: お気に入りがない利用者で一覧にアクセスすると、「お気に入りに登録された書籍はありません。」と書籍一覧へのリンクが表示され、そのリンクから書籍一覧へ移動できる。

確認時期・データ準備: 0・1・10・11件、自分／他人の書籍、詳細／一覧双方の操作元を確認する。AC-9-03は解除後も現在ページに別の書籍が残る条件で確認し、Q-24の境界と分ける。

## スコープの切り分け

### 今回実装するもの

- 認証付きfavorites.toggleを接続し、認証利用者と対象書籍の組を追加／解除する。書籍所有者にかかわらず操作でき、他利用者の登録分は変更しない。
- favorites.indexは自分の登録分だけを追加日時降順・10件／ページで取得し、画像・タイトル・著者・ISBN・詳細リンク・解除操作を渡す。
- 詳細からは詳細へ、一覧からは一覧へ戻り、登録状態を再表示する。成功フラッシュは出さない。
- 0件の文言・書籍一覧リンク、画像なしのNo Image、ゲストのログイン誘導と対象なし404を維持する。組の重複防止と書籍削除後の関連削除を確認する。

### 対象外・維持する既存動作

- 他利用者のお気に入り管理、一覧の書籍登録日時順への変更、成功フラッシュ、専用APIは追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-9-1〜T-9-9（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#9-お気に入りを管理できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-24](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-24お気に入り解除後のページ補正): （※仕様保留中）最終ページの最後の1冊を解除してページがなくなる場合と全件解除時の戻り先。最後の有効ページへ補正する推奨案は未承認で、解除後の遷移実装前に確認する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）連打・同時操作・通信失敗時の状態と再送。操作の直列化などの推奨案は未承認。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2・4を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
