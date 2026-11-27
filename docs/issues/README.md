# 実装Issue一覧

作成日: 2026-11-19 / 最終同期日: 2026-11-27（Issue #12のみ追加同期）

設計書の12実装単位を、1単位1件でGitHub Issueに登録した。各本文の控えをこのディレクトリに保存する。仕様の正本は[設計書](../design/README.md)とし、Issue作成は実装・受け入れ確認の完了を示さない。

受け入れ条件44項目は[acceptance.md](../design/acceptance.md)の現行本文と一致する。2026-11-23に更新したGitHub Issue #1〜#12の本文と各控えを同期した。Q-18・22〜26とDB物理条件は承認済み。Q-19は表示方針・認証7文言・ログイン入力エラーの統一が確定し、未指定の個別文言は保留を維持する。認証の未指定の上限・重複等は従来の暫定採用を維持し、回答状況の正本は[questions.md](../design/questions.md)とする。

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

2026-11-26、Issue #7の現状・検証補足・実装準備の承認を本文の控えへ追加同期した。他Issueの再同期・再検証は行っていない。

2026-11-27、Issue #12の現状・既存検証・カバレッジ初回測定と再測定待ち・残件を更新し、GitHub本文と控えの一致を確認した。AC原文・チェック状態とIssueの開閉状態は維持した。他Issueの再同期・テスト・画面操作の再実行は行っていない。

## 着手順と確認時期

各Issueの着手順は提案として記載し、実装前提と手動ACの確認前提を分けている。単位1・3の画面を使うACは後続単位の接続後に確認し、基盤・Seeder実装時には内部のテスト観点を先行する。単位12で未接続箇所と未確認ACを回収する。

公開APIはWebの認証・本人制限と区別する。ランキングや公開APIの検証用データはFactory等で独立して用意でき、レビュー投稿画面や初期Seederの完成だけを前提にしない。

## 本文同期の確認結果（2026-11-23）

- GitHub Issue #1〜#12を再取得し、本文の控え12件と一致することを確認した。
- AC全44項目の現行原文、T番号範囲、既存実装・検証履歴・後続確認と、承認済み事項／保留事項の区別を確認した。
- Issueの開閉状態とACのチェック状態は変更していない。#1〜#3の実装済み・クローズ済みと、画面依存ACの確認待ちを区別する。チェック状態は新たな手動確認の実施を示さない。
- 文書更新のみのため、アプリケーションの手動確認・自動テストは再実行していない。過去の検証結果と今回の本文同期を区別する。
