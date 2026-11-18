## 概要と背景

完成済みの認証画面と共通ナビをバックエンドへ接続し、会員登録・ログイン・ログアウトと保護経路への誘導を利用できるようにする。

- 実装単位: 2 / 12
- 対象: F-01〜03、SC-01〜13の共通導線
- 現状: `routes/web.php`は無名のGET `/`からwelcomeを表示するだけで、認証・業務経路は未接続。ログイン／会員登録Bladeはあるが、composer.jsonにFortifyは含まれていない。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#2-既存画面への接続と認証)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-2-01: 名前・未登録のメールと条件を満たす一致したパスワードで会員登録すると、自動ログインして書籍一覧が表示される。
- [ ] AC-2-02: ゲストで書籍登録へ進んでログインすると、書籍登録画面になり、元の保護画面がない状態でログインすると、書籍一覧になる。
- [ ] AC-2-03: 登録済みのメールと異なるパスワードでログインすると、「ログイン情報が登録されていません。」が入力欄の下に表示され、パスワードが空のログイン画面になる。
- [ ] AC-2-04: ログイン後に共通ナビからログアウトすると、ログイン画面が表示され、再び書籍登録へ進むとログインが必要になる。

確認時期・データ準備: 書籍一覧の完成した表示は単位4、保護先の書籍登録画面は単位6などに依存する。後続機能が未接続の場合は該当ACを未確認とし、最終接続を単位12でも確認する。

## スコープの切り分け

### 今回実装するもの

- Fortifyを導入し、既存の4項目の会員登録と2項目のログインへ接続する。登録後は自動ログインして`/`へ進める。
- ログイン成功後は元の保護ページ、なければ`/`へ戻す。認証済みの`/login`・`/register`は`/`へ、ログアウトはセッション破棄後`/login`へ誘導する。
- 入力検証はFormRequestと日本語messages()へ分離し、エラー時は名前・メールなどを保持して、パスワード・確認入力を保持しない。確定した認証失敗文言を入力欄下へ表示する。
- permissions-api.mdの名前付きルート・引数と認証条件を既存Bladeへ接続する。共通ナビのPC／モバイル導線と公開・保護経路を確認する。未実装経路の骨組みは完成機能に数えない。

### 対象外・維持する既存動作

- 各業務機能の保存・取得処理は対応単位へ分ける。パスワード再設定・メール認証フロー・二要素認証・管理者は追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-2-1〜T-2-15（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#2-既存画面への接続と認証)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先): （※仕様保留中）未指定の認証エラー文言、確認不一致・認証失敗の対応項目。確認欄／email欄へ対応させる推奨案は未承認で、認証・入力検証の実装前に確認する。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
