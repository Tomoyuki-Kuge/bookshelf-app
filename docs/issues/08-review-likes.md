## 概要と背景

レビューへのいいねを利用者ごとに追加・解除し、書籍詳細の状態と件数へ反映できるようにする。

- 実装単位: 8 / 12
- 対象: F-18、SC-02内の操作
- 現状: 書籍詳細にいいね表示・操作枠があるが、reviews.like経路とトグル保存処理は未実装。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#8-レビューへいいねできる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-8-01: ログインして未いいねのレビューの「いいね」をクリックすると、同じ書籍詳細に戻り、「いいね済み」と1件増えた件数が表示される。
- [ ] AC-8-02: 「いいね済み」のレビューで再度ボタンをクリックすると、同じ詳細に戻り、「いいね」と1件減った件数が表示される。
- [ ] AC-8-03: 自分が投稿したレビューの「いいね」をクリックすると、他人のレビューと同様に「いいね済み」になり、成功フラッシュは表示されない。

確認時期・データ準備: 0→1→0件、複数利用者の件数、自己いいねと他者の状態維持を確認する。同時操作の期待結果はQ-23の回答後に検証する。

## スコープの切り分け

### 今回実装するもの

- 認証付きPOST `/reviews/{review}/like`（reviews.like）を接続し、認証利用者と対象レビューの組を追加／解除する。
- 自分のレビューにもいいねできるようにし、他利用者の状態を変更しない。同じ組を重複保存しない。
- 対象書籍の詳細へ戻り、「いいね」／「いいね済み」と全利用者の合計件数を再表示する。成功フラッシュは出さない。
- ゲストの直接POSTはログインへ誘導し、対象なし・削除済みレビューは認証後404とする。ゲストの件数付きログイン導線とレビュー削除後の連鎖を確認する。

### 対象外・維持する既存動作

- Seeder専用の本人いいね除外を通常操作へ持ち込まない。いいねAPI、独立画面、成功フラッシュは追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-8-1〜T-8-6（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#8-レビューへいいねできる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）連打・同時操作・通信失敗時の受付順序、状態と再送方針。直列化と操作ごとのトグル、自動再送しない案は未承認で、保存・例外処理の実装前に確認する。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2・4・7を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
