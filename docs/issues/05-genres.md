## 概要と背景

認証済みの利用者が共通ジャンルを閲覧・登録・編集し、書籍と紐付かないジャンルだけ削除できるようにする。

- 実装単位: 5 / 12
- 対象: F-10〜14、SC-06〜09
- 現状（2026-11-23、main `062b9aa`時点）: Genreモデル・関連・DB一意制約と認証付きの名前付きルートは実装済み。ジャンルの各業務経路は一時的な501で、専用Controller・FormRequest・画面への取得／保存接続は未実装。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#5-ジャンルを管理できる)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-5-01: ログインしてジャンル一覧から名前をクリックすると、そのジャンルの詳細と紐付く書籍が表示され、書籍から詳細へ移動できる。
- [ ] AC-5-02: 未使用のジャンル名を登録して編集すると、各操作後にジャンル一覧へ戻り、変更後の名前と成功メッセージが表示される。
- [ ] AC-5-03: 書籍が紐付いていないジャンルの削除確認で承認すると、一覧へ戻って対象が消え、「ジャンルを削除しました。」が表示される。
- [ ] AC-5-04: 書籍が紐付いているジャンルの削除確認で承認すると、対象が一覧に残り、「このジャンルには書籍が紐付いているため削除できません。」が表示される。

確認時期・データ準備: 他利用者でも同じジャンルを管理できること、0・1・10・11冊の境界、紐付き削除の拒否と競合を確認する。

## スコープの切り分け

### この単位の実装範囲

- ジャンル全経路を認証で保護し、全認証済み利用者が同じジャンルを管理できるようにする。
- 一覧はID昇順で名前・書籍数・管理導線を渡す。詳細は紐付き書籍を登録日時降順・10件／ページで表示し、書籍詳細へ接続する。
- ジャンル名を必須・255文字以内・一意で検証し、前後空白除去、英字大小同一・全角半角区別を満たす。更新は自身を一意性判定から除外する。
- 未紐付きの場合だけ削除し、事前確認後の紐付け追加もDBのRESTRICTで拒否して確定した削除不可文言へ揃える。
- 登録・更新・削除後は一覧へ戻し、既存の成功／失敗枠、入力保持、キャンセル・削除中止と0件表示を接続する。

### 対象外・維持する既存動作

- ジャンルの作成者・管理者権限・ジャンルAPIは追加しない。ジャンル削除時に書籍やbook_genreを削除しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-5-1〜T-5-13（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#5-ジャンルを管理できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 本単位の処理本体・手動ACの完了は未確認。既存のルート・モデル・認可テストと区別する。今回の本文更新では実装・自動テスト・画面確認を行っていない。

## 確定事項と残る確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先)：未指定のジャンル名エラー文言のみ（※仕様保留中）。必須・255文字以内・一意、入力保持・欄下表示は確定済み。
- [DB物理定義](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md#t3-genres)：全半角を区別し英字大小を同一とするジャンル名の式UNIQUE索引は単位1で承認・実装済み。アプリ側の重複判定も整合させる。DB方式を未確定に戻さない。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)とQ-21：削除の事前確認後に紐付けが追加されてもRESTRICTで拒否し、同じ削除不可文言へ揃える。その他のDB障害は通常の削除不可や入力エラーへ一律変換しない。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1・2・4を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
