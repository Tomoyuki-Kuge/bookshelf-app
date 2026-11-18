## 概要と背景

認証済みの利用者が共通ジャンルを閲覧・登録・編集し、書籍と紐付かないジャンルだけ削除できるようにする。

- 実装単位: 5 / 12
- 対象: F-10〜14、SC-06〜09
- 現状: genres配下の一覧・詳細・登録・編集Bladeはあるが、Genreモデル・業務経路・Controller・FormRequestは未実装。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#5-ジャンルを管理できる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-5-01: ログインしてジャンル一覧から名前をクリックすると、そのジャンルの詳細と紐付く書籍が表示され、書籍から詳細へ移動できる。
- [ ] AC-5-02: 未使用のジャンル名を登録して編集すると、各操作後にジャンル一覧へ戻り、変更後の名前と成功メッセージが表示される。
- [ ] AC-5-03: 書籍が紐付いていないジャンルの削除確認で承認すると、一覧へ戻って対象が消え、「ジャンルを削除しました。」が表示される。
- [ ] AC-5-04: 書籍が紐付いているジャンルの削除確認で承認すると、対象が一覧に残り、「このジャンルには書籍が紐付いているため削除できません。」が表示される。

確認時期・データ準備: 他利用者でも同じジャンルを管理できること、0・1・10・11冊の境界、紐付き削除の拒否と競合を確認する。

## スコープの切り分け

### 今回実装するもの

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
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先): （※仕様保留中）ジャンル名の未指定エラー文言。推奨文言は未承認で、入力条件の確定と分けて実装前に確認する。
- [DB物理定義](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#未確定事項の確認先一覧): （※仕様保留中）確定したジャンル名の比較規則を満たす照合順序・実現方式を開発側で検討し、単位1のDB一意制約と整合させる。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2・4を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
