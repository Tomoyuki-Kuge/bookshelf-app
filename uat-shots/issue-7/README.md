# Issue #7 検証結果

実施日: 2026-11-26（日本時間）。対象: F-15〜17、SC-02の投稿・本人操作／SC-05、AC-7-01〜04、T-7-1〜12。

## 実装と自動テスト

ReviewControllerとReviewRequestを追加し、既存の投稿・編集・更新・削除ルートへ接続した。認証→対象確認→本人認可→入力検証、保存直前の存在・本人再確認、親書籍の共有ロック→レビューの更新ロック、トランザクションによる取消を実装した。投稿者は認証情報、対象書籍は経路から決定し、更新でも両方を維持する。失敗時は内部フォームへ型安全な旧入力とQ-19の暫定日本語エラーを戻す。

完成済みBlade・DB定義・依存パッケージ・共通障害処理は変更していない。いいね操作・お気に入り・ランキング・公開APIは後続単位のまま。

- PHP 8.5.12／Laravel 10.50.4／MySQL 8.4.13、DBはtesting限定。
- レビュー対象: **34テスト・792アサーション成功**（終了0、3.20秒）。
- 初回実装の変更PHP5ファイルをPintで整形後、全件: **206テスト・5,910アサーション成功**（終了0、14.51秒）。元と隔離の変更PHP5ファイルの全バイト一致、親による差分レビュー、git diff --check成功。後述のテスト補強後の最終結果は206件・5,933アサーション。
- 元.envを含まない明示許可項目だけの隔離実コピー、公開.env.testing、PHP 8.5コンテナを使用。元のプロジェクトルートはマウントせず、テストの子プロセスも隔離コピー内で起動した。

実行形（隔離コピーは後片付け済み）:

```sh
docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue7-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test --coverage --coverage-clover=/var/www/html/storage/logs/issue7-coverage.xml
```

Pintは同じ実行形で次の5ファイルを指定した。

```text
vendor/bin/pint app/Http/Controllers/ReviewController.php app/Http/Requests/ReviewRequest.php routes/web.php tests/Feature/ReviewManagementTest.php tests/Feature/AuthenticationTest.php
```

| テスト観点 | 主なテスト | 確認内容 |
|---|---|---|
| T-7-1・2・4・5・8・10 | test_post_edit_update_preserve_identity_and_unrelated_data | 自分／他人の書籍へ投稿、複数投稿、ID偽装無視、保存直後の全項目、編集初期値・GET不変、対象外データ保持 |
| T-7-3・4・6 | test_invalid_input_returns_to_internal_form_with_safe_old_input（19データセット、各POST/PUT） | 必須・型・範囲・1000/1001文字、固定期待文言・DOM欄下表示・旧入力・全業務表不変 |
| T-7-7〜9 | test_authentication_target_and_owner_checks_precede_validation | 未認証・対象・本人・入力の判定順、書籍作成者による他者レビュー拒否、不正ID、拒否後全保存値不変 |
| T-7-10・11 | test_delete_cascades_only_target_likes_and_deleted_urls_return_404 | 対象レビュー・いいねだけのCASCADE、他関連全保存値保持、削除後404 |
| T-7-12（模擬） | test_deleted_target_or_changed_owner_is_rechecked_before_save、test_unexpected_failures_roll_back_all_changes_without_success_flash | 保存直前の親・対象消失と本人再認可、一般／DB障害の全体取消・安全な500・成功フラッシュなし |
| T-7-12（実並行） | test_independent_process_handles_concurrent_operation（6ケース）、test_parent_shared_lock_blocks_book_delete_until_review_operation_commits（3ケース） | 後勝ち、レビュー／書籍削除後404、POST/PUT/DELETEの親共有ロック中の書籍削除待機 |

実並行9ケースは独立DB接続・独立PHPプロセス、標準入力による同期、SHOW PROCESSLISTの待機SQL観測、12秒タイムアウト、finallyでの後片付けを使用した。固定sleepだけで順序を仮定せず、模擬競合と区別した。

途中検証ではLaravelの整数検証がrating=trueを受理することを検知し、int/string以外の型を拒否する検証とbool/floatケースを追加した。実並行テスト生成時のFQCNエスケープ不備も修正済み。これらの途中失敗と、修正後の最終全件成功を区別する。

## 初回カバレッジ

導入済みpcovを利用し、環境設定やパッケージを変更せず測定した。app配下37ファイル、274対象行のうち266行到達で**97.1%**（Clover集計97.08%）。ReviewController／ReviewRequest／ReviewPolicyは100%。

未到達8行はConsole Kernelのschedule、Handlerの認証以外の標準ValidationException復帰、BookControllerのジャンル再確認・FK例外、TrustHostsの標準設定、RouteServiceProviderのAPIレート制限。子プロセス内の実行範囲は親プロセスのカバレッジへ統合していない。

これは現在存在するappの初回測定であり、未実装の後続機能や全基本要件の完了を示さない。単位12で再測定し60%超の目標への到達を確認する予定を維持する。

## Playwright MCPによる画面確認

隔離HTTP環境 http://127.0.0.1:8082 、PC 1440×1000／モバイル390×844。APP_ENV=testing、SESSION_DRIVER=file、APP_DEBUG=falseで起動した。公開.env.testing・既存ビルド済みアセットを使い、元.envは参照していない。

testing業務7表各0件から、専用の山田太郎・鈴木花子、各1冊の書籍、1ジャンル、鈴木の保持確認用レビューを準備した。初期Seederの検証ではない。ログインは専用メール yamada.issue7.uat@example.com／suzuki.issue7.uat@example.com と password を使用。確認対象の山田の4レビューはすべて通常の画面操作で投稿した。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-7-01 | 満たす | 山田がPCでは他人の書籍、モバイルでは自分の書籍へ投稿。同じ詳細に戻り、評価・本文と「レビューを登録しました。」を表示。[PC](02_created-pc.png)・[モバイル](14_created-mobile.png) |
| AC-7-02 | 満たす | 同じ利用者が同じ書籍に2件目を投稿し、先の投稿と2件目の両方が表示された。[PC](03_multiple-pc.png)・[モバイル](15_multiple-mobile.png) |
| AC-7-03 | 満たす | 編集で既存の評価・コメントを初期表示し、評価とコメントを更新して詳細へ復帰。「レビューを更新しました。」と変更内容を表示。[PC初期値](04_edit-initial-pc.png)・[PC更新](06_updated-pc.png)・[モバイル初期値](16_edit-initial-mobile.png)・[モバイル更新](18_updated-mobile.png) |
| AC-7-04 | 満たす | 削除確認を承認して同じ詳細へ復帰し、対象が消え「レビューを削除しました。」を表示。2件目・他投稿者のレビューを維持。旧編集URLは404。[PC削除](11_deleted-pc.png)・[PC旧URL](12_deleted-url-pc.png)・[モバイル削除](21_deleted-mobile.png)・[モバイル旧URL](22_deleted-url-mobile.png) |

### 補足確認と画面操作の範囲

- PCは評価未選択で実POSTし「評価は必須です。」とコメント保持、モバイルはコメント空で実POSTし「コメントは必須です。」と評価5保持を確認した。不正投稿ではレビューが増えず、その後の修正投稿が1件だけ表示された。[PC](01_invalid-post-pc.png)・[モバイル](13_invalid-post-mobile.png)。PC投稿の評価欄はrequired=false、checkValidity()=true、validationMessageは空、フォームはnovalidate=trueで、サーバー応答の欄下文言を確認した。
- PC・モバイルとも実PUTでコメント空を送信し、変更後の評価1と空コメントを保持して欄下エラーを表示した。[PC](05_invalid-update-pc.png)・[モバイル](17_invalid-update-mobile.png)。編集キャンセルで未保存の本文が詳細に出ず、保存済み本文を維持した。[PC](07_edit-cancelled-pc.png)・[モバイル](19_edit-cancelled-mobile.png)。
- 書籍作成者の鈴木へ切り替え、山田のレビューの編集・削除が非表示、鈴木自身のレビューには表示されることを確認。山田の編集URLへのGETは403だった。[書籍作成者表示](08_book-owner-view-pc.png)・[403](09_forbidden-pc.png)。他人のPUT/DELETEとID偽装拒否は自動テストで検証しており、UATで直接送信していない。
- 削除確認「本当に削除しますか？」の中止で対象を維持し、その後の承認で削除できた。[PC中止後](10_delete-cancelled-pc.png)・[モバイル中止後](20_delete-cancelled-mobile.png)。
- 最初の編集操作はsr-onlyのラジオinputへの直接クリックがタイムアウトしたため中断し、スナップショットで既存値・画面を再確認した。表示された星のlabelによる通常クリックでやり直し、更新・入力エラーを確認した。アプリ・Blade修正による解消とは扱わない。ダイアログは観測した確認文言に対して中止／承認した。ツール引数不備の試行は確認成功に数えていない。
- 文字数境界・保存前競合・いいね連鎖削除の内部結果は自動テストの結果であり、画面UATで全組み合わせを再実行していない。

## 残件と後片付け

Q-19のレビュー5文言はユーザー承認による暫定採用で、クライアントによる正式確定は後日確認。実環境のログ設定上書き状況は未確認。後続単位の機能・ACは未完了のまま。

画面から作成した山田の4レビューは本人の画面操作で削除した。準備したデータだけを記録IDで後片付けし、業務7表の全保存値が開始前と一致（各0件）することを確認した。作業用ブラウザータブと一時HTTPコンテナを閉じ、隔離コピー・fixtureを削除した。既存サービス・ボリューム・ユーザーの既存変更は保持した。

## テスト内容レビュー後の補強（2026-11-26）

ユーザー承認によりReviewManagementTestの検出力を次の2点で補強した。アプリ・ルート・既存認証テストは補強前後でSHA256一致、変更はテストと対応する設計・検証記録だけ。画面UATは再実行せず、上記の既存結果を維持する。

- 入力不正の19データセット各POST／PUTに、対象・別レビューのいいね、お気に入り、ジャンル・book_genreを準備。空表の比較だけでは検出できなかった関連行の誤削除も、日時・関連IDを含む業務7表の全保存値比較で検出する。
- 書籍削除との実並行3ケースでは、レビュー操作を確定した後、書籍削除をBookのdeletingイベントで実削除直前に同期停止する。レビュー操作のstatus・Location・成功フラッシュ・エラーなしをJSONで厳密比較し、独立接続でPOSTの新規全項目、PUTの更新項目と本人・書籍・作成日時・いいねの保持、DELETEの対象といいねの不存在、その他の全保存値不変を確認してから書籍削除を許可する。最終的なCASCADEと書籍削除の成功応答も確認する。

隔離コピーだけに以下の誤変更を1件ずつ入れ、補強テストが実際に失敗することを確認した。元アプリへ誤変更は反映していない。

| 隔離内だけの誤変更 | 実行対象 | 検出結果 |
|---|---|---|
| ReviewRequestの入力エラー処理でreview_likesを全削除する | test_invalid_input_returns_to_internal_form_with_safe_old_inputの#0 | 全保存値比較で1件失敗・終了1 |
| 親共有ロックを保持したレビュー操作を取り消し、成功302を返す | test_parent_shared_lock_blocks_book_delete_until_review_operation_commits | POST新行不足・PUT保存値不一致・DELETE対象残存で3件失敗・終了1 |

両方を復元し、変更テスト1PHPをPintで整形後、**補強後の最終全206テスト・5,933アサーション成功**（終了0、14.67秒）。カバレッジ97.1%。対象34テストは815アサーション成功（終了0、3.35秒）。親による補強差分レビュー、変更テストの元／隔離バイト一致、アプリ不変、git diff --check成功。

実行形:

```sh
docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue7-test-hardening:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test --coverage --coverage-clover=/var/www/html/storage/logs/final-coverage.xml --log-junit=/var/www/html/storage/logs/final-junit.xml
```

元.envを含まない明示許可項目だけの隔離実コピーと公開.env.testingを使い、子プロセスも隔離内で実行した。終了時に業務7表の全保存値が開始前のbaselineと一致（各0件）することを確認し、隔離コピー・補強用一時ファイルを削除した。既存サービス・ボリューム・ユーザーの既存変更は保持した。
