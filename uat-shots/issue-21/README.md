# Issue #21 検証結果

確認日: 2026-11-26 / 対象ブランチ: `issue-21-readability` / 開始コミット: `6aab7e9`

[Issue #21](https://github.com/Tomoyuki-Kuge/bookshelf-app/issues/21)の承認済み計画に沿い、書籍保存と旧入力生成のメソッド分割、レビュー競合テストの整理、DOM解析・SQL観測のTrait共有、Controller・Seederの命名改善を行った。機能仕様・ルート・DB定義・完成済みBlade・依存パッケージの変更はない。

## 自動テスト・静的確認

| 確認 | 結果 |
|---|---|
| 変更12PHPのみPint | 成功、終了0 |
| 関連5クラス（書籍管理・レビュー管理・ジャンル管理・公開書籍閲覧・Seeder） | 175テスト・4,843アサーション成功、終了0 |
| 整形後の最終全件 | 212テスト・5,951アサーション成功、失敗・エラー・スキップ0、終了0（12.78秒） |
| 元の変更12PHPと隔離検証コピー | 全ファイルのバイト一致 |
| 子PHPスクリプト2本の抽出 | スクリプト本文が変更前と完全一致 |
| 差分レビュー・`git diff --check` | 問題なし |

1テスト内の模擬競合7ケースを名前付きDataProviderで独立させたため、テスト数は206件から6件増加した。対象外の書籍・レビューを各ケースに明示して、全保存値比較の検出範囲も維持した。

実並行検証は、書籍保存4ケース・ジャンル共有ロック1ケース・ジャンル削除の独立接続1ケース・レビューの先行競合6ケース・親共有ロック3ケースがすべて成功。模擬変更と実並行実行を区別し、ロック順・待機SQLの観測・レビュー確定保存値の照合・タイムアウト・後始末を維持した。

検証は元の保護対象`.env`を含まない明示許可項目だけの隔離実コピーと公開`.env.testing`を使用した。PHP 8.5コンテナとMySQL 8.4の`testing` DBに限定し、子プロセスも隔離コピー内で実行した。元のプロジェクトルートはコンテナへマウントしていない。

実行形（隔離コピーは終了後に削除）:

```sh
docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue21-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test --filter='BookManagementTest|ReviewManagementTest|GenreManagementTest|PublicBookBrowsingTest|SeederTest'
docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue21-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test --log-junit=/var/www/html/storage/logs/issue21-final-junit.xml
```

Pintも同じコンテナ・マウントで、変更した12PHPを明示して実行した。

## Playwright MCPによる画面確認

隔離HTTP環境 `http://127.0.0.1:8082`、PC幅1440×1000で確認。幅390×844では書籍入力エラー・レビュー一覧・レビュー削除を追加確認した。`APP_ENV=testing`、`APP_DEBUG=false`、`SESSION_DRIVER=file`。初期Seederとは別に、画面から専用利用者「可読性確認 山田」、2ジャンル、1書籍、同利用者の2レビューを作成した。

| 条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-5-02 | 満たす | 登録・更新後にジャンル一覧へ戻り、対象名と成功文言が変わった。[登録](01_genre-created.png)・[更新](02_genre-updated.png) |
| AC-6-01 | 満たす | 必須項目とジャンルを指定して登録すると対象詳細へ移動し、「書籍を登録しました。」と入力内容が表示された。[証拠](03_book-created.png) |
| AC-6-02 | 満たす | タイトル・説明とジャンル集合を変更すると、詳細へ戻り更新内容と「書籍情報を更新しました。」が表示された。[証拠](04_book-updated.png) |
| AC-6-03 | 満たす | タイトルを空にして実POSTすると、元フォームで「タイトルは必須です。」と著者・ISBN・出版日・説明・ジャンル選択を保持。書籍数は1件のまま。[PC](05_book-validation-old-input.png)・[幅390px](06_book-validation-mobile.png) |
| AC-7-01 | 満たす | 評価5とコメントを投稿すると同じ書籍詳細へ戻り、投稿内容と「レビューを登録しました。」が表示された。[証拠](07_review-created.png) |
| AC-7-02 | 満たす | 同じ利用者で同じ書籍へ2件目を投稿し、先のレビューと新しいレビューがともに表示された。[証拠](08_second-review.png) |
| AC-7-03 | 満たす | 本人のレビューを評価3・別コメントへ更新すると詳細に反映され、「レビューを更新しました。」が表示された。[PC](09_review-updated.png)・[幅390px](10_reviews-mobile.png) |
| AC-7-04 | 満たす | 本人レビューの削除確認を承認し、詳細へ戻って対象だけが消え、もう1件と「レビューを削除しました。」が表示された。[PC](12_review-deleted.png)・[幅390px](11_review-deleted-mobile.png) |

タイトル未入力時は`required=false`、`checkValidity()=true`、`validationMessage=""`、フォームの`novalidate=true`を確認した。ブラウザー標準検証で止まらず、サーバー側の入力エラーとして確認している。

他者・ゲストの拒否、入力境界、既知のDB制約違反と想定外障害、保存値・関連行保持、Seederの再実行は自動テストで確認した。今回の画面UATではこれらを直接送信して再検証していない。未実装の後続機能や、Q-19の暫定文言の正式確定は今回の確認対象に含めていない。カバレッジは再測定していない。

## 後片付け・既存変更

作成した書籍と2ジャンルは同じ利用者の画面操作で削除した。書籍削除で残る確認用レビューも削除された。専用利用者はログアウト後、メールと名前・関連なしを確認して削除した。終了時の業務7表はすべて0件で、開始前の全保存値と完全一致した。既存データの破棄やAUTO_INCREMENTの巻き戻しは行っていない。

作業用ブラウザータブ・隔離HTTPコンテナ・隔離コピーは終了時に片付けた。既存サービス・ボリュームを維持した。ユーザーの既存`docs/CODEX_HANDOFF.md`は開始時のSHA256と一致し、既存`.playwright-mcp/`を削除・移動していない。コミット・push・PR作成は行っていない。
