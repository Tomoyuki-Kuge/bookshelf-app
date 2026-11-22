## 概要と背景

完成済みの認証画面と共通ナビをバックエンドへ接続し、会員登録・ログイン・ログアウトと保護経路への誘導を利用できるようにする。

- 実装単位: 2 / 12
- 対象: F-01〜03、SC-01〜13の共通導線
- 現状（2026-11-23、main `062b9aa`時点）: Fortify 1.30.0による認証、名前付きWeb経路、BookPolicy／ReviewPolicy、一覧・書籍登録フォームの読み取りは実装済み。認証の正式文言はPR #16で反映済み。未接続の業務経路は認証・対象確認・本人認可を通過後、一時的に501を返す。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#2-既存画面への接続と認証)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-2-01: 名前・未登録のメールと条件を満たす一致したパスワードで会員登録すると、自動ログインして書籍一覧が表示される。
- [ ] AC-2-02: ゲストで書籍登録へ進んでログインすると、書籍登録画面になり、元の保護画面がない状態でログインすると、書籍一覧になる。
- [ ] AC-2-03: 登録済みのメールと異なるパスワードでログインすると、「ログイン情報が登録されていません」がemail欄の下に表示され、パスワードが空のログイン画面になる。
- [ ] AC-2-04: ログイン後に共通ナビからログアウトすると、ログイン画面が表示され、再び書籍登録へ進むとログインが必要になる。

確認時期・データ準備: 書籍一覧の完成した表示は単位4、保護先の書籍登録画面は単位6などに依存する。後続機能が未接続の場合は該当ACを未確認とし、最終接続を単位12でも確認する。

## スコープの切り分け

### この単位の実装範囲

- Fortifyを導入し、既存の4項目の会員登録と2項目のログインへ接続する。登録後は自動ログインして`/`へ進める。
- ログイン成功後は元の保護ページ、なければ`/`へ戻す。認証済みの`/login`・`/register`は`/`へ、ログアウトはセッション破棄後`/login`へ誘導する。
- 入力検証はFormRequestと日本語messages()へ分離し、エラー時は名前・メールなどを保持して、パスワード・確認入力を保持しない。確定した認証失敗文言を入力欄下へ表示する。
- permissions-api.mdの名前付きルート・引数と認証条件を既存Bladeへ接続する。共通ナビのPC／モバイル導線と公開・保護経路を確認する。未実装経路の骨組みは完成機能に数えない。

- 認証7文言と表示先はQ-19の確定内容に従う。入力不正・認証失敗のHTTP応答から再表示HTMLまで、旧入力保持・秘密情報非保持と欄下表示を確認する。

### 対象外・維持する既存動作

- 各業務機能の保存・取得処理は対応単位へ分ける。パスワード再設定・メール認証フロー・二要素認証・管理者は追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-2-1〜T-2-15（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#2-既存画面への接続と認証)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 既存の[Issue #2画面確認報告](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/uat-shots/issue-2/README.md)は2026-11-20時点の結果。認証の正式文言変更は[PR #16](https://github.com/Tomoyuki-Kuge/bookshelf-app/pull/16)でHTTP／DOMの自動検証済みだが、その変更後の画面UATは未実施。未接続の業務画面を含むT-2-9〜10の全範囲は後続確認。本更新では再検証していない。

## 確定事項と残る確認事項

- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先)：入力欄下表示、確認不一致をpassword_confirmation欄下・認証失敗をemail欄下に対応させる方針と認証7文言は承認済み。正式文言は句点なし。ログインの必須・メール形式文言も登録と統一する。
- 登録の上限・重複等の未指定文言は既存の暫定採用を維持する。その他の未指定の個別エラー文言は（※仕様保留中）。ログインへ登録用の255文字上限・8文字以上条件を追加しない。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：CSRF失効は既定419画面・入力非保持・再入力（認証失効時は再ログイン）。認証後の業務経路を含む例外応答・保存処理の完成は各後続単位と単位12で検証する。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
