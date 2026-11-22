# Bookshelf 開発ガイド（AIエージェント向け）

Bookshelfは、書籍の登録・レビュー・評価・お気に入り管理を行うLaravel製Webアプリです。
完成済みBladeにバックエンドを接続します。本書と対象機能の設計書を読んでから変更し、ユーザーの最新指示を優先する。

## 1. 基本方針・絶対守るべき制約

- **日本語対応**：ユーザーとのやり取り、作業報告、設計書、コメント・表示文言は日本語を基本とする。
- **対象は基本要件のみ**：対象機能と対象外は `docs/design/features.md` に従う。
- **完成済みBladeの変更禁止**：`resources/views/**/*.blade.php` は変更しない（レイアウト・共通部品・文言・入力項目・ルート参照を維持する）。
- **.env の保護**：読み込み・書き込み・コマンド出力のすべてを禁止。`.env.example` や `config/` で確認する。
- **提供資料・控えの保護**：`docs/reference/` の控えは変更しない。
- **勝手な推測の禁止**：未確定事項は `docs/design/questions.md` に推奨案付きで記録し、未承認の案を仕様やテストの期待値にしない。

## 2. 設計書の参照ルール

設計書の読み方・書き方は [docs/design/README.md](docs/design/README.md) を参照する。実装前に、変更に関係する決めごとを次の参照先で確認し、内容を本書へ転記しない。

| 確認する内容 | 場所 |
| --- | --- |
| 機能・対象外・参照資料 | [features.md](docs/design/features.md) |
| 確定回答・未確定事項・推奨案・確認先 | [questions.md](docs/design/questions.md) |
| 画面・遷移・入力検証・表示文言 | [screens.md](docs/design/screens.md) |
| 認可・ルート・公開APIの入出力 | [permissions-api.md](docs/design/permissions-api.md) |
| DBの物理定義・関連・削除制約 | [data.md](docs/design/data.md) |
| 状態遷移・処理順序・競合・例外方針 | [behavior.md](docs/design/behavior.md) |
| Seederの責務・投入仕様・再実行 | [features.md「初期データ」](docs/design/features.md#初期データ) |
| 手動の受け入れ条件 | [acceptance.md](docs/design/acceptance.md) |
| テスト観点・テストユーザー・データ準備 | [test_case.md](docs/design/test_case.md) |
| PHP・エディターの書式 | [.editorconfig](.editorconfig)、[composer.json](composer.json) |
| テスト設定 | [phpunit.xml](phpunit.xml) |
| 開発環境・サービス構成 | [compose.yaml](compose.yaml)、[.env.example](.env.example) |

実装と設計書が食い違ったら、**コードではなく `docs/design/` を先に直す**。仕様矛盾や未確定事項は独断で解消せず、`questions.md` へ記録する。

## 3. 環境構成とコマンド実行のルール

- PHP 8.5系 / Laravel 10.x / MySQL 8.4系のSail環境を前提とする。対応範囲と検証方針は [docs/design/README.md](docs/design/README.md#開発環境) を参照する。
- **コンテナ内実行**：Laravel・PHP・依存管理のコマンドはプロジェクトルートから、原則 `laravel.test` コンテナ内で実行する。
- **`.env` の保護**：Sail・Compose・Artisan・PHPUnitによる間接的な読み込みも避ける。Composeの `--env-file` 指定だけ、またはArtisan単体の環境指定だけで安全と判断せず、子プロセスも確認する。`.env` を生成・変更する `composer setup` は実行しない。
- **破壊的コマンドの禁止**：`migrate:fresh` やボリューム削除などの既存データ破棄コマンドは無断で実行しない。

## 4. コーディング・設計原則

- 実装方針は設計書に従う。文字数・件数・文言・判定順序などの具体的な契約は上の表から確認する。
- 書式は `.editorconfig` に従い、変更したPHPはPintで整形する。Bladeや無関係なファイルを巻き込まない。
- **日本語コメントの義務化**：新規作成するすべてのPHPファイル（Migration・Model・Controller・FormRequest・Resource・Seeder・Factory等）およびテストファイルは、ファイル冒頭のDocBlockに目的や役割を説明する日本語の一言コメントを必ず記載する。
- 主要なクラス、カスタムメソッド、複雑なロジックやバリデーション定義にも、意図がひと目で伝わる簡潔な日本語コメントを適宜添える。宣言の説明はDocBlock、処理途中の意図はインラインコメントで示す。
- 入力検証は `FormRequest` に分離し日本語エラーとする。
- Webでは認証情報からユーザーを取得し、送信された `user_id` を信じない。本人制限はPolicyで適用する。公開APIにはWebの本人制限を持ち込まず、設計書の契約に従う。
- Seederは責務別に分割する（User → Genre → Book → Review → Favorite → ReviewLike）。

## 5. テスト・Gitルール

- `acceptance.md`（手動用）と `test_case.md`（内部制約・API書き込みを含む検証用）を遵守し、実行結果と未実行・保留の理由を分けて報告する。
- 作業前後に差分と状態を確認し、ユーザーの既存変更を上書きしない。変更後は `git diff --check` を確認する。
- 無差別な `git add .` は禁止。コミットを依頼された場合は対象ファイルを明示してステージし、対象差分を確認する。
- `.gitignore` を守り、`.env`・認証情報・ログ・`vendor/`・`node_modules/`・phpMyAdminの配布物やローカル設定などの不要ファイルをコミットしない。

## 6. ブランチとプルリクエスト

### ブランチ運用

- **1 Issueにつき1本の作業ブランチ**を使い、関連する設計更新・実装・テストをまとめる。別Issueや無関係な変更を混在させない。Issueに紐づかない変更は、1つの変更目的につき1本とする。
- 新規の作業ブランチは `main` から作成し、`main` へ直接コミットしない。対応する作業ブランチが既にあれば再利用し、規約に合わせるために既存ブランチを勝手に改名・上書きしない。
- ブランチ名は次の表に従う。`<概要>` は内容が分かる英小文字・数字をハイフンでつなぐ。

| 対象 | 命名 | 例 |
| --- | --- | --- |
| Issueに紐づく変更 | `issue-<番号>-<概要>` | `issue-3-seeders` |
| Issueなしで、設計書・READMEなどの文書だけを修正 | `docs/<概要>` | `docs/update-review-design` |
| Issueなしで、`.codex/` のスキル・ルールや開発運用を修正 | `chore/<概要>` | `chore/update-pr-skill` |

### プルリクエストの本文

本文の型は [.github/pull_request_template.md](.github/pull_request_template.md) を使う。12実装単位のうち対象の番号と設計書・AC・TのIDを記入し、本文の型を別の場所へ重複定義しない。

- 受け入れ条件の手動確認と自動テスト結果を分け、後続単位に依存するACや未確定仕様を確認済みにしない。
- Issueに紐づく場合は末尾に `Closes #<番号>` を入れる。紐づかない場合はIssue欄を「該当なし」とし、`Closes` 行は省略する。

### マージ後の整理

- PRをマージしたら、対応するリモート・ローカルの作業ブランチを削除する。マージ済みのブランチを別の作業に再利用しない。
- 削除前に、対象PRがマージ済みであり、未コミットの変更やPRに未反映のコミットがないことを確認する。ローカルでは `main` へ切り替え、最新化してから `git branch -d <ブランチ名>` で削除する。squash mergeなどで削除を拒否された場合も、自動で強制削除せず、残ったブランチと理由を報告する。
- PR作成の依頼だけでは、マージやブランチ削除を行わない。
