## 概要と背景

ログイン利用者が複数ジャンル付きの書籍を登録し、自分の書籍だけを編集・物理削除できるようにする。

- 実装単位: 6 / 12
- 対象: F-06〜09、SC-03〜04、SC-02の本人操作
- 現状（2026-11-23、main `062b9aa`時点）: Bookモデル・関連・BookPolicyと認証／本人制限付きルートは実装済み。BookController::createは既存ジャンルを登録フォームへ渡す。書籍の保存・編集表示・更新・削除は一時的な501で、業務FormRequestと処理本体は未実装。フォームは`genres[]`と`$bookGenreIds`を参照する。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#6-自分の書籍を管理できる)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-6-01: ログインして必須項目と1つ以上のジャンルを入力して書籍登録すると、登録した詳細へ移動し、「書籍を登録しました。」が表示される。
- [ ] AC-6-02: 自分の書籍の編集画面で項目とジャンルの選択を変更して更新すると、詳細に変更が反映され、「書籍情報を更新しました。」が表示される。
- [ ] AC-6-03: タイトルを空にして登録すると、保存されずに元フォームへ戻り、「タイトルは必須です。」と他の入力・ジャンル選択が表示される。
- [ ] AC-6-04: 自分の書籍の削除確認で承認すると、一覧へ戻って対象が消え、「書籍を削除しました。」が表示され、保存しておいた詳細URLを開くと404になる。

確認時期・データ準備: 正常CRUDに加え、他利用者の直接操作、user_id偽装、ISBN・文字数・ジャンル選択の境界、入力保持、削除範囲を確認する。

## スコープの切り分け

### この単位の実装範囲

- 書籍登録・編集・更新・削除の経路を接続し、作成者は認証情報から設定する。送信user_idを採用せず、Web更新で作成者を変更しない。
- BookPolicyでGET編集・PUT更新・DELETEの都度本人を判定し、他人は403、対象なし・削除済みは404とする。画面の本人操作表示も揃える。
- 書籍入力をFormRequestと日本語messages()へ分離する。タイトル・著者・ISBN・出版日・既存ジャンル1つ以上を必須とし、上限・ISBNの半角数字13桁／一意・未来日可・任意項目条件を設計どおり検証する。
- 書籍と複数ジャンルを登録し、編集時はジャンル集合を置換する。入力不正では本文・任意項目・選択を保持し、全選択解除時に既存ジャンルを復活させない。
- 保存後は対象詳細、削除後は一覧へ確定フラッシュ付きで戻す。書籍削除はレビュー・そのいいね・お気に入り・中間行まで物理削除し、ジャンル本体を残す。

- 任意項目の空欄NULL保存、Q-25の出版日形式、Q-23の原子保存・競合時の取消・後勝ち・削除済み404を実装・検証する。

### 対象外・維持する既存動作

- ISBN自動変換・チェックディジット検証・外部連携・CSV・画像アップロード、下書き・復元は追加しない。公開APIは単位11で扱う。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-6-1〜T-6-22（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#6-自分の書籍を管理できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 本単位の処理本体・手動ACの完了は未確認。既存のルート・モデル・認可テストと区別する。今回の本文更新では実装・自動テスト・画面確認を行っていない。

## 確定事項と残る確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先)：タイトル必須・ISBN桁数・ISBN重複の正式文言、入力欄下表示と旧入力保持は確定済み。その他の未指定の書籍入力エラー文言のみ（※仕様保留中）。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：説明・画像URLはNULL許容で、Webの空欄はNULLへ統一する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：書籍項目とジャンル集合は1トランザクション。途中失敗は全体取消。検証後のISBN重複・選択ジャンル消失は原因を特定して通常の入力エラーへ戻し、他のDB障害を入力エラーへ一律変換しない。ジャンルの未指定文言はQ-19の残件。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：同時編集は後から正常保存した内容を採用し、項目ごとの自動マージ・version列・409を追加しない。編集中に削除された対象は404で、再作成しない。認証・CSRF・経路等の確認後は対象なし404を入力不正より優先する。
- [Q-25](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-25出版日のbladeへの受け渡し形式)：出版日はYYYY-MM-DD文字列で既存の日付欄・詳細へ渡す。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1・2・4・5を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
