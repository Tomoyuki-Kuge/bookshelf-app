## 概要と背景

ゲストが書籍一覧と詳細を閲覧し、ジャンル・平均評価・レビューと操作導線を既存Bladeで確認できるようにする。

- 実装単位: 4 / 12
- 対象: F-04〜05、SC-01〜02
- 現状: books/index.blade.php・books/show.blade.phpはあるが、Bookモデルと業務Controller・名前付きルートは未実装。一覧は`$books`と`reviews_avg_rating`などを参照する。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#4-公開の書籍一覧詳細を閲覧できる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-4-01: ゲストで書籍一覧にアクセスすると、登録日時の新しい順に1ページ最大10冊が表示され、カードから対象の詳細へ移動できる。
- [ ] AC-4-02: 書籍詳細にアクセスすると、書籍情報と登録日時の新しい順の全レビューが、投稿者名・評価・コメント・投稿日・いいね数付きで表示される。
- [ ] AC-4-03: レビューのある書籍とない書籍を一覧で確認すると、ある書籍だけに星と平均評価が表示される。
- [ ] AC-4-04: 書籍0件の一覧とレビュー0件の書籍詳細にアクセスすると、それぞれ「書籍が登録されていません。」「まだレビューはありません。」が表示される。

確認時期・データ準備: 対象データはFactory等で独立して準備でき、単位3のSeederだけを必須にしない。書籍・レビューの本人操作の最終接続と保存結果は単位6・7以降でも確認する。

## スコープの切り分け

### 今回実装するもの

- 公開GET `/`（books.index）と`/books/{book}`（books.show）を接続する。GET `/books`は追加しない。
- 一覧は登録日時降順・同日時ID降順で10件／ページとし、画像・著者・全ジャンル・レビューがある場合だけ星と平均評価を表示する。
- 詳細へ書籍情報と全レビューを登録日時降順で渡す。レビューはページ分割せず、投稿者名・評価・コメント・投稿日・いいね数を表示する。
- ゲスト／認証済みの表示、本人操作、いいね・お気に入り状態、フォームと戻り先を既存Bladeの参照に合わせる。保存操作自体は各対応単位で完成させる。
- 書籍0件・レビュー0件・画像／説明なし・存在しない書籍の表示を確認し、所定の文言・代替画像表示を維持する。

### 対象外・維持する既存動作

- Webの検索・絞り込み・並び順選択、書籍CRUD・レビュー投稿・トグル保存は対象外。レビューの件数制限・ページ分割は追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-4-1〜T-4-12（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#4-公開の書籍一覧詳細を閲覧できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-25](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-25出版日のbladeへの受け渡し形式): （※仕様保留中）出版日のモデルから既存Bladeへの受け渡し。YYYY-MM-DD文字列へ整形する推奨案を開発側で検討・記録し、単位6の編集フォームとも整合させる。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1・2を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
