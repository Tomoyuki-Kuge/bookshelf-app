## 概要と背景

書籍詳細からお気に入りを追加・解除し、自分の一覧を追加日時順に閲覧・解除できるようにする。

- 実装単位: 9 / 12
- 対象: F-19〜20、SC-02内の操作、SC-10
- 現状（2026-11-23、main `062b9aa`時点）: User::favoriteBooks・Book::favoritedByUsersと認証付きfavorites.index／favorites.toggleルートは実装済み。一覧取得・トグル保存処理は一時的な501。既存Bladeの表示・操作枠を接続する。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#9-お気に入りを管理できる)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-9-01: ログインして書籍詳細の未登録のハートをクリックすると、同じ詳細に戻って登録状態になり、お気に入り一覧にその書籍が表示される。
- [ ] AC-9-02: 登録済み書籍の詳細でハートをクリックすると、同じ詳細に戻って未登録状態になり、お気に入り一覧からその書籍が消える。
- [ ] AC-9-03: お気に入り一覧から解除すると、現ページに書籍が残れば同じページ、ページがなくなれば最後の有効ページへ戻り、対象だけが消える。全件0件なら1ページ目の既定の0件表示になり、成功フラッシュは表示されない。
- [ ] AC-9-04: お気に入りがない利用者で一覧にアクセスすると、「お気に入りに登録された書籍はありません。」と書籍一覧へのリンクが表示され、そのリンクから書籍一覧へ移動できる。

確認時期・データ準備: 0・1・10・11件、自分／他人の書籍、詳細／一覧双方の操作元を確認する。AC-9-03は現ページ維持・最終ページ消滅・全件0件の各条件をQ-24に従って確認する。

## スコープの切り分け

### この単位の実装範囲

- 認証付きfavorites.toggleを接続し、認証利用者と対象書籍の組を追加／解除する。書籍所有者にかかわらず操作でき、他利用者の登録分は変更しない。
- favorites.indexは自分の登録分だけを追加日時降順・10件／ページで取得し、画像・タイトル・著者・ISBN・詳細リンク・解除操作を渡す。
- 詳細からは詳細へ、一覧からは一覧へ戻り、登録状態を再表示する。成功フラッシュは出さない。
- 0件の文言・書籍一覧リンク、画像なしのNo Image、ゲストのログイン誘導と対象なし404を維持する。組の重複防止と書籍削除後の関連削除を確認する。

- 一覧での解除後も現ページを維持し、ページ消滅時だけ最後の有効ページへ補正する。全件0件なら1ページ目。トグルは同一利用者・対象ごとに直列化する（Q-23・24）。

### 対象外・維持する既存動作

- 他利用者のお気に入り管理、一覧の書籍登録日時順への変更、成功フラッシュ、専用APIは追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-9-1〜T-9-9（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#9-お気に入りを管理できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 本単位の処理本体・手動ACの完了は未確認。既存のルート・モデル・認可テストと区別する。今回の本文更新では実装・自動テスト・画面確認を行っていない。

## 確定事項と残る確認事項

- [Q-24](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-24お気に入り解除後のページ補正)：解除後も現ページを維持し、そのページがなくなる場合だけ最後の有効ページへ戻す。全件0件なら1ページ目の既定の0件表示。詳細からの操作は同じ詳細へ戻す。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：同一利用者・対象の操作を直列化し、受付ごとにトグルする。一意制約を維持し、通信失敗時に自動再送しない。
- 固有の未回答仕様はない。戻り先・ページ補正・具体的な排他方式を既存Bladeと承認済みの契約に合わせて実装・検証する。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1・2・4を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
