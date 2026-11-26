# Issue #10 検証結果

確認日: 2026-11-26（日本時間） / ブランチ: `issue-10-ranking` / 開始コミット: `0f6a8a4`

公開GET `/ranking`をRankingControllerへ接続した。単一SELECTで書籍情報とレビューの評価合計・件数を取得し、導入済みbrick/mathの整数交差積で平均降順→件数降順→書籍ID昇順に比較して上位10冊を選ぶ。表示用平均はBigRationalから求め、DBのAVGの精度や表示値を順位比較へ流用しない。正本はdocs/designのF-21・SC-11・Q-14とbehavior.mdのIssue #10補足。

## 自動テスト・レビュー

| 確認 | 結果 |
|---|---|
| 最終全テスト | **273件・7,765アサーション成功**。失敗・エラー・スキップ0、終了0。下記のテスト内容補強後の結果 |
| RankingTest | 13件・1,030アサーション成功 |
| RankingOrderTest | 1件・5アサーション成功 |
| 変更PHPのPint | 初回6PHP、補強後は変更したRankingTestのみ成功、各終了0。整形後の差分で全テスト実行 |
| 実行環境 | PHP 8.5.12 / Laravel 10.50.4 / MySQL 8.4.13。ONLY_FULL_GROUP_BY有効、div_precision_increment=4 |
| 元ファイル／隔離コピー | Controller・比較補助・ルート・新旧3テストの6PHPがバイト一致。親も照合済み |
| 業務7表 | テスト開始／終了、UAT終了の全保存値JSONが一致。各0件。親も比較済み |
| 差分レビュー | 公開経路、単一SELECT、整数比較、同点優先順、上位選抜後のキー振り直し、表示と比較の分離を確認 |
| git diff --check | 成功 |

初回全テストでは隔離コピーのlang/不足により認証文言3件が失敗した。元コードの問題ではなく、言語ファイルを明示複製して再実行し、上表の最終全テストが成功した。最終テスト後のアプリ変更はない。カバレッジは今回未測定で、単位12の再測定を残す。

| AC／T | 自動テスト |
|---|---|
| AC-10-01・03、T-10-1・4・5 | `test_public_boundaries_and_each_rendered_card`：ゲスト／会員、0・1・10・11冊、未レビュー除外、固定ID列、各カードの順位・画像・No Image・タイトル・著者・星・平均2桁／1桁・件数・詳細リンク |
| AC-10-02、T-10-2・3 | `test_close_averages_and_equal_average_tiebreaks`：AVGでは消える微差、真の同平均の件数順・ID順、10位／11位境界 |
| T-10-6 | `test_web_review_changes_recalculate_without_changing_other_books`：既存レビューへのWeb追加・更新・非最後の削除・最後の削除と平均／件数／順位・対象外保持 |
| T-10-6 | `test_web_deletion_promotes_eleventh_book`：最後のレビュー削除と書籍削除の別ケースで対象除外・11位繰り上げ・対象外保存値保持 |
| 取得整合・障害 | `test_single_aggregate_select_and_safe_failure`：単一集計SELECTと、障害時の安全な500応答・保存値不変 |
| 大整数 | `test_large_integer_cross_products_and_tiebreaks`：PHP整数範囲を超える交差積、平均差・件数差・ID差。大量DB行は作らない |

通常の整数AVGでは同じ4.0050となる805/201と809/202を用い、真の平均が高い前者を先にする。表示は両方4.00・バッジ4.0で、件数201と202の差に順位を逆転させない。比較方式の選定と候補数Bに対するO(B)メモリ・O(B log B)ソートの制約はbehavior.mdに記録済み。

検証は保護対象.envを含まない明示許可項目の新しい隔離実コピーと公開.env.testing、PHP 8.5コンテナ、MySQL 8.4のtesting DBだけで実行した。元ルートはコンテナへマウントせず、開発用DB・既存サービス・ボリュームは変更していない。実行形（一時コピーは検証終了後に削除）:

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue10-tz533fl6:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app vendor/bin/pint \
  app/Http/Controllers/RankingController.php app/Support/RankingOrder.php \
  routes/web.php tests/Feature/AuthenticationTest.php \
  tests/Feature/RankingTest.php tests/Unit/RankingOrderTest.php
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue10-tz533fl6:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test \
  --log-junit=/var/www/html/storage/logs/issue10-final-junit.xml
```

## テスト内容レビュー後の補強（2026-11-26）

ユーザー承認に基づき、RankingTestの既存8境界ケースを補強した。タイトル・著者・画像URL・IDはFactory作成時のscalar配列を期待値として保持し、返却属性と各カードのDOMへ独立に照合する。画像あり／なしの分岐も元データから決め、レスポンス内のBookを期待値に流用しない。書籍0件に加え、未レビュー書籍が存在する状態でも空配列・カード0件・確定した空表示文言を確認する。両状態ともゲストと認証済みで実施した。

新しい隔離コピーだけに次の誤変更を個別注入し、テストの期待した照合が失敗することを確認した。各ケースの終了コードは1、エラーではなくアサーション失敗で検出し、ケースごとに完全復元した。原本アプリ・Bladeには注入していない。

| 隔離コピーだけの誤変更 | 検出結果 |
|---|---|
| 返却titleを固定値へ変更 | 6ケースが作成元属性との不一致で失敗 |
| 返却authorを固定値へ変更 | 6ケースが作成元属性との不一致で失敗 |
| 返却image_urlをすべてNULLへ変更 | 6ケースが作成元属性との不一致で失敗 |
| 元は画像なしの書籍へ画像URLを誤設定 | 4ケースが作成元属性との不一致で失敗 |
| 未レビュー書籍があるときだけ空表示文言を変更 | 8ケースが確定文言の照合で失敗 |

復元後に変更テスト1PHPをPintで整形し、**最終全273件・7,765アサーション成功**（失敗・エラー・スキップ0、終了0）。RankingTestは13件・1,030アサーションで、前回の948から82増加した。テストケース数は増やしていない。前回の全273件・7,683アサーションは補強前の結果として区別する。

補強検証も保護対象.envを含まない明示許可項目の隔離実コピー・PHP 8.5・MySQL 8.4のtesting DB限定で行った。隔離パスは`/private/tmp/bookshelf-issue10-tests-y5hqsfax`、最終実行は同パスをmountしたコンテナ内の`artisan test --log-junit=/var/www/html/storage/logs/issue10-strengthened-final-junit.xml`。一時パスは確認後に削除した。親も誤変更5種のJUnit失敗と内容、復元後の最終JUnit成功、業務7表の開始終了全保存値一致（各0件）、元／隔離の6PHPとBladeのバイト一致を確認した。原本アプリ・Blade・既存引き継ぎ等9ファイルのSHA256は補強開始時と一致する。

アプリ・Bladeの変更はなく、画面UATは再実施していない。下記の画面証拠は初回UATの結果を維持する。今回の変更はRankingTestと設計上の検証補足、本報告であり、コミット・push・PR作成は行っていない。

## Playwright MCPによる画面確認

隔離HTTP環境`http://127.0.0.1:8082`、PC 1440×1000・モバイル390×844、APP_ENV=testing・APP_DEBUG=false・SESSION_DRIVER=file、専用のゲストブラウザーコンテキストで確認した。既存ビルド済みアセットを利用し、隔離コピーだけに静的ファイル用ルーターと確認用表紙SVGを用意した。

開始時にtestingの書籍・レビューが0件であることを確認し、専用利用者1名・書籍12冊（レビューあり11冊＋なし1冊）・レビュー418件を作成した。初期Seederの投入結果とは区別する。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-10-01 | 満たす | ゲストで1〜10位の10冊を表示。11位と未レビュー書籍は除外。表紙読込・No Imageを確認し、両幅で1位のタイトルから対応詳細へ遷移。[PC一覧](01_pc_ranking.png)・[PC詳細](02_pc_detail.png)・[モバイル一覧](03_mobile_ranking.png)・[モバイル詳細](04_mobile_detail.png) |
| AC-10-02 | 満たす（並び順） | 5.0が先頭、同平均4.5は4件→2件→2件で後2冊はID昇順。805/201は809/202より先で、両方4.00表示。両幅で同じ10冊・連番・href・表示値をDOMでも照合。[PC](01_pc_ranking.png)・[モバイル](03_mobile_ranking.png)。モバイルの件数の視認性は下記制約あり |
| AC-10-03 | 満たす | 専用レビューだけを削除し、書籍は12冊残した状態で再表示。「まだレビューが投稿された書籍がありません。」を両幅で確認。[モバイル](05_mobile_empty.png)・[PC](06_pc_empty.png) |

確認時の期待ID順は41448→41446→41444→41445→41447→41443→41449→41450→41451→41452。除外は41453（11位）と41454（レビューなし）。IDは今回の確認記録であり、仕様や将来のテストで固定しない。

### モバイル表示の制約

幅390pxでは完成済みランキングBladeのレビュー件数が狭い幅で折り返され、右側の評価バッジと重なった。タイトルも既存のtruncate指定で省略される。横スクロールは発生せず、順位・リンク遷移・空表示・DOM上の平均と件数・並び順は確認できたが、表示品質の問題を解消済みとは扱わない。完成済みBladeの変更禁止に従い未修正とし、[モバイル一覧](03_mobile_ranking.png)を残す。対応にはBlade変更範囲の調整が必要。

## 後片付け・変更範囲

専用利用者の記録IDとemailを限定して削除し、今回の書籍・レビューは既存CASCADEで除去した。UAT前後の業務7表の全保存値は一致（各0件）。AUTO_INCREMENTは巻き戻していない。作業用ブラウザータブ・HTTPコンテナ・隔離コピー・fixtureを片付けた。

アプリ変更はController・比較補助・公開経路の接続。既存認証テストの501期待値を200へ更新し、ランキングテストを追加した。完成済みBlade・DB物理定義・依存パッケージは変更していない。ユーザーの既存引き継ぎ変更と既存未追跡.playwright-mcp/は維持した。コミット・push・PR作成は行っていない。
