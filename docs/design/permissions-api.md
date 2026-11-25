# 権限・ルーティング・公開API設計

作成日: 2026-11-15 / 最終整理日: 2026-11-25

共通方針は [README.md](README.md)、機能はfeatures.md、確定回答・未回答案はquestions.mdに従う。根拠はS5・7・8・13とQA。2026-11-22確認のQAに従い、API契約（Q-22）・登録者変更（Q-18）・障害時応答（Q-23）を確定する。未指定の個別エラー文言はQ-19の残件に従う。

## 権限マトリクス

○=利用可、△=条件付き、×=その経路を提供しない。一般会員のみで管理者区分なし。

| 機能 | ゲスト（Web） | 認証済み利用者（Web） | 公開API利用者 |
|---|:---:|:---:|:---:|
| F-01 会員登録 | ○ | × | × |
| F-02 ログイン | ○ | × | × |
| F-03 ログアウト | × | ○ | × |
| F-04 書籍一覧 | ○ | ○ | × |
| F-05 書籍詳細・レビュー閲覧 | ○ | ○ | × |
| F-06 書籍登録 | × | ○ | × |
| F-07 書籍編集 | × | △ | × |
| F-08 書籍削除 | × | △ | × |
| F-09 ジャンル紐付け | × | △ | × |
| F-10 ジャンル一覧 | × | ○ | × |
| F-11 ジャンル詳細 | × | ○ | × |
| F-12 ジャンル登録 | × | ○ | × |
| F-13 ジャンル編集 | × | ○ | × |
| F-14 ジャンル削除 | × | △ | × |
| F-15 レビュー投稿 | × | ○ | × |
| F-16 レビュー編集 | × | △ | × |
| F-17 レビュー削除 | × | △ | × |
| F-18 レビューへのいいね | × | ○ | × |
| F-19 お気に入り登録・解除 | × | △ | × |
| F-20 お気に入り一覧 | × | △ | × |
| F-21 評価ランキング | ○ | ○ | × |
| F-22 API書籍一覧 | × | × | ○ |
| F-23 API書籍詳細 | × | × | ○ |
| F-24 API書籍登録 | × | × | ○ |
| F-25 API書籍更新 | × | × | ○ |
| F-26 API書籍削除 | × | × | ○ |
| F-27 初期データ投入 | × | × | × |
| F-28 基本テスト | × | × | × |

- 書籍の編集・削除はbooks.user_id、レビューはreviews.user_idとログインIDが一致する場合のみ。書籍作成者も他人のレビューは操作不可。
- F-09は登録時または本人の書籍編集時に付随する操作。F-14は書籍紐付きなし、F-19・20は自分の登録分のみ（書籍所有者でなくても可）。
- 認証済みで認証画面へ来た場合は `/`。Webのゲスト・会員ともAPI利用者になれ、基本APIにはWebの本人制限を適用しない。
- F-27・28は開発要件で利用者向け経路なし。実行環境の権限は未指定。○でも対象存在・入力検証を行う。複数レビュー・自分へのいいねは可。

### Issue #6の書籍管理接続（2026-11-25ユーザー承認）

books.store・edit・update・destroyをBookControllerへ接続し、登録・更新の検証は共通BookRequestで扱う。既存のルート名・HTTPメソッド・認証と本人Policyを維持する。認証→対象解決→本人認可→入力検証の順序と、保存直前の対象・権限再確認はbehavior.mdのIssue #6補足に従う。Web作成者は認証情報から設定して更新でも維持し、公開APIへの本人制限は追加しない。

## Webルーティング一覧

保存後の結果表示は [screens.md](screens.md#操作結果とエラーの扱い)、例外処理はbehavior.md。{book}・{genre}・{review}は主キー。

### Issue #2の接続段階（2026-11-20ユーザー承認）

- Laravel 10・PHP 8.2に対応するFortifyを導入する。候補v1.30.0の依存条件を確認済みで、導入時の依存解決結果をcomposer.lockに固定する。
- 自動ルート登録を無効にし、下表の認証5経路のみFortifyのControllerへ接続する。登録はFormRequestの検証とCreatesNewUsersの登録Action、ログインはFortifyのLoginRequestを拡張したFormRequestで入力条件・日本語messages()を定義する。認証・セッション管理を独自実装で並立させない。
- 登録成功はintendedの有無によらず`/`へ、ログイン成功は元の保護ページまたは`/`へ、ログアウトはセッション破棄・CSRFトークン再生成後`/login`へ。既存の認証済みゲスト画面からの`/`への誘導を利用する。
- ログイン試行制限はFortify標準（同一メール・IPで失敗5回、60秒）を維持し、制限時はemail欄下へ「ログインの試行回数が多すぎます。:seconds秒後に再度お試しください。」と日本語表示する。認証エラーの正式文言と未指定文言の暫定採用はQ-19を参照する。
- `/`の既存書籍一覧とGET `/books/create`の既存登録フォームだけは、認証後の導線確認に必要な実データの読み取りを接続する。一覧は既存の順序・10件ページ分割・ジャンル・平均評価の表示契約に従い、登録フォームには既存ジャンルを渡す。
- その他の業務経路は下表どおりの名前・引数・メソッド・認証条件で骨組みを登録し、処理本体は一時的に501とする。書籍・レビューの本人制限はPolicyで適用する。501は開発中の未実装を示し、完成時の仕様やQ-23の障害応答を変更するものではない。後続Issueで表示・保存を接続する。
- パスワード再設定、確認、変更、メール認証、二要素認証、プロフィール変更等の追加経路は公開しない。各業務機能・公開API・Seederの完成は今回に含めない。
- 共通ナビ・認証画面と上記2画面の描画、ゲストの保護経路への誘導を今回確認する。他の業務画面の描画・公開画面の実データ閲覧は後続Issueで確認し、T-2-9〜10の全範囲を完了扱いにしない。

| メソッド | パス | ルート名／担当 | 機能 | 利用条件 | 正常時の画面・移動先 |
|---|---|---|---|---|---|
| GET | `/register` | `register` | F-01 | ゲスト向け | SC-13 |
| POST | `/register` | Fortify登録処理 | F-01 | ゲスト向け | 自動ログイン後 `/` |
| GET | `/login` | `login` | F-02 | ゲスト向け | SC-12 |
| POST | `/login` | Fortifyログイン処理 | F-02 | ゲスト向け | 元の保護ページ、なければ `/` |
| POST | `/logout` | `logout` | F-03 | 認証 | セッション破棄後 `/login` |
| GET | `/` | `books.index` | F-04 | 公開 | SC-01、10件/ページ |
| GET | `/books/create` | `books.create` | F-06・09 | 認証 | SC-03 |
| POST | `/books` | `books.store` | F-06・09 | 認証 | 登録した書籍詳細 |
| GET | `/books/{book}` | `books.show` | F-05 | 公開 | SC-02 |
| GET | `/books/{book}/edit` | `books.edit` | F-07・09 | 認証＋BookPolicy本人判定 | SC-04 |
| PUT | `/books/{book}` | `books.update` | F-07・09 | 認証＋BookPolicy本人判定 | 更新した書籍詳細 |
| DELETE | `/books/{book}` | `books.destroy` | F-08 | 認証＋BookPolicy本人判定 | `/` |
| GET | `/genres` | `genres.index` | F-10 | 認証 | SC-06 |
| GET | `/genres/create` | `genres.create` | F-12 | 認証 | SC-08 |
| POST | `/genres` | `genres.store` | F-12 | 認証 | ジャンル一覧 |
| GET | `/genres/{genre}` | `genres.show` | F-11 | 認証 | SC-07、10件/ページ |
| GET | `/genres/{genre}/edit` | `genres.edit` | F-13 | 認証 | SC-09 |
| PUT | `/genres/{genre}` | `genres.update` | F-13 | 認証 | ジャンル一覧 |
| DELETE | `/genres/{genre}` | `genres.destroy` | F-14 | 認証＋紐付きなし | ジャンル一覧 |
| POST | `/books/{book}/reviews` | `reviews.store` | F-15 | 認証 | 対象書籍詳細 |
| GET | `/reviews/{review}/edit` | `reviews.edit` | F-16 | 認証＋ReviewPolicy本人判定 | SC-05 |
| PUT | `/reviews/{review}` | `reviews.update` | F-16 | 認証＋ReviewPolicy本人判定 | 対象書籍詳細 |
| DELETE | `/reviews/{review}` | `reviews.destroy` | F-17 | 認証＋ReviewPolicy本人判定 | 対象書籍詳細 |
| POST | `/reviews/{review}/like` | `reviews.like` | F-18 | 認証 | 対象書籍詳細 |
| POST | `/books/{book}/favorites` | `favorites.toggle` | F-19 | 認証、自分の登録分 | 操作元の書籍詳細／お気に入り一覧 |
| GET | `/favorites` | `favorites.index` | F-20 | 認証、自分の登録分 | SC-10、10件/ページ |
| GET | `/ranking` | `ranking.index` | F-21 | 公開 | SC-11、最大10冊 |

- 一覧は `/` を採用しGET /booksは追加しない。POST /booksは登録用。固定のcreateパスは可変パスより先に解決し、GETで変更しない。
- BookPolicy・ReviewPolicyはGET編集・PUT更新・DELETEごとに判定。画面の本人操作表示と直接アクセスの制御を一致させる。
- Webの作成者・お気に入り・いいねの利用者は認証情報から設定し、入力で登録者を変更しない。
- favorites.toggleは操作元の詳細／一覧へ戻す。解除後は現ページを維持し、消滅時のみ最後の有効ページ（全件0件なら1ページ目）へ補正する（Q-24）。

### Issue #4の公開閲覧接続（2026-11-23ユーザー承認）

- Issue #2の骨組みのうちbooks.showをBookController::showへ接続し、既存のbooks.indexとともにSC-01・02の閲覧を完成させる。保存操作・編集画面・ランキング等の未接続経路は各後続単位で扱う。
- 取得は既存のEloquent関連と集計・ページ分割を使う。一覧は現行の取得処理を再利用し、詳細では対象書籍のgenres、登録日時降順のreviewsと各user・likedByUsersをまとめて取得する。認証済みの場合は利用者のfavoriteBooks・likedReviewsも取得し、既存Bladeの状態表示へ渡す。件数専用属性だけを追加して既存Bladeの関連参照を置き換えない。
- 公開閲覧に本人用Policyを課さず、編集・削除の表示は既存のBookPolicy・ReviewPolicyに任せる。対象確認はモデルバインディングを使い、詳細GETのbookは数字列に制限してDBの暗黙変換による不正な対象解決を防ぐ。数字IDの不存在・削除済みは404を維持する。不正形式のGETは、同パスの既存PUT・DELETE経路との照合によるLaravel標準の405とし、保護経路の制約や独自応答を追加しない。create固定パスの先行とGET /booksを追加しない契約も維持する。
- 出版日はQ-25の既存モデルの文字列表現を使う。詳細の受け渡しは単位4、編集フォームの既存値表示は単位6で確認する。
- AC-4-01〜04とT-4-1〜12を照合する。フラッシュ・旧入力・エラー枠はセッション値を準備して表示を確認し、実際の保存後の戻り先・保存結果は後続単位で確認する。T-4-12の編集画面部分やT-2の未接続画面を確認済みにしない。

### Issue #5のジャンル管理接続（2026-11-25ユーザー承認）

- genresの7経路をGenreControllerへ接続し、全認証済み利用者が共通ジャンルを管理する。既存Blade・DB定義を維持し、所有者・管理者のPolicyやジャンルAPIは追加しない。
- 一覧はwithCount('books')を用いてID昇順で全件取得する。詳細は対象のbooks関連に条件を掛けてから登録日時降順・10件でページ分割し、各書籍のgenresを一括取得する。
- 認証後、genreの明示バインディング内で数字列であることを確認して主キー検索する。不正形式・不存在・削除済みは404。ルートのwhereNumberで認証前に拒否せず、ゲストは既存の認証ミドルウェアでログインへ誘導する。create固定経路を先に解決する。対象なしは更新入力の不正より優先する。
- 登録・更新は共通GenreRequestへ分離し、前後空白除去後のnameだけを検証・保存する。重複判定はDBと同じLOWER(name) COLLATE utf8mb4_binを用い、更新時はバインド済み対象のIDを除外する。文字列以外の旧入力は欄へ戻さず、入力エラーの戻り先は登録・編集の内部ルートとする。暫定文言はQ-19を参照する。
- 登録・更新・削除の成功はgenres.indexへsuccessフラッシュ、紐付きによる削除拒否は同じ一覧へerrorフラッシュで戻す。削除では中間行をdetachせず、事前確認とDBのRESTRICTで関連を保護する。競合・想定外障害の分類はbehavior.mdを参照する。

## 公開API

### 共通契約・経路

5本すべて認証不要。トークン発行やジャンル・レビュー・お気に入りのAPIは追加しない。BookResourceを使い、成功本文はdataに包む。一覧はlinks・meta、削除204は本文なし、エラーでフォームへリダイレクトしない。要求ヘッダーはAccept: application/json、POST・PUTはContent-Type: application/jsonとする（Q-22承認済み）。

| API／機能 | メソッド・エンドポイント | 入力 | 成功 | 業務上のエラー |
|---|---|---|---|---|
| AP01／F-22 | GET `/api/v1/books` | 一覧クエリ | 200、書籍配列＋ページ情報 | 422 |
| AP02／F-23 | GET `/api/v1/books/{book}` | 書籍ID | 200、書籍・ジャンル・レビュー・集計 | 404 |
| AP03／F-24 | POST `/api/v1/books` | 書籍JSON | 201、保存後の詳細と同じ構造 | 422 |
| AP04／F-25 | PUT `/api/v1/books/{book}` | 書籍ID＋書籍JSON | 200、保存後の詳細と同じ構造 | 404・422 |
| AP05／F-26 | DELETE `/api/v1/books/{book}` | 書籍ID、ボディなし | 204、本文なし | 404 |

### 入力定義

AP01のクエリ（Q-07・22の確定条件）：

| キー | 型・必須 | 条件 |
|---|---|---|
| `keyword` | 文字列・任意 | 255文字以内。タイトルまたは著者の部分一致 |
| `genre_id` | 整数・任意 | 実在するジャンルID |
| `page` | 整数・任意 | 1以上。省略時1 |
| `per_page` | 整数・任意 | 1〜100、省略時20 |

keywordは前後空白を除去し、空なら検索条件なし。英字大小は区別せず全角半角は区別し、`%`・`_` は通常の文字として部分一致させる。タイトルOR著者を括り、ジャンル条件とANDで結ぶ。登録日時降順・同日時ID降順、存在しないgenre_idは422。比較・エスケープの具体方式は開発側で実装時に確認する。

検索結果なしは200＋空配列。最終ページを超える正のpageも200＋空配列で、current_pageは要求値を維持し、from/toはnull、last_pageは実際の最終ページ（全体0件なら1）とする。0件のpage=1ではcurrent_page/last_pageは1、totalは0、from/to・prev/nextはnull。リンクへkeyword・genre_id・per_pageの適用中の条件を引き継ぐ。Webのお気に入りの補正とは区別する。

AP03・04のボディ（Q-04・07・22の確定条件）：

| キー | JSONの型 | 条件 |
|---|---|---|
| `user_id` | 整数 | POST・PUTとも必須、実在するユーザーID |
| `title` | 文字列 | 必須、255文字以内 |
| `author` | 文字列 | 必須、255文字以内 |
| `isbn` | 文字列 | 必須、ハイフンなし半角数字13桁、一意。PUTでは自身を除外 |
| `published_date` | 文字列 | 必須、有効な日付、未来日可。`YYYY-MM-DD` |
| `description` | 文字列／null | 任意、5,000文字以内 |
| `image_url` | 文字列／null | 任意、255文字以内、HTTP／HTTPSのURL |
| `genres` | 整数配列 | 必須、既存IDを1つ以上、重複不可 |

PUTも必須項目をすべて要求し、ジャンル集合は置換する。ISBNは数値化・自動変換・チェックディジット検証をしない。Q-18に従い実在する指定user_idへの登録者変更を許可し、Webの編集・削除権限は保存後のbooks.user_idに従って新登録者へ移す。APIにWeb本人用Policyや認証由来の401・403を追加しない。

任意2項目はDB・APIともNULL許容。POSTの省略・null・空文字はNULL保存、PUT省略は既存値維持、明示的null・空文字はNULLへ変更する。

### 出力定義・JSON例

Q-22の承認と4点の修正を反映した確定契約。ID・件数・評価は整数、平均は数値またはnullとし、表示用の文字列へ変換しない。平均は `round(..., 1)` で小数第1位へ四捨五入する。Webの表示桁数とランキングの未丸め比較は変更しない。

| 定義 | キー・型・配置 |
|---|---|
| 共通書籍 | id:整数。title・author・isbn・published_date:文字列。description・image_url:文字列またはnull。genres:配列、各要素はid:整数・name:文字列。average_rating:数値またはnull、review_count:整数。未評価はnull・0 |
| AP01（200） | data:共通書籍の配列。最上位にlinks・meta。レビュー配列は含めない |
| AP02（200） | data:共通書籍1件＋reviews配列。レビューはid:整数・user_name:文字列・rating:整数1〜5・comment:文字列・created_at:日時文字列。0件は[]、登録日時降順・同日時ID降順で全件 |
| AP03（201）・AP04（200） | data:保存後の共通書籍1件＋reviews配列。AP02と同じBookResource・構造で、投稿者・平均・レビュー件数も返す |
| AP05（204） | 本文なし。dataや成功文言も返さない。削除済みIDへの再削除は404 |
| links | 最上位オブジェクト。first・last:ページURL文字列、prev・next:URL文字列またはnull。端では該当の前後リンクをnullとする |
| meta | 最上位オブジェクト。current_page・last_page・per_page・total:整数、from・to:整数またはnull、path:文字列。totalは全体件数、from/toはページ内の先頭／末尾の通し番号 |

出版日は時差変換せずYYYY-MM-DD、レビューのcreated_atはISO 8601・UTC・秒単位（例：`2026-11-18T00:00:00Z`）。POST・PUTは保存後に `$book->load(['genres', 'reviews.user'])->loadCount('reviews')->loadAvg('reviews', 'rating')` し、詳細と共通のBookResourceで返す。公開キーは `review_count` であり、Eloquent内部の `reviews_count` 属性と区別して対応付ける。

投稿者メール等の要件外の個人情報は返さない。AP05の物理削除・CASCADE範囲は [data.md](data.md#外部キーと親削除時の動作) を参照。

AP01の構造例（該当1冊、相対URL）：

```json
{
  "data": [{
    "id": 1, "title": "吾輩は猫である", "author": "夏目漱石", "isbn": "9784101010014",
    "published_date": "1905-01-01", "description": "書籍の説明です。", "image_url": null,
    "genres": [{"id": 1, "name": "小説"}], "average_rating": 4.5, "review_count": 2
  }],
  "links": {"first": "/api/v1/books?genre_id=1&per_page=1&page=1", "last": "/api/v1/books?genre_id=1&per_page=1&page=1", "prev": null, "next": null},
  "meta": {"current_page": 1, "from": 1, "last_page": 1, "path": "/api/v1/books", "per_page": 1, "to": 1, "total": 1}
}
```

### エラーの返し方

FormRequestに分離しmessages()で日本語を定義する（S8）。422はmessageとerrors、errorsは項目名→メッセージ文字列配列とする。genres.0等の配列要素キーも使用する。

```json
{
  "message": "入力内容に誤りがあります。",
  "errors": {"title": ["タイトルは必須です。"], "isbn": ["ISBNは13桁で入力してください。"]}
}
```

書籍不存在の404は、詳細・更新・削除とも次の形式とする。

```json
{"error": "書籍が見つかりませんでした。"}
```

ISBN重複は「そのISBNは既に使用されています。」。不正なuser_idは「指定したユーザーが存在しません。」、不正な検索genre_idは「指定したジャンルが存在しません。」とし、いずれも422。page・per_page・genresの型・ID型のエラーはQ-22の確定文言、未指定の項目別文言はQ-19の保留範囲に従う。

基本APIに認証・所有者制限由来の401・403は加えない。対象なしを入力不正より優先し、不正JSON・非対応メソッド・障害・保存競合時の応答はQ-23と [behavior.md](behavior.md#例外方針) に従う。書籍404のerrorキーと、400・405・422・500のmessageキーを混同しない。

## 未確定事項

未指定の個別エラー文言のみ [questions.md](questions.md) のQ-19で確認する。Q-18・22〜26は回答済みであり、検索の比較・排他・例外分類などの具体的な内部方式は、承認済みの契約を満たすよう該当機能の実装時に検討・検証する。
