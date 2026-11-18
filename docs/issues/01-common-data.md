## 概要と背景

書籍・ジャンル・レビュー・お気に入り・いいねを後続機能から扱えるよう、設計どおりのデータ基盤を整備する。

- 実装単位: 1 / 12
- 対象: 全基本機能のデータ基盤（F-01〜28）
- 現状: `users`・`password_reset_tokens`・`sessions`、cache・jobs系の標準マイグレーションとUserモデルがある。書籍などの6業務表と、それらを扱うモデル・関連は未実装。Userのパスワードハッシュ・秘匿属性を確認するテストはある。
- 設計: [data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#1-共通データ基盤)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-1-01: 複数ジャンルとレビューを持つ確認用書籍の詳細にアクセスすると、その書籍に紐付くジャンル・投稿者名・評価・コメント・いいね数が表示される。
- [ ] AC-1-02: 確認用利用者でログインして書籍詳細とお気に入り一覧にアクセスすると、その利用者のお気に入り・いいねの登録状態が表示される。

確認時期・データ準備: AC-1-01は単位4、AC-1-02は単位2・4・9などの画面接続後に確認する。基盤実装時はT-1の内部検証を先行し、ACを確認済みにしない。

## スコープの切り分け

### 今回実装するもの

- 既存の`users`を踏まえ、基本7業務表・44列のうち不足する物理定義を整備する。列・型・主キー・確定したNULL条件はdata.mdに従う。
- `book_genre`・`favorites`・`review_likes`の専用IDと組のUNIQUE、全外部キー、CASCADE／RESTRICTを定義する。reviewsの利用者・書籍の組にUNIQUEを追加しない。
- Userと書籍・ジャンル・レビューの関連を整備し、`favorites`・`review_likes`は多対多の中間表名を明示する。日時の扱いは決定した設計へ合わせる。
- 物理制約・関連取得・連鎖削除を後続の画面接続とは分けて検証する。既存の認証補助・管理テーブルは現状を確認して扱う。

### 対象外・維持する既存動作

- 画面・認証・業務CRUD・Seederの実装は各後続単位で扱う。ユーザー削除画面、集計保存列、booksの単一genre_idは追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-1-1〜T-1-13（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#1-共通データ基盤)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現): （※仕様保留中）任意の説明・画像URLの保存値とNULL可否。省略／null／空文字を区別する推奨案は未承認。スキーマ実装前に確認する。
- [Q-25](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-25出版日のbladeへの受け渡し形式): （※仕様保留中）出版日のモデルからBladeへの受け渡し。YYYY-MM-DD文字列へ整形する案は、開発側の検討・記録後に適用する。
- [DB物理定義](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#未確定事項の確認先一覧): （※仕様保留中）DB製品・照合順序、NULL・既定値・日時・採番・追加制約、認証補助／セッション構成。data.mdの未確定事項を開発側で整理し、環境・契約に関わる部分を必要に応じて確認する。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 共通データ基盤から着手する。物理定義の未決事項を整理し、後続画面が揃う前に内部制約を検証する。
