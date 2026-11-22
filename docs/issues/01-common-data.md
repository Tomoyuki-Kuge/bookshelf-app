## 概要と背景

書籍・ジャンル・レビュー・お気に入り・いいねを後続機能から扱えるよう、設計どおりのデータ基盤を整備する。

- 実装単位: 1 / 12
- 対象: 全基本機能のデータ基盤（F-01〜28）
- 現状（2026-11-23、main `062b9aa`時点）: 基本7業務表・モデル4種・関連・FactoryとBookPolicy／ReviewPolicyは実装済み。`CommonDataFoundationTest.php`等で物理定義・関連・削除制約を検証している。画面依存のAC-1-01〜02は後続の接続後に確認する。
- 設計: [data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#1-共通データ基盤)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-1-01: 複数ジャンルとレビューを持つ確認用書籍の詳細にアクセスすると、その書籍に紐付くジャンル・投稿者名・評価・コメント・いいね数が表示される。
- [ ] AC-1-02: 確認用利用者でログインして書籍詳細とお気に入り一覧にアクセスすると、その利用者のお気に入り・いいねの登録状態が表示される。

確認時期・データ準備: AC-1-01は単位4、AC-1-02は単位2・4・9などの画面接続後に確認する。基盤実装時はT-1の内部検証を先行し、ACを確認済みにしない。

## スコープの切り分け

### この単位の実装範囲

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
- 単位1の内部制約・関連・削除は既存の自動テストで検証済み。AC-1-01〜02は画面依存のため後続単位で確認する。検証履歴は[PR #16](https://github.com/Tomoyuki-Kuge/bookshelf-app/pull/16)と[CommonDataFoundationTest.php](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/tests/Feature/CommonDataFoundationTest.php)を参照。本更新ではテスト・画面操作を再実行していない。

## 確定事項と残る確認事項

- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：説明・画像URLはDBでNULL許容。Web空欄、APIのPOST／PUTにおける省略・null・空文字の保存契約は承認済み。
- [Q-25](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-25出版日のbladeへの受け渡し形式)：DBはdate型、モデルから既存BladeへYYYY-MM-DD文字列で渡す方針は承認済み。
- [DB物理定義](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md#埋まらなかった箇所)：PHP 8.5・Laravel 10・MySQL 8.4、InnoDB・utf8mb4／utf8mb4_unicode_ci、自動採番、日時NULL許容・秒精度・UTC・Eloquent管理、ratingのCHECKなし、既存補助テーブル維持は承認済み。ジャンル名の全半角区別・英字大小同一を満たす式UNIQUE索引も実装済み。画面・業務入力検証は別単位で確認する。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 共通データ基盤から着手する。物理定義の未決事項を整理し、後続画面が揃う前に内部制約を検証する。
