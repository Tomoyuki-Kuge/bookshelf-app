## 概要と背景

責務別のSeederを依存順に実行し、新規の検証用DBへ指定の初期データを投入できるようにする。

- 実装単位: 3 / 12
- 対象: F-27
- 現状: DatabaseSeederはUserFactoryで`Test User`（test@example.com）を1人作成する。UserSeeder・GenreSeeder・BookSeeder・ReviewSeeder・FavoriteSeeder・ReviewLikeSeederは存在しない。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)、[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#3-指定の初期データを投入できるseeder)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-3-01: 初期データ投入後に書籍一覧のページを順に表示すると、指定11冊が1ページ目に10冊、2ページ目に1冊表示される。
- [ ] AC-3-02: 初期データの書籍詳細にアクセスすると、指定のタイトル・著者・ISBN・出版日・ジャンルと、設定済みの説明・画像、各書籍2〜4件のレビューが表示される。
- [ ] AC-3-03: 初期利用者5名のメールとパスワード `password`でそれぞれログインしてお気に入り一覧にアクセスすると、各自の名前と3〜5冊のお気に入りが表示される。
- [ ] AC-3-04: 初期利用者でログインしてジャンル一覧にアクセスすると、指定の10ジャンルが重複なく表示される。

確認時期・データ準備: ACは新規の検証用DBへの初回投入で確認し、単位2・4・5・9の画面接続を待つ。クラス別投入・再実行・内部件数の検証は先行できる。既存データ破棄を伴うコマンドは無断実行しない。

## スコープの切り分け

### 今回実装するもの

- DatabaseSeederを統括役とし、UserSeeder→GenreSeeder→BookSeeder→ReviewSeeder→FavoriteSeeder→ReviewLikeSeederの順で呼び出す。各クラスへ責務を分離する。
- features.md指定の5利用者・10ジャンル・11書籍・32レビュー、利用者ごと3〜5冊のお気に入り、レビューごと0〜3人のいいねを投入する。初期書籍の登録者はUser::first()とし、新規DBの指定順では山田太郎になる。
- User／Genre／Bookは指定キーのfirstOrCreateを使い、既存値を上書きしない。Bookのジャンルはsync、Reviewはcreate、Favorite／ReviewLikeはsyncWithoutDetachingを用いる。
- ReviewSeeder再実行時の事前クリア・重複防止は追加しない。いいねの投稿者本人除外はSeederに限り、通常機能の自己いいね可と区別する。
- 初回投入の件数・配分と、クラス別の責務・再実行後の性質を分けて検証する。通常操作や境界値用の追加データを初期11冊へ混ぜない。

### 対象外・維持する既存動作

- 通常のDB総件数の上限、既存DBの初期化、ReviewSeederの冪等化は対象外。未指定のサンプル文面・具体的配分を独断で確定しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-3-1〜T-3-19（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#3-指定の初期データを投入できるseeder)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-26](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-26seederの固定サンプルと個別実行): （※仕様保留中）未指定の説明・レビュー本文、32件の割当、お気に入り／いいねの具体的な組、個別実行の依存不足、既存DBのUser::first()の扱い。指定範囲内の固定一覧を開発側で用意する案などは未承認。実装前に方針と裁量範囲を確認する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）Seeder全体の途中失敗時の保存範囲・応答とトランザクション方式を開発側で検討し、必要な確認を行う。未確定のまま全体原子性を保証したことにしない。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
