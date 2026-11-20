# Bookshelf 開発ガイド

開発ルールは[AGENTS.md](AGENTS.md)、機能仕様と環境の決定は[docs/design/README.md](docs/design/README.md)、セットアップ・検証手順は[README.md](README.md)を参照する。

- ユーザーとのやり取り、ドキュメント、コメント・表示文言は日本語を基本とする。
- PHP 8.5系・Laravel 10.x・MySQL 8.4系のSail環境で開発する。
- Laravel・PHP・依存管理のコマンドは原則コンテナ内で実行する。ホストへのPHPや開発支援パッケージの自動インストールは行わない。
- `.env`は直接・子プロセス経由とも読み書きしない。検証ではREADMEのDocker Compose直接実行手順と`.env.testing`を使う。
- 完成済みBladeと`docs/reference/`の控えは変更しない。
