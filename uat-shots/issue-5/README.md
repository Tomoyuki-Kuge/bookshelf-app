# Issue #5 検証結果

実施日: 2026-11-25（日本時間）。対象: F-10〜14、SC-06〜09、AC-5-01〜04、T-5-1〜13。

## 実装と自動テスト

ジャンル7経路をGenreControllerと共通GenreRequestへ接続した。認証後の数字列ID確認、名前の前後空白除去・DBと同じ式による重複判定、入力保持、一覧への成功／失敗フラッシュ、紐付き削除拒否を実装。検証後の名前重複は既知の一意索引違反だけを入力エラーへ戻す。更新直前の対象確認とロックで削除済み対象を再生成しない。Blade・DB定義・依存パッケージは変更していない。

Q-19のジャンル名4文言はユーザー承認による暫定採用。クライアント確認による正式確定は未完了であり、暫定文言のテスト成功と区別する。

- PHP 8.5.12／Laravel 10.50.4／MySQL 8.4のtesting DB。
- 変更PHP4ファイルをPintで整形後、全102テスト・2,791アサーション成功（終了0、6.39秒）。既存80ケースにジャンル22ケースを追加。
- 元の`.env`を含まない許可ファイルだけの隔離コピーで実行。vendorも実コピーし、子プロセスから元の`.env`へアクセスできない構成。公開`.env.testing`を使用。
- 実行形: `docker run --rm --network bookshelf-app_sail -v /private/tmp/bookshelf-issue5-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test`。
- 変更PHP4ファイルの元・隔離コピーのSHA256一致、`git diff --check`成功。親が差分をレビューした。

| 観点 | GenreManagementTestの主なテスト | 結果 |
|---|---|---|
| T-5-1・6・12 | test_t_5_1_6_12_members_share_create_update_delete | 別会員の更新・削除、空／既存初期値、trim保存、成功フラッシュ |
| T-5-2 | test_t_5_2_authentication_precedes_identifier_and_target_validation、test_t_5_2_target_deleted_after_validation_is_not_recreated | ゲスト誘導、不正形式・不存在・削除済み404、入力不正より対象確認を優先、再生成なし |
| T-5-3〜5 | test_t_5_3_5_index_lists_all_genres_in_id_order_with_counts_and_links、test_t_5_4_5_detail_filters_before_paginating_and_renders_links | 一覧ID順と件数、0／1／10／11冊、除外書籍を混在させた全ページID・関連・導線 |
| T-5-7〜9・13 | test_t_5_7_8_9_13_invalid_names_return_to_internal_form_without_changing_data、test_t_5_8_9_length_width_and_case_rules_match_database | POST／PUTの境界値、大小同一・全半角区別、失敗後DOMの旧入力・欄下文言、保存値不変 |
| T-5-9 | test_t_5_9_duplicate_after_validation_returns_normal_field_error | イベントで検証後の重複を模擬し、実一意制約違反と通常の重複応答を確認 |
| T-5-10〜11 | test_t_5_10_linked_genre_cannot_be_deleted、test_t_5_11_restrict_handles_link_added_after_precheck | 保存項目・関連を維持。イベントで事前確認後の紐付け追加を模擬し、実RESTRICTと確定文言を確認 |
| T-5-11 | test_t_5_11_independent_connection_restricts_concurrent_delete | 独立接続・独立PHPプロセスで削除前確認後に紐付け挿入。削除SQLの実行中状態をSHOW PROCESSLISTで観測してcommitし、RESTRICT拒否と確定応答を確認。固定sleepで順序を仮定せず、timeoutとfinally後片付けあり |
| T-5-9・11 | test_t_5_9_11_unrelated_database_failures_are_rethrown | 別索引・別外部キー・接続障害を通常入力エラー／削除不可へ変換しない |

## レビュー指摘へのテスト補強（2026-11-25）

レビューで、従来の更新成功テストが未紐付きジャンルのみを使い、名前更新時の関連保持を担保していないことを確認した。data.mdのF-13のCRUDに従い、test_case.mdのT-5-6を補足してから、`test_t_5_6_renaming_linked_genre_preserves_books_and_all_associations`を追加した。アプリの実装・Bladeは変更していない。

- Webで作成したジャンルに日時の異なる3冊を紐付け、別会員として名前を更新する。同じ書籍の別ジャンル、別書籍の紐付け、未紐付け書籍も用意し、更新対象・対象外を区別した。
- ジャンルのname・updated_at以外の全項目、書籍・中間行・他ジャンル・無関係な保存値を更新前後で厳密比較する。更新前後の一覧の新旧名・書籍数と詳細の正確な書籍ID配列・total、成功フラッシュも確認する。
- 元の`.env`を含まない隔離実コピー `/private/tmp/bookshelf-issue5-regression`、PHP 8.5.12／Laravel 10.50.4／MySQL 8.4のtesting DBを使用。public/buildも複製し、元ルートをマウントしない構成で実行した。
- 対象テストは1件・38アサーション成功。変更したテスト1PHPのみPintで整形し元へ反映後、誤変更を復元した最終全テストは**103件・2,829アサーション成功**（終了0、6.12秒）。初回の102件の結果を更新する最終結果であり、画面UATの再実施ではない。
- 隔離コピーのControllerだけに更新時の`$current->books()->detach()`を追加すると、新テストが「book_genreの全保存項目を維持する」で失敗し、3中間行の消失を検出した（期待した失敗、終了1）。この誤変更は元の作業ツリーへ反映していない。
- 実行形は前述のdocker runと同じ構成で、名前・マウント先を`bookshelf-issue5-regression`へ変更。対象確認は`artisan test --filter=test_t_5_6_renaming_linked_genre`、整形は`vendor/bin/pint tests/Feature/GenreManagementTest.php`、最終確認は`artisan test`。
- 親の差分レビュー、元とコピーのテスト・実装PHPのSHA256一致、`git diff --check`成功。DB業務7表は各0件、隔離コピーは削除済み。

## 画面UAT再実施（AC-5-01〜04確認済み）

実施日: 2026-11-25（日本時間）。Playwright MCPの新しい隔離ブラウザーで再実施した。隔離HTTP環境 `http://127.0.0.1:8082`、PC 1440×1000／モバイル390×844。元の`.env`を含まない許可ファイルだけのコピーと公開`.env.testing`を使い、既存ビルド済みアセットを使用。今回はアプリのコード・設定を変更せず、自動テストも再実行していない。

testing DBの業務7表0件から、山田・鈴木の2利用者、11冊付きジャンルと空ジャンルを今回専用に作成した。書籍は01〜11の日別登録日時・画像なし。既存データは変更していない。指定Seederの初回確認とは区別する。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-5-01 | 満たす | 山田でログインし、一覧の名前をクリックして詳細へ移動。11〜02の10冊、ページリンクで01の1冊を表示し、カードをクリックして書籍詳細へ移動した。モバイルでも同じ遷移を実施。[PC詳細](07_retry-detail-page1-pc.png)・[PC2ページ目](08_retry-detail-page2-pc.png)・[移動先の書籍詳細](09_retry-book-detail-pc.png)・[モバイル詳細](19_retry-detail-mobile.png)・[モバイル2ページ目](20_retry-page2-mobile.png)。 |
| AC-5-02 | 満たす | 登録・更新とも一覧へ戻り、名前と「ジャンルを登録しました。」「ジャンルを更新しました。」を確認。前後空白除去・編集初期値も確認。PCとモバイルで実操作。[PC登録](10_retry-created-pc.png)・[PC更新](11_retry-updated-pc.png)・[モバイル登録](21_retry-created-mobile.png)・[モバイル更新](22_retry-updated-mobile.png)。 |
| AC-5-03 | 満たす | 未紐付きジャンルの「本当に削除しますか？」を承認し、一覧の対象行消失と「ジャンルを削除しました。」を確認。PCは2行→1行、モバイルでも2行→1行。[PC削除承認後](15_retry-delete-confirmed-pc.png)・[モバイル削除後](24_retry-deleted-mobile.png)。 |
| AC-5-04 | 満たす | 11冊付きジャンルの確認を承認すると、対象と11冊表示が残り、確定した削除不可文言を表示。PC・モバイルで確認。[PC拒否](13_retry-linked-refused-pc.png)・[モバイル拒否](25_retry-linked-refused-mobile.png)。実並行RESTRICTは前述の自動テスト結果であり、この画面確認とは区別する。 |
| AC-3-04（初回の後続確認） | 満たす（初回結果を維持） | 前回、指定Seederの利用者でログインし、指定10ジャンルの重複なし表示を追加データ投入前に確認済み。[指定10ジャンル](01_seeded-genres-pc.png)。今回はSeederを再実行せず専用Factoryデータで確認した。 |

### 補足確認

- 別の隔離ブラウザーで鈴木としてログインし、山田が作成したジャンルの名前を更新・削除できた。[別会員の更新](23_retry-other-member-mobile.png)。ジャンルを本人限定にしない設計と一致する。
- 登録・編集とも入力後にキャンセルすると一覧へ戻り、登録・更新されないことを確認。削除確認の中止では行数2のまま対象が残り、その後の明示承認で行数1となった。[削除中止後](14_retry-delete-cancelled-pc.png)。
- 実際のPOST後に、必須・重複・256文字超過の暫定文言がname欄下へ出ることを確認。重複入力はtrim後の名前、超過入力は256文字を保持した。[必須](16_retry-required-pc.png)・[重複と入力保持](17_retry-duplicate-pc.png)・[超過](18_retry-length-pc.png)。失敗時に行数は増えず、キャンセル後の一覧は1行。文字列以外の型エラーは通常UIでは入力できないため、前述の自動テストで確認する。
- 必須エラー画面のDOMはrequired=false、checkValidity()=true、validationMessageは空、form.noValidate=true。ブラウザー標準検証に代わりサーバーの日本語エラーを表示している。Q-19の正式文言はクライアント確認待ちであり、今回の表示確認は暫定採用に対する結果。
- モバイルメニューの開閉・ジャンル管理への移動を確認。後片付け途中で書籍・ジャンルのみ0件に戻し、「ジャンルが登録されていません。」を確認した。[空一覧](26_retry-empty-index-mobile.png)。
- **表示上の観測事項:** 幅390pxで今回の長い確認用ジャンル名を表示すると、一覧の削除文字が右端で一部切れる。削除操作は完了できたが、表示上の制約として残る。[該当画面](25_retry-linked-refused-mobile.png)。完成済みBladeの変更禁止に従い、修正していない。

### 初回からの差分・ツール操作の記録

初回はリンク・登録ボタンの操作が反映されずAC-5-01〜04が未確認だった。今回、同じPlaywright MCPの新しい隔離コンテキストでは通常のクリック・送信が動作し、未確認範囲を回収した。前回原因は未特定のままであり、アプリ修正による解消とは扱わない。初回の[未完了フォーム](02_form-interaction-pending-pc.png)・[詳細1ページ](03_detail-page1-pc.png)・[詳細2ページ](04_detail-page2-pc.png)・[モバイル詳細](05_detail-mobile.png)・[空詳細](06_empty-detail-mobile.png)も保持する。

再実施中、ナビのhrefセレクターが非表示のモバイルリンクを含む2要素に一致したため、スナップショットの一意な参照に修正した。最初の削除確認では別呼出しのbrowser_handle_dialogより先に確認が処理され、中止を確認できなかった。その回の[削除結果](12_retry-deleted-pc.png)を中止の証拠には使わず、以降は同じPlaywright MCP内でクリック前にdialog.dismiss／acceptのハンドラーを登録し、中止・明示承認の両方を再確認した。DOMの書き換えやフォームの強制送信、直接の削除HTTP要求は行っていない。

## 後片付け・残件

今回作成したIDと開始前のID集合を記録し、作成分だけを削除した。業務7表は各0件、開始前のID集合と一致。HTTPコンテナを停止・削除し、作業用ブラウザータブ、隔離コピー、一時fixture、Playwright生成ログを片付けた。証拠画像は初回6枚＋今回20枚の計26枚を保持する。既存サービス・ボリューム、既存のコード変更、`docs/CODEX_HANDOFF.md`の既存変更は維持。今回の変更は本報告と新しい証拠画像のみ。

画面AC-5-01〜04の未確認は解消。残件はQ-19の正式文言確認と上記のモバイル表示制約。横断ログ方針の日次・14日保持は既存dailyチャンネルに対して既定stackがsingleを参照しており、今回も運用照合・設定変更を行っていない。カバレッジは承認済みの単位6・7完了後と単位12の予定を維持し、今回は未測定。
