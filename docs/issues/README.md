# 実装Issue一覧

作成日: 2026-11-19

設計書の12実装単位を、1単位1件でGitHub Issueに登録した。各本文の控えをこのディレクトリに保存する。仕様の正本は[設計書](../design/README.md)とし、Issue作成は実装・受け入れ確認の完了を示さない。

受け入れ条件44項目は[acceptance.md](../design/acceptance.md)の原文と一致する。各Issueへ対応するテスト観点、未確定事項、ACを確認できる時期を記載した。新たな未回答仕様は追加せず、既存のQ-18・19・22〜26と物理定義の保留を引き継ぐ。

| 実装単位 | GitHub Issue | 本文の控え |
|---|---|---|
| 1. 共通データ基盤 | [#1](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/1) | [本文](01-common-data.md) |
| 2. 既存画面への接続と認証 | [#2](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/2) | [本文](02-authentication.md) |
| 3. 指定の初期データを投入できる（Seeder） | [#3](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/3) | [本文](03-seeders.md) |
| 4. 公開の書籍一覧・詳細を閲覧できる | [#4](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/4) | [本文](04-public-books.md) |
| 5. ジャンルを管理できる | [#5](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/5) | [本文](05-genres.md) |
| 6. 自分の書籍を管理できる | [#6](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/6) | [本文](06-book-management.md) |
| 7. レビューを投稿・管理できる | [#7](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/7) | [本文](07-reviews.md) |
| 8. レビューへいいねできる | [#8](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/8) | [本文](08-review-likes.md) |
| 9. お気に入りを管理できる | [#9](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/9) | [本文](09-favorites.md) |
| 10. 評価ランキングを閲覧できる | [#10](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/10) | [本文](10-ranking.md) |
| 11. 公開書籍APIを利用できる | [#11](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/11) | [本文](11-public-books-api.md) |
| 12. 基本要件を通して確認する（仕上げ） | [#12](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/12) | [本文](12-integration.md) |

## 着手順と確認時期

各Issueの着手順は提案として記載し、実装前提と手動ACの確認前提を分けている。単位1・3の画面を使うACは後続単位の接続後に確認し、基盤・Seeder実装時には内部のテスト観点を先行する。単位12で未接続箇所と未確認ACを回収する。

公開APIはWebの認証・本人制限と区別する。ランキングや公開APIの検証用データはFactory等で独立して用意でき、レビュー投稿画面や初期Seederの完成だけを前提にしない。

## 今回の確認結果

- GitHubの既存Issueと重複しないことを確認して12件を作成。
- 各本文のAC原文、T番号範囲、必要な見出し、未確認のチェック状態、末尾空白を確認。
- 文書作成のみのため、アプリケーションの手動確認・自動テストは未実行。
