## 概要と背景

レビューへのいいねを利用者ごとに追加・解除し、書籍詳細の状態と件数へ反映できるようにする。

- 実装単位: 8 / 12
- 対象: F-18、SC-02内の操作
- 現状（2026-11-23、main `062b9aa`時点）: Review::likedByUsers・User::likedReviewsと認証付きreviews.likeルートは実装済み。トグル保存処理は一時的な501で、既存の書籍詳細Bladeへの状態・件数表示の接続も後続の対象。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#8-レビューへいいねできる)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-8-01: ログインして未いいねのレビューの「いいね」をクリックすると、同じ書籍詳細に戻り、「いいね済み」と1件増えた件数が表示される。
- [ ] AC-8-02: 「いいね済み」のレビューで再度ボタンをクリックすると、同じ詳細に戻り、「いいね」と1件減った件数が表示される。
- [ ] AC-8-03: 自分が投稿したレビューの「いいね」をクリックすると、他人のレビューと同様に「いいね済み」になり、成功フラッシュは表示されない。

確認時期・データ準備: 0→1→0件、複数利用者の件数、自己いいねと他者の状態維持を確認する。同時操作はQ-23の承認済み方針に従って検証する。

## スコープの切り分け

### この単位の実装範囲

- 認証付きPOST `/reviews/{review}/like`（reviews.like）を接続し、認証利用者と対象レビューの組を追加／解除する。
- 自分のレビューにもいいねできるようにし、他利用者の状態を変更しない。同じ組を重複保存しない。
- 対象書籍の詳細へ戻り、「いいね」／「いいね済み」と全利用者の合計件数を再表示する。成功フラッシュは出さない。
- ゲストの直接POSTはログインへ誘導し、対象なし・削除済みレビューは認証後404とする。ゲストの件数付きログイン導線とレビュー削除後の連鎖を確認する。

- 同一利用者・対象の操作を直列化して受付ごとにトグルし、通信失敗時に自動再送しない（Q-23）。

### 対象外・維持する既存動作

- Seeder専用の本人いいね除外を通常操作へ持ち込まない。いいねAPI、独立画面、成功フラッシュは追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-8-1〜T-8-6（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#8-レビューへいいねできる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 本単位の処理本体・手動ACの完了は未確認。既存のルート・モデル・認可テストと区別する。今回の本文更新では実装・自動テスト・画面確認を行っていない。

## 確定事項と残る確認事項

- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：同一利用者・対象の連打／同時操作を直列化し、受け付けた操作ごとにトグルする。一意制約を維持し、通信失敗時に自動再送しない方針は承認済み。
- 固有の未回答仕様はない。具体的な排他方式は、承認済みの結果を満たすよう実装時に検討・検証する。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1・2・4・7を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
