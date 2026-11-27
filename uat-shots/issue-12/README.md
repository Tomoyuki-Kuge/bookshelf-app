# Issue #12 基本要件の横断検証結果

実行日: 2026-11-27（日本時間） / 補強結果の照合・記録更新日: 2026-11-28 / ブランチ: `issue-12-final-verification` / 開始コミット: `329b0df`

対象はF-28と基本機能の接続、SC-01〜13、AP01〜05、AC-12-01〜04、T-12-1〜11。仕様の正本はdocs/designであり、Q-19の暫定採用と正式確定待ちは区別する。自動検証は成功し、AC-12-01〜03を満たす。AC-12-04は既知のモバイル表示制約が残るため未達とする。

## 変更と自動検証

未使用のPendingFeatureControllerを削除し、BasicWorkflowTest・CommonHttpFailureTest・RealHttpCsrfTestを追加した。業務処理・完成済みBlade・依存パッケージ・DB定義・Seederは変更していない。実装前にtest_case.mdへ検証方法を補足し、Git管理対象外の実装担当設定は古いPHP 8.2表記を削除して環境設計を参照する形に修正した。

テスト内容レビュー後、RealHttpCsrfTestへログインのCSRF拒否を追加し、既存GenreManagementTestの紐付き削除拒否・RESTRICT拒否を業務7表の全保存値比較へ補強した。以下は補強後の最終全体実行の結果である。

| 確認 | 結果 |
|---|---|
| 最終全テスト | **362件・9,727アサーション成功**。失敗・エラー・スキップ0。実行ログの所要時間20.53秒、JUnitのテスト時間合計20.44秒 |
| BasicWorkflowTest | 2件・244アサーション。会員登録からWeb/API操作、登録者変更、集計・関連削除、拒否時7表不変 |
| CommonHttpFailureTest | 10件・258アサーション。400・405・500、デバッグ両設定の安全な実ログ・応答、daily切替・保持 |
| RealHttpCsrfTest | 1件・365アサーション。ログインを含む14入口の実CSRF拒否、直後の入力非保持・未認証維持、正常更新・削除・ログアウト、再認証・再入力 |
| GenreManagementTest | 23件・576アサーション。紐付き削除の事前拒否とRESTRICT拒否に、対象・無関係の関連実データと業務7表の全保存値比較を追加 |
| カバレッジ | **97.9%（464/474対象行）**。phpunit.xmlのapp配下46ファイル。独立HTTP・競合子プロセスのカバレッジは未統合 |
| 書式・差分 | 先行の新規PHP3ファイルはPint成功。補強対象2ファイルは最終差分でPint --test成功。git diff --check成功 |
| 原本・コピー | 起動用2PHPを含むPHP106ファイル一致。app・Blade・参照控え・既存引き継ぎの保護対象113ファイルの保持も親が照合 |
| DB | 自動テスト・Seeder確認・横断UATそれぞれの開始終了で業務7表の全保存値一致（各0件） |

元の.envを含まない許可項目の隔離実コピー、PHP 8.5・Laravel 10・MySQL 8.4のtesting DBで実行した。vendorも実コピーし、元プロジェクトルートをマウントしていない。既存pcovを使用し、追加パッケージはない。全体検証のコマンド形式は次のとおり。補強後の成果物名はfinal-clover.xml・final-junit.xmlとした。

```sh
php -d pcov.enabled=1 -d pcov.directory=/var/www/html/app artisan test --coverage --min=60 --coverage-clover=final-clover.xml --log-junit=final-junit.xml
```

最初の全体実行は隔離コピーのlang/不足により既存認証3件が失敗した。翻訳を複製して解消し、対象外データの保持・本人の登録状態表示・各419直後のセッション非保持・正常PUT/DELETE/logoutを補強して9,611アサーション成功を確認した。その後、ログインCSRFとジャンル削除拒否のテスト内容レビューを反映した最終結果が上表の9,727アサーションである。途中の9,514・9,611アサーションを最新結果へ流用していない。最終出力に非推奨通知はなく、親もJUnit・Clover・前後の保存値・コピー一致を照合した。

未到達10行はConsole Kernel 16、Handler 40・91、API BookController 109・112、Web BookController 84・112、TrustHosts 16〜18。行番号は今回の開始コミットからの差分時点のもの。過去の97.1%を現在の測定値に流用していない。

CSRFはAPP_ENV=testingの通常HTTP・専用Cookie・隔離fileセッションを使う。会員登録・ログインの2入口と保護書き込み12入口（計14入口）に不正・欠落トークンを送り、419・業務7表不変を確認した。ログインは未認証の別Cookieで正しい資格情報を送り、拒否後も保護画面がログインへ誘導されることと、正常トークンではログインできることを確認した。各419の直後、次のHTTPでフラッシュが消費される前にセッションの_old_inputと送信値の非保持を照合し、新フォームの再入力・セッション失効後の再ログインも確認した。既存の419例外描画だけのテストとは区別する。

### テスト補強と誤実装の検出

紐付きジャンル削除の拒否テストは、対象・無関係の書籍、レビュー、お気に入り、いいねを用意し、業務7表を主キー順に再取得して日時・関連IDを含む全保存値を比較する。RESTRICTの模擬競合では、HTTP直前の保存値にイベントで追加したbook_genreの1行だけを期待値として加え、拒否処理の先行する誤変更を比較基準へ混ぜない。

元の.envを含まない隔離コピーだけに、次の誤実装を個別に注入した。いずれもPHPUnitのエラーではなくアサーション失敗1件で検出した。各変更を復元し、元とコピーのPHP106ファイル一致を確認した最終差分が、上表の全362件成功の対象である。

| 注入した誤実装 | 検出したテスト | 検出結果 |
|---|---|---|
| ログインだけCSRF検証から除外 | RealHttpCsrfTest | 期待419に対する実際の302を検出 |
| 紐付きジャンルの事前削除拒否でお気に入りを誤削除 | GenreManagementTest::test_t_5_10_linked_genre_cannot_be_deleted | 業務7表比較でfavoritesの行消失を検出 |
| RESTRICTによる削除拒否でお気に入りを誤削除 | GenreManagementTest::test_t_5_11_restrict_handles_link_added_after_precheck | 業務7表比較でfavoritesの行消失を検出 |

2026-11-28の差分レビューで、保存された実行成果物と最終ソースの一致を再確認した。業務処理・Bladeの変更がないため、テスト補強に伴う画面UATの再実施はしていない。既存65枚の画面証拠と、今回の自動テスト・誤実装検出を区別する。

ログはdailyへの実出力で日時・経路・例外種別と機密非記録を照合した。日次切替は実ハンドラーの前日末状態を模擬し、保持は通常リクエスト単位の新しいdailyハンドラーに16日分（当日＋過去15日）の確認用ファイルを渡して最新14日分だけが残ることを照合した。長寿命ハンドラーの境界では新規ファイル作成前の削除により一時15ファイルとなる観測があり、上記保持確認の条件と区別する。実環境の連続稼働や.envによる上書き状況を確認したものではない。

## 既存実装・テストの対応照合

下表は既存テストの読み取りによる対応であり、全観点の網羅や今回の実行成功を示すものではない。横断シナリオ・実CSRF・日次ログの不足を今回の追加検証で補う。

| 対象 | 主な既存テスト | 主な確認内容 |
|---|---|---|
| F-01〜03・SC-12〜13 | AuthenticationTest | 登録、自動ログイン、入力・正式文言、元画面復帰、セッション更新・破棄 |
| F-04〜05・SC-01〜02 | PublicBookBrowsingTest | 10件ページ分割、日時順、平均、全レビュー、利用者ごとの操作表示 |
| F-06〜09・SC-03〜04 | BookManagementTest | 本人CRUD、入力とジャンル集合、CASCADE、保存取消、競合 |
| F-10〜14・SC-06〜09 | GenreManagementTest | 全会員共通CRUD、名前・書籍数、詳細ページ分割、RESTRICT |
| F-15〜17・SC-02・05 | ReviewManagementTest | 複数投稿、入力境界、本人認可、入力保持、関連削除、競合 |
| F-18・SC-02 | ReviewLikeTest | 自己いいね、追加・解除、件数、利用者独立性、直列化 |
| F-19〜20・SC-02・10 | FavoriteTest | 自分の登録分、追加日時順、操作元復帰、ページ補正、直列化 |
| F-21・SC-11 | RankingTest・RankingOrderTest | 未丸め平均・件数・ID順、上限、再集計、空表示 |
| F-22〜23・AP01〜02 | PublicBooksApiTest | 検索・絞り込み・ページ境界、公開JSON型、全レビュー、日時・平均 |
| F-24〜26・AP03〜05 | PublicBooksApiTest・PublicBooksApiConcurrencyTest | 認証不要CRUD、入力、任意値、登録者変更とWeb権限、削除・競合 |
| F-27 | SeederTest | 固定値・件数・責務、再実行、依存不足、全体・個別取消 |
| F-28・共通基盤 | CommonDataFoundationTest・UserModelTest・上記各群 | 物理定義・関連・一意制約・外部キー・削除・日時・モデル属性 |
| 全保護経路 | AuthenticationTest・各機能テスト | ログイン誘導、本人操作の各入口、拒否後の全保存値保持、削除済み404 |

登録からの一連操作、Web作成からAPI更新・削除までの連携、同一操作後の複数表示先の集計照合は今回の補強対象。既存の419例外描画テストは実CSRF拒否の証拠に代用せず、既存のsingleログへの安全な実出力もdailyの日次切替・保持の証拠に代用しない。

## 未チェックだったACと過去の画面証拠の照合

単位1〜11の未チェック19件を既存報告・画像と照合した。18件は過去の実施証拠が揃い、AC-3-02は指定Seeder書籍の詳細の新規確認が必要だった。下表は今回の画面再実施ではなく、既存証拠の対応確認である。PC・390pxの確認範囲を今回の全13画面確認へ広げない。

| AC | 過去の証拠で確認できた範囲 | 主な証拠 |
|---|---|---|
| AC-1-01 | 複数ジャンルと全レビューの必要項目。PC・390px | [Issue #4報告](../issue-4/README.md)、[PC詳細](../issue-4/04_detail-guest-pc.png)・[390px詳細](../issue-4/09_detail-mobile.png) |
| AC-2-01 | 登録・自動ログイン・利用者名付き一覧。PC | [Issue #2報告](../issue-2/README.md)、[登録後](../issue-2/01_registered-index.png) |
| AC-2-02 | 元の保護画面への復帰はPC、元画面なしの一覧遷移は390px | [復帰先](../issue-2/02_intended-create.png)・[既定遷移](../issue-2/07_default-index-mobile.png) |
| AC-2-03 | 現行正式文言・email欄下・パスワード空。PC。Issue #2の旧句点付き画像は採用しない | [Issue #4報告](../issue-4/README.md)、[正式文言](../issue-4/06_auth-message-pc.png) |
| AC-2-04 | PC・390pxのログアウト実操作報告。遷移後の画像はPC | [ログアウト後](../issue-2/04_logout-login.png)・[再認証要求](../issue-2/05_protected-login.png)・[モバイルナビ](../issue-2/06_mobile-navigation.png) |
| AC-3-01 | 指定11冊の10＋1冊表示。PC・390px | [Issue #3報告](../issue-3/README.md)、[PC1ページ](../issue-3/01_index-page1-pc.png)・[PC2ページ](../issue-3/02_index-page2-pc.png)・[390px1ページ](../issue-3/04_index-page1-mobile.png) |
| AC-3-02 | 既存証拠は不足。Issue #3は後続画面待ち、Issue #4の専用Factoryデータは指定Seederの代用にしない | 今回の新規確認対象 |
| AC-3-04 | 初期利用者で指定10ジャンルを重複なく表示。PC | [Issue #5報告](../issue-5/README.md)、[指定ジャンル](../issue-5/01_seeded-genres-pc.png) |
| AC-4-01 | ゲストはPCで明示。10＋1冊・遷移は両幅、390pxの認証状態は画像だけでは判別しない | [Issue #4報告](../issue-4/README.md)、[1ページ](../issue-4/02_index-page1-pc.png)・[2ページ](../issue-4/03_index-page2-pc.png) |
| AC-4-02 | 書籍情報と全12レビュー・日時降順・必要項目。両幅 | [PC詳細](../issue-4/04_detail-guest-pc.png)・[390px詳細](../issue-4/09_detail-mobile.png) |
| AC-4-03 | 評価ありだけ星・平均を表示。両幅 | [PC一覧](../issue-4/02_index-page1-pc.png)・[390px一覧](../issue-4/10_index-page1-mobile.png) |
| AC-4-04 | 書籍0件・レビュー0件の指定文言。両幅 | [Issue #4報告](../issue-4/README.md)、[空一覧PC](../issue-4/01_empty-index-pc.png) |
| AC-5-01 | 一覧からジャンル詳細・書籍詳細への実操作。両幅 | [Issue #5再実施報告](../issue-5/README.md)、[ジャンル詳細](../issue-5/07_retry-detail-page1-pc.png)・[書籍詳細](../issue-5/09_retry-book-detail-pc.png) |
| AC-5-02 | 登録・更新後の一覧・名前・成功文言。両幅 | [登録](../issue-5/10_retry-created-pc.png)・[更新](../issue-5/11_retry-updated-pc.png) |
| AC-5-03 | 確認承認・対象消失・削除成功文言。両幅 | [PC削除](../issue-5/15_retry-delete-confirmed-pc.png)・[390px削除](../issue-5/24_retry-deleted-mobile.png) |
| AC-5-04 | 確認承認後も対象・書籍数が残り、指定拒否文言。両幅 | [PC拒否](../issue-5/13_retry-linked-refused-pc.png)・[390px拒否](../issue-5/25_retry-linked-refused-mobile.png) |
| AC-8-01 | 他者レビューのいいね0→1、同詳細へ復帰。PC | [Issue #8報告](../issue-8/README.md)、[追加](../issue-8/02_like-added.png) |
| AC-8-02 | 同じボタンで1→0、同詳細へ復帰。PC | [解除](../issue-8/03_like-removed.png) |
| AC-8-03 | 自己いいね・成功フラッシュなしはPC、390pxでは状態表示・解除も確認 | [自己いいね](../issue-8/04_self-like.png)・[390px状態](../issue-8/05_self-like-mobile.png) |

Issue #5の初回未完了画像と再実施結果は混同しない。既知の390pxの削除文字切れは機能ACの成功から解消済みとは判断しない。

## 今回のPlaywright MCP画面確認

隔離HTTP `http://127.0.0.1:8122/`、APP_ENV=testing・APP_DEBUG=true、専用fileセッションで実施した。PCのビューポート1440×1000、モバイル390×844を使用し、利用者A・B・ゲストは専用の別ブラウザーコンテキストに分けた。CSS・JavaScriptは既存public/buildを使用し、隔離コピーのpublic/hotは除外した。各コンテキストのコンソールエラーは0件。API書き込み・サーバー側の拒否は自動テストの証拠として区別する。

| AC | 判定 | 観測結果・証拠 |
|---|---|---|
| AC-12-01 | 満たす | 新規Aを会員登録して自動ログイン。ジャンル2件・書籍・レビューを登録し、お気に入りと自己いいねを追加。一覧から詳細へ戻って作成内容・2ジャンル・本人の登録状態を確認。[登録後](19_registered-index-pc.png)・[一覧](25_workflow-index-pc.png)・[本人詳細](26_workflow-owner-detail-pc.png) |
| AC-12-02 | 満たす | 別の新規Bが同じ書籍へレビュー投稿・いいね・お気に入りを追加。Aのいいねが1→2、Bの状態がいいね済みとなり、自分のレビューだけ編集・削除可能。書籍とAのレビューの操作は非表示。[投稿前](52_other-before-review-mobile.png)・[投稿後](53_other-completed-mobile.png)・[Bのお気に入り](54_other-favorites-mobile.png)・[PC詳細](55_other-detail-pc.png) |
| AC-12-03 | 満たす | 作成者Aが削除を承認。書籍一覧・A/Bのお気に入り・ランキングから消え、ジャンル2件は0冊で残存。[削除後一覧](58_deleted-index-pc.png)・[Aお気に入り](59_owner-favorites-after-delete-pc.png)・[ランキング](60_ranking-after-delete-pc.png)・[ジャンル](61_genres-after-delete-pc.png)・[Bお気に入り](62_other-favorites-after-delete-pc.png) |
| AC-12-04 | 満たさない（表示制約） | 基本13画面のリンク・メニュー・フォーム・戻り先を両幅で操作し、501や描画例外はなかった。ただしランキングで件数と評価バッジが重なり、長いジャンル名では操作列が画面外へ出る。[ランキング](15_seed-ranking-mobile-overlap.png)・[長いジャンル名](44_long-genre-mobile.png)。Blade変更禁止を維持し、表示品質の達成へ広げない |
| AC-3-02（残件回収） | 満たす | 指定11冊すべてを一覧のリンクから開き、タイトル・著者・ISBN・出版日・ジャンル・固定説明・画像と各2〜4レビューを確認。下表の11枚を参照 |

### 指定Seederの詳細確認

初回確認は業務7表0件を確認して既存DatabaseSeederを1回実行した。users=5・genres=10・books=11・book_genre=16・reviews=32・favorites=19・review_likes=48。通常操作用データを混ぜず、確認終了後に投入IDだけを削除して全保存値を開始状態へ戻してから横断操作へ進んだ。

| 書籍 | レビュー数 | PCの詳細証拠 |
|---|---:|---|
| 吾輩は猫である | 4 | [詳細](03_seed-9784101010014-pc.png) |
| 人を動かす | 2 | [詳細](04_seed-9784422100524-pc.png) |
| リーダブルコード | 3 | [詳細](05_seed-9784873115658-pc.png) |
| 7つの習慣 | 3 | [詳細](06_seed-9784863940246-pc.png) |
| 坊っちゃん | 3 | [詳細](07_seed-9784101010021-pc.png) |
| サピエンス全史 | 3 | [詳細](08_seed-9784309226712-pc.png) |
| Clean Code | 3 | [詳細](09_seed-9784048930598-pc.png) |
| 嫌われる勇気 | 3 | [詳細](10_seed-9784478025819-pc.png) |
| 火花 | 3 | [詳細](11_seed-9784163902302-pc.png) |
| FACTFULNESS | 3 | [詳細](12_seed-9784822289607-pc.png) |
| コンテナ物語 | 2 | [詳細](13_seed-9784822251468-pc.png) |

11冊の画像はすべて読み込み成功。各書籍のレビュー数の合計は32件。追加で[10冊の1ページ目](01_seed-index-page1-pc.png)・[1冊の2ページ目](02_seed-index-page2-pc.png)、初期利用者のログイン後の[指定10ジャンル](14_seed-genres-pc.png)も確認した。記載IDは今回の検証データであり将来の前提にしない。

### 基本13画面・キャンセル・認証の確認範囲

| 画面 | PC | モバイル |
|---|---|---|
| SC-01 書籍一覧 | [一覧](25_workflow-index-pc.png) | [一覧](34_index-mobile.png) |
| SC-02 書籍詳細 | [本人詳細](26_workflow-owner-detail-pc.png) | [本人詳細](35_owner-detail-mobile.png) |
| SC-03 書籍登録 | [登録フォーム](23_book-create-pc.png) | [登録フォーム](38_book-create-mobile.png) |
| SC-04 書籍編集 | [編集フォーム](27_book-edit-pc.png) | [編集フォーム](36_book-edit-mobile.png) |
| SC-05 レビュー編集 | [編集フォーム](28_review-edit-pc.png) | [編集フォーム](37_review-edit-mobile.png) |
| SC-06 ジャンル一覧 | [一覧](21_genre-index-pc.png) | [一覧](40_genres-mobile.png) |
| SC-07 ジャンル詳細 | [詳細](30_genre-detail-pc.png) | [詳細](41_genre-detail-mobile.png) |
| SC-08 ジャンル登録 | [登録フォーム](20_genre-create-pc.png) | [登録フォーム](43_genre-create-mobile.png) |
| SC-09 ジャンル編集 | [編集フォーム](31_genre-edit-pc.png) | [編集フォーム](42_genre-edit-mobile.png) |
| SC-10 お気に入り | [一覧](29_favorites-pc.png) | [一覧](39_favorites-mobile.png) |
| SC-11 ランキング | [ランキング](33_ranking-pc.png) | [ランキング](48_ranking-mobile.png) |
| SC-12 ログイン | [ログイン](17_login-pc.png) | [ログイン](49_login-mobile.png) |
| SC-13 会員登録 | [登録フォーム](18_register-pc.png) | [登録フォーム](50_register-mobile.png) |

書籍・レビュー・ジャンルの編集キャンセル、書籍登録キャンセルで値や件数を増やさないことを画面で確認した。[ジャンル削除中止](45_genre-delete-cancel-mobile.png)後は行が残り、[承認後](46_genre-deleted-mobile.png)に消えた。[書籍削除中止](57_book-delete-cancel-pc.png)後も書籍・2レビューが残り、その後の明示承認で削除した。確認ダイアログは同じPlaywright呼出しの事前ハンドラーで中止・承認した。ツールが一時的なmodal stateを返した後も処理が継続したため、次のスナップショットと保存画像で結果を確認した。後追いのdialog操作は既に解消済みと返り、別の削除は送信していない。

モバイルメニューの開閉・メール表示は[ナビ](47_mobile-navigation.png)、保護画面へのログイン後復帰は[書籍登録](65_intended-create-mobile.png)で確認。[ログアウト後](63_logout-login-pc.png)はログインへ戻り、元画面がないログインは一覧へ戻った。[認証失敗](64_login-error-pc.png)は正式な句点なし文言がemail欄下へ出て、パスワードは空。書籍のタイトル必須エラーと他項目・ジャンル選択保持も[実際の入力エラー](22_book-validation-pc.png)で確認した。

## 後片付け・残件

横断UATで作成した利用者は専用メールの接頭辞、ジャンルは専用名の接頭辞と作成IDで限定して後片付けした。開始終了の業務7表の全保存値は一致し各0件。AUTO_INCREMENTは巻き戻していない。専用3コンテキストのタブは閉じ、既存のブラウザータブは保持した。PHP104ファイルと起動用2PHP、保護対象69ファイル、JUnit・Clover・DBの前後記録を親も照合し、[検証要約](verification-summary.json)と65枚の画像を保持した。

照合後に今回のHTTP・検証コンテナを停止・削除し、隔離コピー、専用セッション、確認用ログ、データ準備スクリプト、親の一時記録も削除した。既存のLaravel・MySQL・phpMyAdminの3サービスとボリュームは維持している。既存docs/CODEX_HANDOFF.mdの変更、先行のIssue本文同期、未追跡.playwright-mcp/も保持した。

テスト補強用に別途作成した隔離環境は、2026-11-28の成果物照合時に親が後片付けした。業務7表の開始終了の全保存値一致（各0件）、起動用を含むPHP106ファイル一致、保護対象113ファイル不変を確認し、残っていた専用検証コンテナと隔離コピーを削除した。JUnit・Clover・誤実装検出ログ・DB前後記録は照合用の一時領域へ退避し、共有する実績値は[検証要約](verification-summary.json)へ反映した。既存サービス・ボリューム・開発用DBは変更していない。

モバイルランキングの先頭2冊では件数表示と評価の矩形が約16.6×28px重なった。長いジャンル名では横方向の移動で操作列へ到達して削除できたが、初期表示の制約は残る。完成済みBladeを変更していないためAC-12-04は未達のままとする。

2026-11-27、上記2点についてユーザーより「クライアントに確認をとってそのままでいきます」との指示を受け、表示は現状維持とし、修正は行わない方針を記録した。観測した表示制約とAC-12-04の未達記録は保持し、この指示によって表示の解消・再検証済みとは扱わない。

Q-19の正式文言確認、実環境のログ上書き・連続稼働条件の照合、過去のフック問題・依存アドバイザリー再監査・侵入試験は今回の完了へ含めない。

本報告の作成・証拠照合だけによるテスト再実行はしていない。コミット・push・PR作成・Issueのクローズは行っていない。
