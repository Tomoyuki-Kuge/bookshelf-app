# Bookshelf

書籍の登録・レビュー・評価・お気に入り管理を行うLaravelアプリです。機能の範囲と仕様は[設計書](docs/design/README.md)、開発時の制約は[AGENTS.md](AGENTS.md)を参照してください。

## 開発環境

| 項目 | バージョン・設定 |
| --- | --- |
| PHP | 8.5系（Sailの`runtimes/8.5`） |
| Laravel | 10.x（インストールするパッチ版は`composer.lock`で統一） |
| DB | MySQL 8.4系、InnoDB、utf8mb4／utf8mb4_unicode_ci |
| コンテナ | Docker Compose／Laravel Sail |
| フロントエンド | Sail同梱のNode.js 24系／npm、Vite |

PHP要件・依存解決の基準は`composer.json`、実行環境は`compose.yaml`、共有する設定例は`.env.example`に定義しています。Laravel 10とPHP 8.5の対応範囲は[設計書の開発環境](docs/design/README.md#開発環境)を参照してください。

## 開発者向けの起動・再構築

Dockerを起動し、Composer依存関係をインストールした状態で、プロジェクトルートから実行します。初回の依存インストールにもPHP 8.5のComposer環境を使用してください。開発用の`.env`の準備・調整は開発者自身が行います。`.env.example`の`WWWUSER`・`WWWGROUP`は、必要に応じてホスト側のユーザー・グループIDに合わせてください。

```sh
./vendor/bin/sail build laravel.test
./vendor/bin/sail up -d
./vendor/bin/sail composer install
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

既存環境のPHPを切り替える際もアプリコンテナを再構築します。MySQLの既存ボリュームを保持し、`migrate:fresh`や`down -v`は実行しません。アプリは`http://localhost`、ホストからのDB接続先は設定例では`127.0.0.1:3307`です。コンテナ間のDB接続先は`mysql:3306`です。

## `.env`を読み込まない検証

AIエージェントは`.env`の直接・間接の読み書きを禁止しています。Sailの起動スクリプトも`.env`を読み込むため、検証にはDocker Composeを直接使います。以下はPHP 8.5のアプリコンテナとMySQL 8.4が起動済みであることが前提です。

```sh
docker compose --env-file .env.example -f compose.yaml config --quiet
docker compose --env-file .env.example -f compose.yaml exec -T laravel.test php -v
docker compose --env-file .env.example -f compose.yaml exec -T laravel.test composer validate --strict
docker compose --env-file .env.example -f compose.yaml exec -T laravel.test composer check-platform-reqs --lock
docker compose --env-file .env.example -f compose.yaml exec -T -e APP_ENV=testing laravel.test composer test
```

`.env.testing`にはテスト専用の公開設定を置き、Laravel本体とテストの子プロセスの両方で読み込みます。アプリキーはテスト専用で、開発用・本番用には使いません。DBの既定接続先は`mysql:3306`、DB名は`testing`、利用者・パスワードは`.env.example`と同じサンプル値です。個別環境で接続情報が異なる場合は、実行時の環境変数で上書きし、認証情報をコミットしないでください。

MySQLの初回起動時はSailの初期化スクリプトが`testing`データベースを作成します。既存ボリュームに存在しない場合は、既存データを残したまま開発者が追加してください。共通データ基盤のテストはDB名とMySQL 8.4系を確認し、通常のmigrationとトランザクションで検証します。

コード整形は変更したPHPファイルを明示して実行します。

```sh
docker compose --env-file .env.example -f compose.yaml exec -T laravel.test vendor/bin/pint config/database.php
git diff --check
```
