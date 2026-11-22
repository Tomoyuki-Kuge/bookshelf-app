## 概要と背景

責務別のSeederを依存順に実行し、新規の検証用DBへ指定の初期データを投入できるようにする。

- 実装単位: 3 / 12
- 対象: F-27
- 現状（2026-11-23、main `062b9aa`時点）: DatabaseSeeder・責務別6クラス・依存検査補助SeedReferencesとSeederTestは実装済み（PR #15マージ済み）。初回投入・再実行・依存不足・途中失敗の取消を自動検証済み。画面の確認範囲は下記の検証欄を参照する。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)、[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#3-指定の初期データを投入できるseeder)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-3-01: 初期データ投入後に書籍一覧のページを順に表示すると、指定11冊が1ページ目に10冊、2ページ目に1冊表示される。
- [ ] AC-3-02: 初期データの書籍詳細にアクセスすると、指定のタイトル・著者・ISBN・出版日・ジャンルと、設定済みの説明・画像、各書籍2〜4件のレビューが表示される。
- [ ] AC-3-03: 初期利用者5名のメールとパスワード `password`でそれぞれログインしてお気に入り一覧にアクセスすると、各自の名前と3〜5冊のお気に入りが表示される。
- [ ] AC-3-04: 初期利用者でログインしてジャンル一覧にアクセスすると、指定の10ジャンルが重複なく表示される。

確認時期・データ準備: ACは新規の検証用DBへの初回投入で確認し、単位2・4・5・9の画面接続を待つ。クラス別投入・再実行・内部件数の検証は先行できる。既存データ破棄を伴うコマンドは無断実行しない。

## スコープの切り分け

### この単位の実装範囲

- DatabaseSeederを統括役とし、UserSeeder→GenreSeeder→BookSeeder→ReviewSeeder→FavoriteSeeder→ReviewLikeSeederの順で呼び出す。各クラスへ責務を分離する。
- features.md指定の5利用者・10ジャンル・11書籍・32レビュー、利用者ごと3〜5冊のお気に入り、レビューごと0〜3人のいいねを投入する。初期書籍の登録者はUser::first()とし、新規DBの指定順では山田太郎になる。
- User／Genre／Bookは指定キーのfirstOrCreateを使い、既存値を上書きしない。Bookのジャンルはsync、Reviewはcreate、Favorite／ReviewLikeはsyncWithoutDetachingを用いる。
- ReviewSeeder再実行時の事前クリア・重複防止は追加しない。いいねの投稿者本人除外はSeederに限り、通常機能の自己いいね可と区別する。
- 初回投入の件数・配分と、クラス別の責務・再実行後の性質を分けて検証する。通常操作や境界値用の追加データを初期11冊へ混ぜない。

- 個別実行前の依存検査、全体・個別実行のトランザクションと途中失敗時の全変更取消をQ-23・26に従って検証する。

### 対象外・維持する既存動作

- 通常のDB総件数の上限、既存DBの初期化、ReviewSeederの冪等化は対象外。サンプルはQ-26の承認済み裁量とfeatures.mdの固定一覧に従い、指定範囲を超える変更を独断で確定しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-3-1〜T-3-19（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#3-指定の初期データを投入できるseeder)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 既存の[Issue #3検証報告](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/uat-shots/issue-3/README.md)にSeeder専用24ケース・738アサーション、全58テスト・1,790アサーション合格とAC-3-01のPC／モバイル画面確認を記録している。AC-3-02は単位4、AC-3-03は単位9、AC-3-04は単位5の接続待ち。T-3-10の通常投稿・いいね成功は単位7・8で確認する。報告内のQ-26暫定採用の記載は実施当時の状態で、現在は承認済み。本更新では再検証していない。

## 確定事項と残る確認事項

- [Q-26](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-26seederの固定サンプルと個別実行)：指定範囲内の説明・レビュー本文・32件の割当・お気に入り／いいねの組を固定一覧として開発側で用意する方針は承認済み。実装値の正本はfeatures.md。個々の文面がQAに列挙されて承認されたという意味ではない。
- [Q-26](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-26seederの固定サンプルと個別実行)：個別実行では依存Seederを自動で呼ばず、必要な依存データを書込前に検査し、不足時は日本語の理由付き例外で失敗する。既存DBでも新規書籍の登録者はUser::first()を使い、email検索・ID固定へ変更しない。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：DatabaseSeeder全体・各個別Seederはそれぞれトランザクションで囲み、途中失敗では当該実行の全変更を取り消して例外をCLIへ返す。既存値・ジャンル集合も実行前へ戻す。AUTO_INCREMENTの欠番復元は要求しない。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
