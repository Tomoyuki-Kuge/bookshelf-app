# Issue #9 検証結果

確認日: 2026-11-26（日本時間） / ブランチ: `issue-9-favorites` / 開始コミット: `2bbd05f`

お気に入り2経路をFavoriteControllerへ接続した。一覧は自分の登録分を追加日時降順・10件／ページで取得する。書籍行の排他ロック後に登録状態を読み、認証利用者と経路の書籍の組だけを追加・解除する。戻り先は同一オリジンのRefererを検証して内部ルートから生成し、コミット後の件数で一覧ページを補正する。正本はdocs/designのF-19〜20・SC-02／10・Q-23〜24とIssue #9補足。

## 自動テスト・差分確認

| 確認 | 結果 |
|---|---|
| 変更3PHPのみPint | 成功、終了0 |
| 整形後の全テスト | **259件・6,728アサーション成功**、失敗・エラー・スキップ0、終了0 |
| FavoriteTest | 32件・509アサーション成功、実並行9ケースを含む |
| 元ファイル／隔離コピー | Controller・ルート・テストの3PHPがバイト一致 |
| 自動テスト前後の業務7表 | 全保存値JSON一致、各0件。親も開始終了JSONを比較した |
| 静的レビュー | 認証情報・経路による対象決定、排他後の読み取り、対象外保持、内部遷移を確認。修正待ちの指摘なし |
| git diff --check | 成功 |

AC／TとFavoriteTestの対応:

- AC-9-01〜02、T-9-1・2・7: `test_toggle_uses_only_authenticated_user_and_route`。本人・他者書籍、送信ID無視、連続切り替え、日時、古い関連キャッシュ、対象外の全保存値、詳細のハートと一覧への反映を照合。
- AC-9-04、T-9-3〜5: `test_index_filters_before_pagination_and_uses_favorite_dates`。書籍日時と逆の追加日時順、他利用者除外、0・1・10・11件、表示項目・No Imageを照合。
- AC-9-03、T-9-2・8: `test_referer_and_page_are_validated`、`test_removal_clamps_disappeared_page_and_empty_list`。操作元、外部・不正Referer、pageの型・重複・範囲、ページ維持・消滅・全件0件、内部Locationとフラッシュなしを照合。
- T-9-6〜7: `test_guest_missing_and_deleted_books_preserve_saved_values`。ゲスト・不存在・削除済み、Web書籍削除後のCASCADEと対象外不変を照合。
- T-9-9: `test_failure_after_write_rolls_back_every_saved_value`。追加・解除の書き込み後の模擬障害で全体取消、安全な500応答と機密を含まないログを照合。
- T-9-9: `test_concurrent_toggles_and_deletion_preserve_committed_state`。同一組を未登録／登録済みから2回操作、別利用者の同書籍操作2ケース、同一／別利用者の別書籍への追加2ケース、書籍削除先行、追加／解除先行の削除待機の計9ケース。独立プロセスと同期・実際の待機SQL観測、タイムアウト・後片付けを使い、中間の確定値・応答・日時・対象外不変を照合。固定sleepだけで順序を仮定していない。

実行は保護対象.envを含まない明示許可項目の隔離実コピーと公開.env.testing、PHP 8.5コンテナ、MySQL 8.4のtesting DBに限定した。元ルートをマウントせず、子プロセスも隔離コピー内で実行した。実行形（一時パスは終了後に削除）:

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue9-bpjuk3hu:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app vendor/bin/pint app/Http/Controllers/FavoriteController.php routes/web.php tests/Feature/FavoriteTest.php
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue9-bpjuk3hu:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test --log-junit=/var/www/html/storage/logs/issue9-final-junit.xml
```

routes/web.phpはお気に入り2経路の接続先だけを置き換え、認証・名前・メソッド・パスを維持した。Policy・migration・config・依存パッケージ・完成済みBladeに変更はない。routes/api.phpは存在せず今回も追加していない。一覧Bladeは書籍項目だけを参照し、今回の接続で関連取得のN+1は追加していない。

## Playwright MCPによる画面確認

隔離HTTP環境 `http://127.0.0.1:8082`、PC幅1440×1000・モバイル幅390×844、APP_ENV=testing・APP_DEBUG=false・SESSION_DRIVER=file、既存ビルド済みアセットを利用した。空のtesting DBへ既存DatabaseSeederを初回投入し、初期5名とは別に専用利用者を作成した。境界値は専用利用者だけに0・1・10・11件を準備し、初期5名の登録分は変更していない。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-9-01 | 満たす | 詳細の未登録ハートをクリックし、同じ詳細で登録状態になり、一覧に1冊を表示。成功フラッシュなし。[詳細PC](02_added-detail-pc.png)・[一覧PC](03_added-list-pc.png)・[詳細390px](11_added-mobile.png)・[一覧390px](13_added-list-mobile.png) |
| AC-9-02 | 満たす | 詳細の登録済みハートをクリックし、同じ詳細で未登録へ戻り、一覧は0件になった。[詳細PC](04_removed-detail-pc.png)・[詳細390px](12_removed-mobile.png) |
| AC-9-03 | 満たす | 11件の2ページ目の1冊を解除するとpage=1へ戻り10冊表示。次の解除ではpage=1を維持して9冊、最後の1冊解除後はpage=1の0件表示。成功フラッシュなし。[補正前PC](06_last-page-pc.png)・[補正後PC](07_clamped-page-pc.png)・[現ページ維持PC](08_same-page-pc.png)・[全件0件PC](09_last-removed-empty-pc.png)・[補正前390px](15_last-page-mobile.png)・[補正後390px](16_clamped-page-mobile.png)・[現ページ維持390px](17_same-page-mobile.png)・[全件0件390px](14_last-removed-empty-mobile.png) |
| AC-9-04 | 満たす | 0件の文言と「書籍一覧を見る」を表示し、リンクから一覧へ移動できた。PC・390px双方で確認。[PC](01_empty-pc.png)・[390px](10_empty-mobile.png) |
| AC-3-03 | 満たす | 初期5名がpasswordでログインでき、各自の名前と指定の3・4・5・3・4冊を表示。[山田](18_seed-user-1.png)・[鈴木](19_seed-user-2.png)・[田中](20_seed-user-3.png)・[佐藤](21_seed-user-4.png)・[高橋](22_seed-user-5.png) |
| AC-1-02 | 満たす | 山田の一覧に指定3冊が表示され、そこから「吾輩は猫である」の詳細へ移動すると登録済みハートと3件の「いいね済み」を表示。[一覧](18_seed-user-1.png)・[詳細](23_seed-detail-state.png) |

ページ番号リンクを表示文字「2」で指定した操作が一度タイムアウトした。該当確認を中断してスナップショットを取得し、実際のアクセシビリティ名「Go to page 2」を確認して再開した。補正確認は再開後の観測を根拠とした。最初のHTTP起動では静的アセットへの応答がHTMLになったため、隔離環境だけのPHP組み込みサーバー用ルーターを用意して再起動し、その後にUATを実施した。アプリコード・Blade・既存アセットは変更していない。

## 後片付け・未確認範囲

作成した利用者・ジャンルのIDを記録し、そのIDだけを削除して関連行をCASCADEで片付けた。UAT開始前後の業務7表全保存値は完全一致（各0件）。AUTO_INCREMENTは巻き戻していない。作業用ブラウザータブ、隔離HTTPコンテナ、隔離コピーとfixtureを片付け、既存サービス・ボリューム・ユーザーの引き継ぎ変更と未追跡ファイルは維持した。

カバレッジ再測定は単位12で予定しており今回は未実施。不正CSRFトークンの実際の拒否・実環境のログ設定上書き状況は今回未確認で、既存テストや正常なフォーム送信と区別する。Q-19の他機能の保留文言・ランキング・公開API・単位12全体の完成は今回の結果に含めない。コミット・push・PR作成は行っていない。

## テスト内容レビュー後の補強（2026-11-26ユーザー承認）

`test_index_filters_before_pagination_and_uses_favorite_dates`を補強した。初回のテストデータは追加日時降順とID降順が一致していたため、追加日時を固定の非単調順に変更した。期待順は書籍の作成順番号で `[8, 13, 3, 10, 5, 14, 6, 11, 1, 9, 4]` とし、書籍ID・お気に入り中間行IDの昇順／降順および書籍登録日時順と区別する。0・1・10・11件とページ別のID列・総件数・表示件数の確認を維持した。

空表示リンクの確認も、ページ全体のURL文字列検索から、main内の「書籍一覧を見る」のリンク要素1件とhrefの照合へ変更した。共通ナビの書籍一覧リンクだけでは合格しない。設計上の検証観点は先にtest_case.mdへ追記した。アプリ・元のBladeは変更していない。

保護対象.envを含まない新しい隔離実コピー、PHP 8.5コンテナ、MySQL 8.4のtesting DBだけで、次の誤変更を個別に注入した。各ケースの後で対象ファイルを完全に復元した。

| 隔離コピーだけに入れた誤変更 | 補強した対象テストの結果 |
|---|---|
| 一覧をbooks.id降順へ置き換え | 期待ID列の不一致で1件失敗、終了1 |
| 一覧をbooks.id昇順へ置き換え | 同上、1件失敗、終了1 |
| 一覧をfavorites.id降順へ置き換え | 同上、1件失敗、終了1 |
| 一覧をfavorites.id昇順へ置き換え | 同上、1件失敗、終了1 |
| 空表示のリンク要素だけを除去（共通ナビは保持） | main内リンク数が期待1件に対して0件となり失敗、終了1 |

誤変更をすべて復元し、変更したテスト1PHPだけをPintで整形した後、**最終全259テスト・6,730アサーション成功**（失敗・エラー・スキップ0、終了0）。FavoriteTestは32件・511アサーション成功。件数は増やさず既存ケースのデータと照合を強化した。親も誤変更5種のJUnit失敗内容、最終JUnit成功、Controller・Blade・補強テストの元／隔離バイト一致を確認した。

実行形（一時パスは終了後に削除）:

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue9-tests-bwv0ex62:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app vendor/bin/pint tests/Feature/FavoriteTest.php
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue9-tests-bwv0ex62:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test --log-junit=/var/www/html/storage/logs/issue9-tests-final-junit.xml
```

画面・アプリの動作変更がないためUATは再実施せず、上記の初回画面確認結果を維持する。カバレッジ・不正CSRFトークン・実環境設定の未確認範囲も変わらない。

補強検証の開始終了でも業務7表の全保存値は一致（各0件）し、親がJSONを比較した。アプリ・ルート・元Blade・既存引き継ぎのSHA256は補強開始前と一致。今回の隔離コピーは削除済みで、git diff --checkも成功した。
