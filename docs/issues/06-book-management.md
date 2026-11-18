## 概要と背景

ログイン利用者が複数ジャンル付きの書籍を登録し、自分の書籍だけを編集・物理削除できるようにする。

- 実装単位: 6 / 12
- 対象: F-06〜09、SC-03〜04、SC-02の本人操作
- 現状: books/create・edit・_formと詳細の操作枠はあるが、保存処理・入力検証・BookPolicyは未実装。フォームは`genres[]`と`$bookGenreIds`を参照する。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#6-自分の書籍を管理できる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-6-01: ログインして必須項目と1つ以上のジャンルを入力して書籍登録すると、登録した詳細へ移動し、「書籍を登録しました。」が表示される。
- [ ] AC-6-02: 自分の書籍の編集画面で項目とジャンルの選択を変更して更新すると、詳細に変更が反映され、「書籍情報を更新しました。」が表示される。
- [ ] AC-6-03: タイトルを空にして登録すると、保存されずに元フォームへ戻り、「タイトルは必須です。」と他の入力・ジャンル選択が表示される。
- [ ] AC-6-04: 自分の書籍の削除確認で承認すると、一覧へ戻って対象が消え、「書籍を削除しました。」が表示され、保存しておいた詳細URLを開くと404になる。

確認時期・データ準備: 正常CRUDに加え、他利用者の直接操作、user_id偽装、ISBN・文字数・ジャンル選択の境界、入力保持、削除範囲を確認する。

## スコープの切り分け

### 今回実装するもの

- 書籍登録・編集・更新・削除の経路を接続し、作成者は認証情報から設定する。送信user_idを採用せず、Web更新で作成者を変更しない。
- BookPolicyでGET編集・PUT更新・DELETEの都度本人を判定し、他人は403、対象なし・削除済みは404とする。画面の本人操作表示も揃える。
- 書籍入力をFormRequestと日本語messages()へ分離する。タイトル・著者・ISBN・出版日・既存ジャンル1つ以上を必須とし、上限・ISBNの半角数字13桁／一意・未来日可・任意項目条件を設計どおり検証する。
- 書籍と複数ジャンルを登録し、編集時はジャンル集合を置換する。入力不正では本文・任意項目・選択を保持し、全選択解除時に既存ジャンルを復活させない。
- 保存後は対象詳細、削除後は一覧へ確定フラッシュ付きで戻す。書籍削除はレビュー・そのいいね・お気に入り・中間行まで物理削除し、ジャンル本体を残す。

### 対象外・維持する既存動作

- ISBN自動変換・チェックディジット検証・外部連携・CSV・画像アップロード、下書き・復元は追加しない。公開APIは単位11で扱う。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-6-1〜T-6-22（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#6-自分の書籍を管理できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先): （※仕様保留中）確定4文言以外の入力エラーと表示対応。推奨文言は未承認で、FormRequest実装前に確認する。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現): （※仕様保留中）説明・画像URLの空欄保存値とDBのNULL条件。未設定をNULLへ統一する案は未承認で、単位1・11と揃えて確認する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）書籍とジャンルの保存単位、保存途中の失敗、検証後のISBN重複・参照先消失、同時編集・障害時の応答。原子保存や後勝ちなどの推奨案を採用済みにしない。
- [Q-25](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-25出版日のbladeへの受け渡し形式): （※仕様保留中）出版日の既存値を日付欄へ渡す型・整形箇所を開発側で検討し、詳細表示とも整合させる。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2・4・5を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
