# Issue #11 公開書籍APIの検証結果

確認日: 2026-11-27（日本時間） / ブランチ: `issue-11-public-books-api` / 開始コミット: `cb5ffa6`

F-22〜26・AP01〜05の公開書籍APIを接続した。5経路とも認証・トークン・Web本人Policyを要求せず、API用FormRequest・共通BookResourceで入出力を扱う。設計の正本はdocs/designのpermissions-api.md、behavior.md、questions.mdのQ-18・19・22・23。Blade・DB定義・Seeder・依存パッケージの変更はない。

## 自動テスト・レビュー

| 確認 | 結果 |
|---|---|
| 最終全テスト | **349件・8,859アサーション成功**。失敗・エラー・スキップ0、終了0。下記のテスト内容補強後の結果 |
| PublicBooksApiTest | 68件・1,015アサーション成功 |
| PublicBooksApiConcurrencyTest | 8件・79アサーション成功 |
| 変更PHPのPint | 初回12ファイル、レビュー修正後は並行テスト1ファイル、内容補強後はAPIテスト1ファイルを明示して整形、各終了0。修正後も全テスト成功 |
| 環境 | PHP 8.5.12・Laravel 10.50.4・MySQL 8.4.13、testing DB限定 |
| 原本と隔離コピー | 最終12 PHPがバイト一致。親も照合済み |
| 業務7表 | 自動テスト開始終了・UAT開始終了の全保存値JSON一致（各0件）。親も照合済み |
| 保護対象 | 既存引き継ぎ・全Blade・参照控え67ファイルのハッシュ一致 |
| git diff --check | 成功 |

| 観点 | 主な自動テスト・確認内容 |
|---|---|
| T-11-1〜4・8・12・17〜20 | `test_public_crud_common_resource_and_web_owner_transfer`、`test_each_public_route_for_guest_and_web_member`。両主体の全5経路、元入力に基づく公開値・型、13レビュー全件・日時順・同日時ID順、平均4.7への丸め、登録者変更後の旧本人403・新本人Web操作成功、保存対象外の全保存値保持 |
| T-11-5〜7・21〜22 | `test_search_pagination_literal_matching_and_links`、`test_invalid_query`。タイトルOR著者とジャンルAND、大小・全半角・空白・通常文字!/%/_、ページごとのID列、検索条件保持、0件・最終超過・PHP_INT_MAXとper_page=100、境界と日本語422 |
| T-11-9〜14・18〜19・23 | `test_invalid_json_inputs_for_post_and_put`、`test_valid_boundaries_required_fields_and_optional_values`。POST/PUTの必須・型・上限・日付・URL・ISBN・ジャンル、JSONオブジェクトと配列の区別、JSON整数と数値文字列/小数/真偽値の区別、queryによる本文必須の補完拒否、任意項目の省略/null/空文字 |
| T-11-15・16・24 | `test_error_contract_priority_and_safe_failure`、`test_delete_cascades_only_target_relations`。404優先・不正JSON400・405とAllow・422・安全な500、CASCADE対象と対象外の7表全保存値比較 |
| T-11-24の模擬競合 | `test_simulated_reference_disappearance_and_database_errors`。参照ユーザー/ジャンル消失、既知ISBN制約と他DB障害を区別し、要素エラーと全体取消を照合 |
| T-11-24の実並行 | `test_concurrent_save_after_validation`、`test_shared_references_are_protected_until_commit`。独立プロセスと同期・待機SQLの観測で後勝ち、書籍消失404、現所有者削除によるCASCADE404、新登録者だけの消失422、ISBN競合、ジャンル消失、共有ロック中の削除待機を確認 |

初回APIテストではURL上限fixtureが1文字長く1件失敗し、255文字へ修正した。実並行テストでは保存後のロック解放と参照ユーザー削除によるCASCADEが応答集計の再取得と競合し500となる問題を検出した。関連・集計を保存トランザクション内でロードしてから応答するよう修正し、上表の最終コードで全テスト成功。巨大正数pageのoffset桁あふれを避ける取得方式、genres={}の確定配列型文言もレビュー・検証済み。

検証は元の.envを含まない許可項目の隔離実コピーと公開.env.testingで実行した。依存はsymlink先も実コピーし、元プロジェクトルートをコンテナへマウントしていない。実行形（隔離コピーは検証後に削除）:

```sh
docker run --rm --network bookshelf-app_sail \
  -v /private/tmp/bookshelf-issue11-twbeb1nc:/var/www/html \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  sail-8.5/bookshelf-app artisan test \
  --log-junit=/var/www/html/storage/logs/issue11-final-junit.xml
```

最終JUnitの343件・8,747アサーション・失敗/エラー/スキップ0を親も確認した。画面確認後にアプリコードを変更していないため、記録更新だけによるテスト再実行はしていない。

## レビュー指摘の修正・失敗時の後片付け（2026-11-27）

PublicBooksApiConcurrencyTestのuserケースで追加する参照ユーザーが、子プロセス起動・同期の途中失敗時に残る問題を修正した。finallyで元ユーザーと参照ユーザーのIDを重複除去して削除し、保存値比較のassertionが失敗しても検証用api_race接続を破棄する。

隔離コピーだけで実テストの起動直前へ故意に例外を注入した。未修正コードは終了1・参照ユーザー1件残存を再現し、その作成IDだけを削除して元の7表へ復元した。修正後の同じ注入は意図した例外で終了2、業務7表の実行前後全保存値一致・接続破棄を確認した。さらに保存値比較へ故意にassertion失敗を注入し、終了1でも7表保持・接続破棄を確認した。これらの失敗は検証用の意図した結果であり、原本には注入していない。

故意の注入を除いた最終テスト1PHPをPintで整形し、新しい隔離環境 `/private/tmp/bookshelf-issue11-cleanup-43dx131y` で全テストを再実行した。**343件・8,747アサーション成功、失敗・エラー・スキップ0、終了0**。並行テスト8件・79アサーションも成功。git diff --check成功。親もJUnit、失敗注入の結果、注入前から最終までの業務7表全保存値一致（各0件）、アプリ・ルート・Blade・既存引き継ぎのハッシュ保持を照合した。

今回の変更はテストの後片付けだけで、API本体・Bladeは変更していない。画面UATは下記の初回結果を維持し、再実施していない。今回作成した隔離環境・注入ファイルも検証後に片付けた。

## テスト内容レビュー後の6点補強（2026-11-27）

ユーザー承認に基づきPublicBooksApiTestを補強した。アプリ本体・Bladeは変更していない。対応する設計補足はtest_case.mdの「Issue #11のテスト内容レビュー後の補強」を参照する。

| 補強点 | 検証方法 |
|---|---|
| reviews必須・全件・空配列 | 詳細・POST・PUTはreviewsを必須キーとして元データと厳密比較し、未レビューも[]。一覧はreviewsなしを厳密比較 |
| 実在IDの整数型 | 実在するuser/genre IDだけを文字列・小数・真偽値へ変え、POST/PUTの型エラー本文と7表不変を比較。trueはDBで参照されるID=1の存在を明示確認・必要時だけテスト内で準備し、既存行は変更しない |
| 日時とIDの主従 | 専用ジャンルの4冊と4レビューに日時/IDの逆転と同日時を配置し、元scalarの明示ID順・公開全項目で照合 |
| 実削除後の全経路 | API削除した同じIDへGET・有効入力PUT・DELETEを送り、各404の確定本文・7表不変・再作成なしを比較 |
| PUTの実保存値 | 全入力項目を変え、応答とは別にDBから書籍項目・登録者・ジャンル集合を再取得して比較。任意項目の省略維持/null/空文字もDB照合 |
| 確定エラー文言 | page/per_page・参照user/検索genre・ID型・配列型・タイトル必須・ISBN桁数/重複のキーと文字列配列を厳密比較 |

`assertBook`は返却値のreviews有無で比較を省略せず、呼出側で一覧と詳細を指定して公開オブジェクト全体を厳密比較する。`assertSavedInput`は返却モデルを期待値に使わずDBを再取得する。追加テストは`test_book_and_review_order_prioritizes_created_at_before_id`、`test_json_integer_types_are_strict_for_existing_references`、`test_confirmed_body_validation_messages`、`test_actual_deleted_book_is_not_recreated_by_any_endpoint`。既存CRUD・主体別CRUD・検索・任意項目テストも補強した。固定の不存在IDで型不正を代用していたケースは実在参照の型テストへ置き換えた。

隔離コピーだけに次の誤実装を1つずつ注入し、補強したアサーションが失敗することを確認した。すべて終了1、errors=0であり、実行エラーではなく期待結果の不一致として検出した。毎回アプリを復元し、業務7表の全保存値保持を確認した。原本アプリ・Bladeには注入していない。

| 隔離コピーの誤実装 | 検出 |
|---|---|
| reviewsキーを返さない | CRUDテスト1件失敗 |
| 厳密整数型の規則を除去し、参照存在検証は維持 | 実在参照の型テスト6件失敗 |
| 書籍のcreated_at順を除去 | 日時主従テスト1件失敗 |
| レビューのcreated_at順を除去 | 日時主従テスト1件失敗 |
| 実際に削除した確認用書籍への応答を誤らせる | 削除済み各経路テスト1件失敗 |
| PUTの返却モデルは新値のまま、DB著者だけ不一致 | CRUDテストの保存値比較1件失敗 |
| page不正の文言を別の日本語へ変更 | 検索不正テスト1件失敗 |

初回補強テストでは、不存在userをmax ID+1で準備した後のFactoryがそのIDを使うfixture不備1件を検出した。必要なFactory作成後に不存在IDを決めるよう修正し、最終正常実行では失敗なし。

復元後の変更テスト1PHPをPintで整形し、隔離環境 `/private/tmp/bookshelf-issue11-strengthen-809_8cna` のPHP 8.5・MySQL 8.4・testing DBで全テストを実行した。**349件・8,859アサーション成功、失敗・エラー・スキップ0、終了0**。API68件・1,015アサーション、並行8件・79アサーション。Pint・git diff --check成功。最終コマンドは同隔離パスをマウントした`artisan test --log-junit=/var/www/html/evidence/final-junit.xml`。

親も各注入JUnitのassertion失敗・errors0、最終JUnit成功、原本と隔離テストのバイト一致、注入開始から最終検証後までの7表全保存値一致（各0件）、アプリ・ルート・Blade・既存引き継ぎのハッシュ保持を確認した。今回の隔離環境・注入ファイルは検証後に片付けた。アプリ本体・Bladeの変更がないため画面UATは再実施せず、下記の初回確認結果を維持する。343件・8,747アサーションは補強前の結果として区別する。

## Playwright MCPによる画面確認

隔離HTTP環境 `http://127.0.0.1:8082`、1440×1000、APP_ENV=testing・APP_DEBUG=false、専用のゲストコンテキスト（Cookieなし）で公開GETを開いた。専用ユーザー1人・ジャンル2件・書籍4冊・レビュー3件を用意した。初期Seederの投入結果とは区別する。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-11-01 | 満たす | 4冊のdata、genres、平均3.3・レビュー3件、未評価null・0件、最上位links/meta（per_page=20・total=4）を確認。[一覧](01_list.png) |
| AC-11-02 | 満たす | keyword=API確認とgenre_id=32601を併用し、著者で一致する49282とタイトルで一致する49281だけを返す。別ジャンル49283、キーワード不一致49284を除外。[検索](02_search.png) |
| AC-11-03 | 満たす | 49281の書籍・2ジャンル・平均3.3・件数3と全レビューを確認。投稿者名・評価・コメント・UTC秒精度のcreated_atを含み、同日時レビューはID降順（評価5→4→1）。[詳細](03_detail.png) |
| AC-11-04 | 満たす | 不存在999999999は404で指定error。検索0件は空data・total=0。page=99&per_page=1は空data・current_page=99・last_page=4・from/to=null。[404](04_not_found.png)・[検索0件](05_empty_search.png)・[範囲外ページ](06_out_of_range.png) |

スクリーンショットはブラウザーの通常JSON表示で、日本語がUnicodeエスケープされている。表示本文をJSONとして読み、デコード後の文字列・数値・配列も照合した。本文を書き換えて撮影していない。不存在URLのコンソール404は期待した応答である。POST・PUT・DELETEと内部保存・競合は上記自動テストの結果であり、画面UATの結果と区別する。記載IDは今回の記録で、仕様や将来の検証で固定しない。

## 後片付け・残件

確認用ユーザーはIDと専用emailの両方で限定して削除し、書籍・レビューはCASCADEで除去、今回のジャンル2件も削除した。UAT前後の業務7表の全保存値一致を確認した（各0件）。AUTO_INCREMENTは巻き戻していない。専用タブ、HTTPコンテナ、隔離コピーとfixtureは片付けた。既存サービス・ボリューム・開発用DBは変更していない。

Q-19のAPI暫定文言は本日のユーザー承認に基づく採用で、正式文言はクライアント確認待ち。カバレッジ再測定・共通CSRF等の横断確認は単位12へ残す。実環境のログ設定上書きや既存のモバイルBlade表示制約は今回未調査。既存docs/CODEX_HANDOFF.mdの変更と.playwright-mcp/は保持した。コミット・push・PR作成は行っていない。
