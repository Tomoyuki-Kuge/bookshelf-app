# Issue #6 検証結果

実施日: 2026-11-25（日本時間）。対象: F-06〜09、SC-03〜04・SC-02の本人操作、AC-6-01〜04、T-6-1〜22。

## 実装と自動テスト

書籍登録・編集表示・更新・削除をBookControllerへ接続し、BookRequestで入力検証・暫定日本語文言・内部フォームへの復帰・型安全な旧入力・全ジャンル解除の保持を実装した。Web作成者は認証情報から設定し、更新でも維持する。任意項目の空欄はNULL、出版日は有効日付の検証後にYYYY-MM-DDへ揃えて保存する。

認証→数字列ID・対象確認→本人Policy→入力検証を適用し、更新・削除では保存直前に行ロックと本人再認可を行う。書籍とジャンル集合は1トランザクションで保存し、選択ジャンルの共有ロックと既存外部キーで整合性を守る。既知のISBN重複・ジャンル消失だけを通常入力エラーへ戻し、他の障害は隠さない。物理削除は既存CASCADEを利用する。完成済みBlade・DB定義・依存パッケージは変更していない。

Handlerは例外本文・SQL・トレースを記録せず、日時・ルート定義・例外種別を記録する。既定ログとstackをdailyへ揃え、既存の14日保持を利用する。実環境の.envによる上書き状況は未調査。

- PHP 8.5.12／Laravel 10.50.4／MySQL 8.4.13、DBはtesting限定。
- 初回実装では変更7PHPをPintで整形後、全148テスト・3,572アサーション成功（終了0、8.69秒）。既存103件に書籍45ケースを追加。後述の障害応答修正後は全157件・3,785アサーション、テスト補強後の最終結果は全172件・5,124アサーション。
- 元.envを含まない許可ファイルだけの隔離実コピーを利用。vendor・lang・public/buildも実コピーし、元ルートをマウントしない。公開.env.testingで起動し、子プロセスも同じ隔離内で実行。
- 実行形: `docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue6-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test`。
- 変更PHP7ファイルについて元と隔離のSHA256一致、親の差分レビュー、git diff --check成功。
- 途中の全件検証で認証3件が失敗した原因は隔離コピーのlang漏れ。langを含めた最終全件成功で解消。対象テストのDOM参照・属性順序・セッション検証手順の不備も修正済み。
- カバレッジは今回未測定。承認済みの単位6・7完了後と単位12の測定予定を維持する。

| テスト観点 | 主なテスト | 確認内容 |
|---|---|---|
| T-6-1〜3・9・13・18・19・21 | test_create_and_update_preserve_owner_and_related_data | 初期フォーム・登録・編集、作成者維持、ジャンル集合置換、関連保持、出版日 |
| T-6-4〜5 | test_authentication_target_and_owner_checks_precede_validation | 認証・不正ID・対象・本人の判定順と拒否後不変 |
| T-6-6〜12・14・16〜17 | test_invalid_inputs_return_to_internal_form_and_preserve_database、test_maximum_lengths_and_valid_dates_urls_are_accepted、test_duplicate_isbn_genres_and_clear_selection_are_preserved | POST/PUTの必須・型・文字数・ISBN・日付・URL・ジャンル、内部フォーム復帰・旧入力・全解除 |
| T-6-15 | test_no_genres_form_displays_registration_guidance | ジャンル0件の既定案内 |
| T-6-18・20 | test_delete_cascades_only_target_book_relations_and_returns_404_afterward | 全関連の物理削除、ジャンル本体・無関係な全保存値保持、削除後404 |
| T-6-22（模擬） | test_target_deleted_or_owner_changed_after_validation_is_not_saved、test_save_races_and_unexpected_failures_roll_back_all_changes | 検証後の削除・所有者変更、ISBN重複・ジャンル消失、想定外失敗の全体取消 |
| T-6-22（実並行） | test_independent_process_handles_concurrent_save（4ケース）、test_genre_shared_lock_blocks_concurrent_delete_until_sync_commits | 後勝ち・削除後404・ISBN競合・検証後ジャンル消失、共有ロックによる削除待機と保存後RESTRICT |
| 障害・ログ | test_unexpected_failure_logs_safe_metadata_and_returns_generic_500 | 日次14日設定、日時・経路・例外種別、機密非記録・デバッグ有効／無効時500の非露出 |

実並行5ケースは独立DB接続・独立PHPプロセスと同期を用いる。待機SQLをSHOW PROCESSLISTで観測し、固定sleepだけで順序を仮定しない。タイムアウトとfinallyで作成データ・プロセスを後片付けする。模擬競合と区別して検証した。

## レビュー指摘P2の修正と最終検証

2026-11-25、想定外障害の応答がAPP_DEBUG=falseに依存していた点を修正。HandlerのrenderExceptionResponseで想定内応答を維持し、障害時は元例外・previousを渡さない既定エラー画面、JSON要求には汎用messageのみを返す。app.debug自体は変更しない。SQL・トレース・認証情報の非露出はデバッグ設定によらず保証する。

- 変更2PHPをPintで整形後、障害応答修正後の全157テスト・3,785アサーション成功（終了0、9.13秒）。
- test_unexpected_failure_logs_safe_metadata_and_returns_generic_500をデバッグ有効／無効×DB例外／一般例外×HTML／JSONの8ケースへ拡張し、応答・ログ非露出とapp.debug不変を確認。
- test_expected_responses_keep_their_status_and_debug_settingで認証302／401、入力302／422、403・404・405・419の維持を確認。CSRFはPHPUnitの既定省略と区別し、HTTP経路でTokenMismatchExceptionを発生させて応答を確認。
- 実行形: `docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue6-review-fix:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test`。
- 元.envを含まない明示許可コピー、vendor・lang・public資産の実コピーと公開.env.testingを使用。変更2PHPの元・隔離SHA256一致、親の差分再確認、git diff --check成功。
- 正常CRUD・Bladeは変更しておらず、以下の画面UATは初回結果を維持する。今回の修正後にブラウザーUATは再実行していない。

## テスト内容レビュー後の補強と最終検証

2026-11-25、ユーザー承認によりテストの検出力を補強した。変更はBookManagementTestと対応するtest_case.mdの観点・本報告のみ。アプリ実装は変更していない。

- PUTの所有者変更後403は、所有者変更後・拒否操作前に取得した全業務表snapshotと応答直後を厳密比較する。
- DELETEはGateの初回本人判定成功後にDBの所有者だけを変更し、保存直前の再認可が拒否することを `[true, false]` と全保存値不変で確認する。
- POST／PUTの不正入力ごとに、仕様由来の固定期待エラーマップ、該当欄下DOMの文言、全旧入力・有効なジャンル選択・保存値不変を照合する。実装のmessages()を期待値に流用しない。
- 任意項目の個別／一括解除について、DBのNULL、編集空欄、詳細の未設定表示を確認する。空欄／省略による登録も各1ケースで維持する。
- 正常POST直後に全入力とジャンル集合を比較し、その後のPUTで登録時の漏れを隠さない。PUTは全項目を異なる値へ変更し、別書籍・関連・ユーザー・ジャンルの対象外保存値を厳密比較する。

隔離コピーだけに次の代表的な誤変更を1つずつ入れ、補強テストが失敗することを確認した。いずれも期待した失敗（終了1）であり、最終正常結果と区別する。元のアプリへ誤変更は反映していない。

| 誤変更 | 対応するテスト | 検出した失敗 |
|---|---|---|
| 所有者変更後、403前にauthorを書き換える | test_target_deleted_or_owner_changed_after_validation_is_not_saved | 拒否前後の全保存値不一致 |
| title必須の文言を誤変更する | test_invalid_inputs_return_to_internal_form_and_preserve_database（#0） | 期待エラーマップ不一致 |
| authorの旧入力を失う | 同上（#0） | 全旧入力不一致 |
| 空欄更新でも既存の任意値を維持する | test_clearing_existing_optional_values_stores_null_and_renders_empty | NULL期待と保存値の不一致（3ケース） |
| 登録時のdescription保存を省略する | test_create_and_update_preserve_owner_and_related_data | POST直後の全入力比較で不一致 |
| 更新時に別書籍のauthorも変更する | 同上 | 無関係な全保存値の不一致 |

実行は下記の通常コマンドへ `--filter=<上表のテスト名>` を付けて絞り込んだ。#0の2件は `--filter='test_invalid_inputs_return_to_internal_form_and_preserve_database.*#0$'` で実行した。各誤変更を復元し、変更テストのみPintで整形後、**復元済みの最終全172テスト・5,124アサーション成功**（終了0、9.83秒）。アプリ全PHPと変更テスト計36ファイルの元／隔離SHA256一致、親によるアプリ5主要ファイルの補強前後SHA256一致、git diff --check成功。

実行形: `docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue6-test-hardening:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test`。

元.envを含まない許可項目だけの実コピー・公開.env.testing、PHP 8.5コンテナとMySQL 8.4のtesting DBで検証した。親が終了後に業務7表各0件を確認。隔離コピーは記録確認後に削除した。アプリ・Bladeに変更はなく、画面UATは再実行せず以下の既存結果を維持する。

## Playwright MCPによる画面確認

隔離HTTP環境 http://127.0.0.1:8082 、PC 1440×1000／モバイル390×844。APP_ENV=testing、SESSION_DRIVER=file、APP_DEBUG=falseを一時HTTPコンテナの環境変数で指定した。公開.env.testingと既存ビルド済み資産を使用し、元.envは参照していない。

testing DBの業務7表0件から、今回専用の山田・鈴木2利用者と2ジャンルを準備した。初期Seederの検証ではない。書籍はすべて画面から登録し、PC・モバイルとも通常フォームで更新・削除した。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-6-01 | 満たす | 必須項目とジャンルを入力して登録すると対象詳細へ移動し、「書籍を登録しました。」と登録項目を表示。PCでは複数ジャンル・先頭0のISBN・未来日を確認。[PC登録](01_created-pc.png)・[モバイル登録](12_created-mobile.png) |
| AC-6-02 | 満たす | 編集初期値の出版日・選択を確認後、タイトル・説明・ジャンル集合を変更して更新。対象詳細と「書籍情報を更新しました。」を表示。[PC編集初期値](02_edit-initial-pc.png)・[PC更新](03_updated-pc.png)・[モバイル更新](14_updated-mobile.png) |
| AC-6-03 | 満たす | タイトル空で実POSTすると元フォームへ戻り「タイトルは必須です。」と他の入力・選択を保持。PCで一覧が1冊のまま、モバイルではタイトル補完後の登録が1冊だけとなることを画面から確認。[PC入力エラー](04_invalid-create-pc.png)・[モバイル入力エラー](11_invalid-create-mobile.png) |
| AC-6-04 | 満たす | 「本当に削除しますか？」を承認すると一覧へ戻り対象が消え、「書籍を削除しました。」と0件表示。保存した詳細URLは404。[PC削除後](09_deleted-pc.png)・[PC旧URL](10_deleted-url-pc.png)・[モバイル削除後](16_deleted-mobile.png)・[モバイル旧URL](17_deleted-url-mobile.png) |

### 補足確認

- 全ジャンル解除で実PUTしても既存選択が復活せず、説明等を保持して未選択エラーを表示した。[PC全解除](05_clear-genres-pc.png)・[モバイル全解除](13_clear-genres-mobile.png)。PCの編集キャンセル後は保存済み説明・文学ジャンルのままであることも確認。
- 別会員の鈴木では書籍の編集・削除操作が非表示、編集URLへのGETは403。[別会員表示](06_other-member-pc.png)・[編集拒否](07_forbidden-edit-pc.png)。他人のPUT/DELETE拒否とuser_id偽装は自動テストの結果であり、画面から直接送信していない。
- 削除確認を中止すると詳細が残り、その後の承認で削除できることをPC・モバイルで確認。[PC中止後](08_delete-cancelled-pc.png)・[モバイル中止後](15_delete-cancelled-mobile.png)。
- PCのタイトル欄はrequired=false、checkValidity()=true、validationMessageは空、フォームはnovalidate=true。欄下文言はブラウザー標準ではなくサーバーの実POST応答で確認。
- 初回はPlaywright MCPがブラウザー使用中でtabs/snapshotを取得できず中断。ユーザーのセッション終了後に隔離コンテキストで再開し、全ACを回収した。アプリ修正による解消とは扱わない。

## 残件と後片付け

Q-19の書籍文言はユーザー承認の暫定採用であり、クライアントによる正式確定は後日確認。実環境のログ設定上書きは未確認。レビュー・トグル・公開API等の後続単位は未完了のまま。

画面から登録した2冊は本人の削除操作で削除した。今回作成した利用者・ジャンルだけをfixtureの記録IDで削除し、業務7表すべての保存値が開始前と一致（各0件）することを確認した。作業用ブラウザータブを閉じ、既存タブは保持。一時HTTPコンテナを停止・削除し、隔離コピーと一時fixtureも削除した。既存サービス・ボリュームは維持した。完成済みBlade・DB定義・依存・既存の引き継ぎ変更は維持した。
