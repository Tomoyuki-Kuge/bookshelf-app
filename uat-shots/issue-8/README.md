# Issue #8 検証結果

確認日: 2026-11-26（日本時間） / ブランチ: `issue-8-review-likes` / 開始コミット: `5540e40`

レビューいいねの保存処理をReviewLikeControllerへ接続した。利用者は認証情報、レビューは経路から取得する。親書籍の共有ロック→レビューの排他ロックの後に、そのトランザクションで最初の非ロックSELECTとして中間行の登録状態を読み、追加・解除する。自己いいねを許可し、対象書籍詳細へ成功フラッシュなしで戻る。仕様の正本はdocs/designのF-18・SC-02・Q-23とIssue #8補足。完成済みBlade・DB定義・依存パッケージの変更はない。

## 初回実装の自動テスト・差分レビュー

| 確認 | 結果 |
|---|---|
| 変更3PHPのみPint | 成功、終了0 |
| 整形後の最終全件 | 225テスト・6,183アサーション成功、失敗・エラー・スキップ0、終了0 |
| 追加したReviewLikeTest | 13件・232アサーション成功（実並行8ケースを含む） |
| 元ファイルと隔離コピー | Controller・ルート・追加テストの3PHPがバイト一致 |
| 初回の差分レビュー・git diff --check | 初回は指摘なし。後続のreview-diffで別レビューへの同時追加のデッドロックを検出（下記参照） |
| testing DBの全保存値 | 自動テスト前後で業務7表が完全一致、各0件 |

追加テストと設計の対応:

- T-8-1〜3・5／AC-8-01〜03: `test_toggle_uses_authenticated_user_and_route_and_renders_current_counts`。自己・他人の投稿への操作、連続4回の中間状態、0→1→0件、複数利用者の件数と各状態、送信IDの無視、中間行の日時、古い関連キャッシュ、対象外の全保存値を照合した。ジャンル・お気に入りも非空にして保持を検証した。
- T-8-4: `test_guest_and_missing_targets_preserve_every_saved_value`。件数付きログインリンク、ゲストの直接POST、不存在・削除済み404、拒否後の保存値不変を確認した。
- T-8-5: `test_web_review_delete_cascades_likes_and_preserves_other_reviews`。Web削除で対象レビューといいねだけを除去することを確認した。
- T-8-6: `test_failure_after_write_rolls_back_without_success`の追加・解除2ケース。書き込み後の模擬障害で全体取消、安全な500、成功フラッシュなし、機密を含まないログを確認した。
- T-8-6: `test_concurrent_toggles_and_deletions_are_serialized`の実並行8ケース。同一組2回の操作を未登録・登録済みから実行する2ケース、別利用者の2ケース、レビュー・書籍削除先行の2ケース、いいね先行でレビュー・書籍削除を待機させる2ケース。独立接続・子プロセス・同期と待機SQLの観測を用い、第1操作の確定保存値と対象外不変を確認してから第2操作を許可した。初回は外側のトランザクションで古いDBスナップショットを作っていたが、後続の修正で実HTTP経路と同じトランザクション境界へ揃えた。タイムアウトと後片付けを設け、固定sleepだけで順序を仮定していない。

検証は保護対象.envを含まない明示許可項目の隔離実コピーと公開.env.testingを使用。元ルートをマウントせず、PHP 8.5のコンテナ内で実行し、MySQL 8.4のtesting DBに限定した。子プロセスも隔離コピー内で実行した。

実行形（隔離コピーは確認終了後に削除）:

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue8-wj7o56bu:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app vendor/bin/pint app/Http/Controllers/ReviewLikeController.php routes/web.php tests/Feature/ReviewLikeTest.php
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue8-wj7o56bu:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test --log-junit=/var/www/html/storage/logs/issue8-final-junit.xml
```

初回の全件実行では隔離コピーへのlangディレクトリの配置漏れにより既存認証3件が失敗した。langを明示コピーし、アプリを変更せず最終全件成功を確認した。カバレッジは今回再測定していない。

## レビュー指摘への修正と最終検証（2026-11-26）

review-diffで、未登録のいいね中間行へのFOR UPDATEが索引のギャップをロックし、別レビューへの同時追加をデッドロックさせる問題を検出した。プロジェクトから独立した使い捨てMySQL 8.4.13・REPEATABLE READで、親書籍→レビュー→未登録の組をロックするSQL順序を再現し、ERROR 1213と片方の取消を確認した。再現環境は削除済みで、プロジェクトのDBには触れていない。

ユーザーの修正依頼を受け、behavior.mdとtest_case.mdを先に補足した。Controllerは認証IDをトランザクション前に取得し、親書籍・レビューのロック後の最初の非ロックSELECTで登録状態を読む方式へ変更した。同一レビューへの操作はレビュー行で直列化し、REPEATABLE READのスナップショットを待機後に確立する。中間行の未登録範囲をロックしないため、別レビューへの追加と相互待機しない。トランザクション内でこの読み取りより先に非ロックSELECTを置かないことが前提となる。DB全体の分離レベル変更・自動再送・依存追加は行っていない。

`test_initial_likes_on_distinct_reviews_do_not_deadlock`を追加。同一利用者・別利用者の2ケースで、それぞれ異なるレビューを対象に、両方が中間行の検索を終えた後に書き込みを解放した。双方の解放受信も同期で確認し、302・対象書籍へのLocation・成功フラッシュなし、登録された2組と日時、対象外の全保存値を照合した。既存8ケースの子HTTP処理からは実経路にない外側トランザクションを取り除き、同一レビューの待機後の切り替えと削除競合も再確認した。

| 確認 | 結果 |
|---|---|
| 隔離コピーだけ旧Controllerへ戻して新回帰2ケースを実行 | 両方とも期待302に対して500で失敗、終了1。旧方式の問題を検出 |
| 修正版に復元し、変更2PHPのみPint | 成功、終了0 |
| 整形後の最終全件 | **227テスト・6,219アサーション成功**、失敗・エラー・スキップ0、終了0 |
| ReviewLikeTest | 15件・268アサーション成功。既存実並行8ケース＋別レビューへの追加2ケースが成功 |
| 親の再レビュー・元ファイルと隔離コピーの比較 | 指摘したギャップロックを除去。Controller・ルート・テストの3PHPがバイト一致 |
| testing DB・git diff --check | 開始終了の業務7表全保存値一致（各0件）、差分チェック成功 |

実行形:

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue8-gap-33akipgy:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test --filter=test_initial_likes_on_distinct_reviews_do_not_deadlock
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue8-gap-33akipgy:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test --log-junit=/var/www/html/storage/logs/issue8-gap-final-junit.xml
```

PHP 8.5コンテナ・MySQL 8.4のtesting DB・元.envを含まない隔離構成を継続した。下記の画面UATは初回実装時の記録であり、今回の排他処理の修正後には再実施していない。画面・ルート・表示文言の変更はなく、状態・件数の自動回帰は最終全件で確認した。カバレッジの再測定は行っていない。修正検証用の隔離コピーも終了時に削除した。

## テスト内容レビュー後の補強と最終検証（2026-11-26）

ユーザー承認によりtest_case.mdへ補強観点を先に反映し、ReviewLikeTestだけを修正した。アプリ・ルート全40PHPは補強開始時のSHA256と一致し、機能の変更はない。

- 削除先行の競合では、削除トランザクション内で削除後・コミット前、かつ子の待機解除前に期待する全保存値を固定した。子が404を返すまでの誤更新を期待値へ混ぜず検出する。
- 書き込み後障害の追加・解除両ケースに、他利用者による対象・別レビューへのいいね、お気に入り、ジャンル・book_genreを準備した。日時を含む全保存値比較で、空表の比較では見逃す関連行の誤削除も検出する。
- 同一レビューへの並行操作で、2回目がいいねとなる4ケースも、302・対象書籍詳細へのLocation・成功フラッシュなしを厳密比較した。レビュー・書籍削除の既存成功応答とは分けて扱う。

検出力の確認では、隔離コピーだけに誤動作を1種類ずつ注入した。元アプリには反映していない。

| 注入した誤動作 | 対象 | 検出結果 |
|---|---|---|
| 削除後の404を維持しつつ、子側で無関係ないいねを誤削除 | 削除先行2ケース。誤削除の完了を子からの同期通知で確認 | 全保存値不一致で2件失敗、終了1 |
| 500応答時にHandlerがお気に入りを全削除 | 書き込み後障害の追加・解除 | 全保存値不一致で2件失敗、終了1 |
| 2回目のいいねだけLocationを誤った遷移先へ変更 | 同一組・別利用者の追加／解除4ケース | 応答不一致で4件失敗、終了1 |
| 2回目のいいねだけ成功フラッシュを追加 | 同上4ケース | 応答不一致で4件失敗、終了1 |

すべての誤変更を復元して、変更したテスト1PHPをPintで整形した後、**最終全227テスト・6,219アサーション成功**（失敗・エラー・スキップ0、終了0）。ReviewLikeTestは15件・268アサーション成功。テスト件数を増やさず、既存ケースのデータと期待値比較を補強した。

実行は元.envを含まない新しい隔離実コピー、PHP 8.5コンテナ、MySQL 8.4のtesting DBに限定した。

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue8-tests-wm2oi22d:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app vendor/bin/pint tests/Feature/ReviewLikeTest.php
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue8-tests-wm2oi22d:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test --log-junit=/var/www/html/storage/logs/issue8-tests-final-junit.xml
```

親がJUnitの各誤動作の失敗と最終全件成功、テストの元／隔離バイト一致、既存引き継ぎ・Controller・ルートのハッシュ不変を確認した。業務7表の開始終了全保存値は完全一致（各0件）、git diff --check成功。検証用隔離コピーは終了時に片付けた。アプリ・画面の変更がないため画面UATは再実施せず、初回の記録を維持する。いいね経路に対する不正CSRFトークンの実際の拒否は今回の3点補強には含めず、共通CSRF検証の未確認範囲として区別する。

## Playwright MCPによる画面確認（初回実装時）

隔離HTTP環境 `http://127.0.0.1:8082`、PC幅1440×1000、モバイル幅390×844。APP_ENV=testing、APP_DEBUG=false、SESSION_DRIVER=file、既存ビルド済みアセットを使用した。専用利用者2名（いいね確認 山田・田中）、1ジャンル、1書籍、各利用者のレビュー1件ずつを準備した。初期Seederの検証ではない。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-8-01 | 満たす | 山田が田中のレビューの「いいね (0)」をクリック。同じ書籍詳細へ戻り「いいね済み (1)」を表示し、成功フラッシュなし。[追加](02_like-added.png) |
| AC-8-02 | 満たす | 同じボタンを再クリック。同じ詳細に戻り「いいね (0)」を表示し、成功フラッシュなし。[解除](03_like-removed.png) |
| AC-8-03 | 満たす | 山田が自分のレビューへいいねし「いいね済み (1)」を表示。成功フラッシュなし。幅390pxでも状態を表示し、再クリックで「いいね (0)」へ戻った。[自己いいねPC](04_self-like.png)・[モバイル表示](05_self-like-mobile.png)・[モバイル解除](06_self-unlike-mobile.png) |

補足確認:

- ゲストには各レビューの「いいね (0)」がログインリンクとして表示された。[証拠](01_guest-links.png)。直接POSTの拒否は自動テストの確認結果であり、画面UATで認可を迂回した送信はしていない。
- 山田のレビューへ山田・田中がそれぞれいいねし、田中の画面で「いいね済み (2)」を確認した。[2人分](07_two-users.png)。山田が解除すると山田には「いいね (1)」、再表示した田中には「いいね済み (1)」となり、他利用者の状態を維持した。[山田の解除後](08_other-like-preserved.png)・[田中の状態維持](09_other-user-still-liked.png)。
- 保存失敗・実並行操作・削除済みへの直接POSTなどの内部検証は上記自動テストの結果として区別する。手動ACの3条件を後続機能の完成扱いにしない。

## 後片付け・残件

いいねを本人の画面操作で解除し、確認用書籍・ジャンルも作成者の画面操作で削除した。準備した専用利用者は記録したID・メールを照合して削除し、業務7表の全保存値がUAT開始前と完全一致（各0件）することを確認した。AUTO_INCREMENTの巻き戻しは行っていない。作業用ブラウザータブ2つと隔離HTTPコンテナを片付け、隔離コピー・fixtureも終了時に削除した。既存サービス・ボリューム・ユーザーの引き継ぎ変更を維持した。

Q-19の他機能の未確定文言、後続機能のAC、実環境のログ設定上書き状況は今回の確認対象外。コミット・push・PR作成は行っていない。
