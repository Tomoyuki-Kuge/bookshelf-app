# Issue #4 検証結果

実施日: 2026-11-23（日本時間）。対象: F-04〜05、SC-01〜02、AC-4-01〜04、T-4-1〜12の単位4範囲。

## 実装と自動テスト

BookController::showと公開詳細ルートを接続し、書籍のジャンル・全レビュー・投稿者・いいね、認証利用者のお気に入り・いいね状態をまとめて取得する。既存一覧の取得処理・Blade・Policy・DB定義は維持した。詳細URLのbookは数字列に制限する。数字IDの不存在・削除済みは404、不正形式のGETは同パスのPUT／DELETE経路との照合によるLaravel標準405となり、実在書籍を表示・変更しない。

- PHP 8.5／Laravel 10.50.4／MySQL 8.4のtesting DBを使用。
- 初回実装では変更したPHP3ファイルをPintで整形後、全80テスト・2,235アサーション合格（終了0、9.27秒）。レビュー補強後の最終結果は末尾の「レビュー指摘へのテスト補強」を参照。
- 新規PublicBookBrowsingTestはデータセット込み19ケース。既存61ケースも通過。
- 親が差分をレビューし、検証コピーと作業ツリーの3 PHPのSHA256一致、git diff --checkの終了0を確認。
- 元の.envを含めず、許可ディレクトリ・列挙ファイルだけを一時コピーしてコンテナへマウントした。vendorは実コピー、公開設定は.env.testingを使用。元ルートを丸ごとマウントせず、子プロセスからも元の.envを参照しない。
- 初回の検証コピーでlang/が不足して認証文言3ケースが失敗したため、公開翻訳ファイルを複製して解消した。最終80ケースはその修正後の結果。フック・権限・環境設定の変更はない。

| 観点 | 主なテスト名（PublicBookBrowsingTest） | 結果 |
|---|---|---|
| T-4-1・3・11 | test_t_4_1_3_11_guest_index_paginates_exact_books | 0／1／10／11冊、全ページのID・件数・リンク・空表示を確認 |
| T-4-2・4 | test_t_4_2_index_order_is_unchanged_by_updates、test_t_4_4_index_renders_book_information_and_image_fallback | 日時・同日時ID順、更新後の順序維持、画像・alt・全ジャンルを確認 |
| T-4-5 | test_t_4_5_index_rounds_average_and_omits_unrated_books | 平均小数1桁・星の丸め境界・未評価省略を確認 |
| T-4-6〜8・12 | test_t_4_6_12_detail_shows_information_and_string_publication_date、test_t_4_7_detail_shows_all_reviews_in_date_order_with_likes、test_t_4_8_detail_handles_empty_optional_fields_and_reviews | 出版日文字列、全12レビュー、別書籍の混入防止、空表示を確認 |
| T-4-9〜10 | test_t_4_9_guest_detail_has_login_links_without_operation_forms、test_t_4_10_authenticated_detail_matches_ownership_and_user_state | ゲスト・本人・投稿者・無関係な利用者、所有権といいね状態を独立に確認 |
| T-4-11 | test_t_4_11_existing_blade_displays_session_feedback_and_return_link | 人工準備したセッションのフラッシュ・旧入力・欄下エラー・戻り先を確認 |
| T-4-1 | test_t_4_1_missing_and_deleted_books_return_404_for_guests_and_members、test_t_4_1_malformed_book_identifiers_do_not_resolve_existing_books | 不存在・削除済み404、不正形式の対象解決防止、create先行、GET /books非追加を確認 |

初回実装の実行コマンド（コピーは検証終了後に削除）:

```sh
docker run --rm --network bookshelf-app_sail --name bookshelf-issue4-check -v /private/tmp/bookshelf-issue4-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app vendor/bin/pint app/Http/Controllers/BookController.php routes/web.php tests/Feature/PublicBookBrowsingTest.php
docker run --rm --network bookshelf-app_sail --name bookshelf-issue4-check -v /private/tmp/bookshelf-issue4-verification:/var/www/html -w /var/www/html --entrypoint php -e APP_ENV=testing sail-8.5/bookshelf-app artisan test
git diff --check
```

## 手動画面確認

Playwright MCPで一時環境 http://127.0.0.1:8082 を操作した。PCは1440×1000、モバイルは390×844。既存のビルド済みCSS／JavaScriptを使用し、一時コピーにpublic/hotを含めていない。

DBの業務7表が0件であることを確認して空一覧を撮影後、専用利用者2名・ジャンル2件・書籍11冊・レビュー12件・お気に入り1件・いいね2件を作成した。初回Seederの件数検証ではなく、Factoryによる独立した画面確認用データ。書籍11〜01の登録日時は日ごとに降順、書籍11だけに評価4・5各6件、他10冊は未評価。書籍作成者を山田、レビューを山田・鈴木の交互とし、山田だけがお気に入り登録済み、最新の鈴木レビューには両名がいいね済みとした。

| 受け入れ条件 | 判定 | 画面で観測した根拠・証拠 |
|---|---|---|
| AC-4-01 | 満たす | ゲストのPC一覧で書籍11〜02の10冊、ページリンクを押すと書籍01の1冊を表示。カードを押して対象詳細へ移動した。モバイルでも「Next »」で10冊から1冊へ移動。[PC1ページ目](02_index-page1-pc.png)・[PC2ページ目](03_index-page2-pc.png)・[モバイル1ページ目](10_index-page1-mobile.png)・[モバイル2ページ目](11_index-page2-mobile.png)。 |
| AC-4-02 | 満たす | タイトル・著者・ISBN・出版日2026-01-02・2ジャンル・説明・画像を表示。レビュー12〜01を日付降順で全件表示し、投稿者名・星・コメント・投稿日Y/m/d・いいね2／0件を確認。ゲストには投稿・本人操作のフォームなし。モバイルでも全12件、画像読込成功、横はみ出しなし。[PC詳細](04_detail-guest-pc.png)・[モバイル詳細](09_detail-mobile.png)。 |
| AC-4-03 | 満たす | レビューのある書籍11だけに★★★★★と(4.5)、隣の未評価書籍10を含む他のカードに星・平均なし。DOMでも各カードの有無を確認。[PC比較](02_index-page1-pc.png)・[モバイル比較](10_index-page1-mobile.png)。 |
| AC-4-04 | 満たす | 業務7表0件の一覧に「書籍が登録されていません。」。レビュー0件の書籍10に「まだレビューはありません。」と「画像なし」、説明は非表示。PC・モバイルで確認。[PC空一覧](01_empty-index-pc.png)・[モバイル空一覧](14_empty-index-mobile.png)・[PC空詳細](05_empty-detail-pc.png)・[モバイル空詳細](12_empty-detail-mobile.png)。 |

## 補足確認と未確認範囲

- 山田では書籍の編集・削除、お気に入り登録済み、他者レビューへの「いいね済み(2)」、自分のレビューだけの編集・削除を表示。鈴木では書籍の編集・削除なし、お気に入り未登録、自分のレビューだけの編集・削除を表示。評価初期値とコメントは空。[本人](07_detail-owner-pc.png)・[他者](08_detail-other-pc.png)。ボタン表示の根拠であり、未実装の保存操作を実行・完成確認したものではない。
- モバイルメニュー開閉・ログアウト、詳細の「一覧に戻る」を実操作した。
- 正式な認証失敗文言「ログイン情報が登録されていません」（句点なし）がemail欄下に表示され、メール保持・パスワード空となることを実際のログイン失敗後に確認。[正式文言](06_auth-message-pc.png)。認証7文言すべての再UATではない。
- 後片付けで物理削除した書籍の元URLを開き、既定404を確認。[削除済み詳細](13_deleted-detail-mobile.png)。Webの削除成功操作の確認は単位6で行う。
- T-4-12の編集フォームの出版日表示、投稿・更新・削除・いいね／お気に入り保存後の遷移は後続単位。T-4-11のエラー・旧入力は自動テストで人工セッションを準備した表示確認であり、業務入力エラーの実POSTは未確認。
- AC-1-01相当の複数ジャンル・投稿者・評価・コメント・いいね数は本確認で表示を観測した。AC-1-02全体（お気に入り一覧）とAC-3-02（指定Seeder値）の回収は今回の専用データ確認で完了扱いにしない。
- カバレッジは承認済みの単位6・7完了後および単位12の予定を維持し、今回は未測定。
- UAT起動直後に一時PHPサーバーのルーター指定による静的アセットのMIMEエラーを検出したため、Laravel付属server.phpを使う起動方法へ訂正した。上記証拠は訂正後に撮影。通常画面で描画エラーはなく、404へのアクセス時のコンソール通知は期待するHTTP404に対応する。アプリ・Blade・ビルド済みアセットはこの環境調整で変更していない。

## 後片付けと変更範囲

今回の作成IDとDBの利用者・ジャンル・書籍・レビューのID集合が一致することを確認してから、そのIDだけを削除した。CASCADE後のusers・books・genres・book_genre・reviews・favorites・review_likesは各0件。一時HTTPコンテナと検証用の実行コンテナは停止・削除済み。既存のLaravel・MySQL・phpMyAdminサービスとボリュームは維持した。

検証コピー・UAT fixtureの一時ディレクトリとPlaywrightの生成ログを削除し、証拠画像14枚と本報告だけをuat-shots/issue-4へ保存した。元の.env・完成済みBlade・docs/reference・既存のdocs/CODEX_HANDOFF.mdは変更していない。PRの変更対象は実装・テストの3 PHP、設計補足、本報告と証拠画像であり、既存の引き継ぎファイルの変更は含めない。

## レビュー指摘へのテスト補強（2026-11-23）

ユーザー承認によりPublicBookBrowsingTest.phpのT-4-7だけを補強した。仕様・アプリ実装・ルート・Blade・既存の引き継ぎファイルは今回変更していない。

- 作成順を日付4・11・2・8・12・5・1・10・6・3・9・7日とし、日時降順とID昇順／降順のどちらも一致しないデータへ変更した。準備時の定義から日時降順の期待ID・コメント列を組み立て、DBの同じ取得処理を期待値へ流用しない。
- 3投稿者と評価1〜5を混在させ、各DOMレビュー行の投稿者名・星・コメント・投稿日・いいね数を厳密比較する。12件の全件表示・別書籍レビュー除外・いいね0件／2件の確認も維持した。
- 元の.envを含まない新規隔離コピーとPHP 8.5コンテナ・MySQL 8.4のtesting DBを使用。対象テスト1件・73アサーション成功、変更テスト1ファイルのPint成功後、最終全80テスト・2,254アサーション成功（終了0、9.25秒）。git diff --checkも成功。
- 下表の誤実装は隔離コピーだけへ独立に入れた。変更前のテストでは各誤実装とも全80件が通過していたが、補強後は対象テストで検出できた。

| 隔離コピーだけの誤変更 | 補強後の結果 | 検出箇所 |
|---|---|---|
| レビュー取得をorderBy('id')へ変更 | 失敗（終了1） | 期待する日時降順のID列と一致しない |
| 全レビューのuser関連を先頭レビューの投稿者へ置換 | 失敗（終了1） | 各レビュー行の期待投稿者名と一致しない |

各誤変更後にコピーを復元し、復元後の対象1テスト・73アサーション成功も確認した。最終のtest・app・routesは元ファイルとコピーのSHA256一致を確認。testing DBの業務7表は各0件、実行コンテナと自分で作成した検証コピーは後片付け済み。コンテナ内の整形はvendor/bin/pint tests/Feature/PublicBookBrowsingTest.php、全テストはartisan testで実施した。

画面挙動に変更はないため、画面UATは再実行せず上記14枚の証拠と確認結果を維持する。編集フォームの出版日と保存操作・業務入力検証との判定順序は、引き続き後続単位で確認する。
