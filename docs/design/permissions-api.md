# 権限・ルーティング・公開API設計

作成日: 2026-11-15 / 最終整理日: 2026-11-17

共通方針は [README.md](README.md)、機能はfeatures.md、確定回答・未回答案はquestions.mdに従う。根拠はS5・7・8・13とQA。**APIのキー・型・表現などの詳細案はQ-22で未承認**、登録者変更はQ-18、障害時応答はQ-23で保留する。

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

## Webルーティング一覧

保存後の結果表示は [screens.md](screens.md#操作結果とエラーの扱い)、例外処理はbehavior.md。{book}・{genre}・{review}は主キー。

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
- favorites.toggleは操作元の詳細／一覧へ戻す。空ページの補正方法はQ-24で未確定。

## 公開API

### 共通契約・経路

5本すべて認証不要。トークン発行やジャンル・レビュー・お気に入りのAPIは追加しない。API Resourceを使い、成功本文はdataに包む。一覧はlinks・meta、削除204は本文なし、エラーでフォームへリダイレクトしない。Accept: application/json、POST・PUTのContent-Type: application/jsonを詳細案とする。

| API／機能 | メソッド・エンドポイント | 入力 | 成功 | 業務上のエラー |
|---|---|---|---|---|
| AP01／F-22 | GET `/api/v1/books` | 一覧クエリ | 200、書籍配列＋ページ情報 | 422 |
| AP02／F-23 | GET `/api/v1/books/{book}` | 書籍ID | 200、書籍・ジャンル・レビュー | 404 |
| AP03／F-24 | POST `/api/v1/books` | 書籍JSON | 201、保存後の書籍・ジャンル | 422 |
| AP04／F-25 | PUT `/api/v1/books/{book}` | 書籍ID＋書籍JSON | 200、保存後の書籍・ジャンル | 404・422 |
| AP05／F-26 | DELETE `/api/v1/books/{book}` | 書籍ID、ボディなし | 204、本文なし | 404 |

### 入力定義

AP01のクエリ（Q-07の確定条件、page省略時はQ-22の案）：

| キー | 型・必須 | 条件 |
|---|---|---|
| `keyword` | 文字列・任意 | 255文字以内。タイトルまたは著者の部分一致 |
| `genre_id` | 整数・任意 | 実在するジャンルID |
| `page` | 整数・任意 | 1以上。省略時1とする設計案 |
| `per_page` | 整数・任意 | 1〜100、省略時20 |

タイトルOR著者の部分一致を括り、ジャンル条件とANDで結ぶ。登録日時降順、存在しないgenre_idは422、検索結果なしは200＋空配列。同日時順・比較方法・ページ条件の保持・範囲外応答はQ-22の案を参照する。

AP03・04のボディ（必須条件はQ-04・07、キー・JSON型・表記はQ-22の案）：

| キー | JSONの型 | 条件 |
|---|---|---|
| `user_id` | 整数 | POST・PUTとも必須、実在するユーザーID |
| `title` | 文字列 | 必須、255文字以内 |
| `author` | 文字列 | 必須、255文字以内 |
| `isbn` | 文字列 | 必須、ハイフンなし半角数字13桁、一意。PUTでは自身を除外 |
| `published_date` | 文字列 | 必須、有効な日付、未来日可。表記は `YYYY-MM-DD` とする設計案 |
| `description` | 文字列／null | 任意、5,000文字以内 |
| `image_url` | 文字列／null | 任意、255文字以内、HTTP／HTTPSのURL |
| `genres` | 整数配列 | 必須、既存IDを1つ以上、重複不可。キー名はWeb項目に合わせる設計案 |

PUTも必須項目をすべて要求し、ジャンル集合は置換する。ISBNは数値化・自動変換・チェックディジット検証をしない。user_id必須と登録者変更の許可（Q-18）は区別。任意2項目の省略・null・空文字はQ-22の想定案であり、DBのNULL可否とともに未確定。

### 出力定義・JSON例

次はQ-07の返却情報を具体化した**Q-22の未承認案**。日付・日時・平均・nullの例から、採用やDB定義の確定を判断しない。

| 定義 | キー・型・配置 |
|---|---|
| 共通書籍 | id:整数。title・author・isbn・published_date:文字列。description・image_url:文字列またはnull。genres:配列、各要素はid:整数・name:文字列 |
| AP01（200） | data:共通書籍の配列。各書籍にaverage_rating:数値またはnull、reviews_count:整数。未評価はnull・0。最上位にlinks・meta |
| AP02（200） | data:共通書籍1件＋reviews配列。レビューはid:整数・user_name:文字列・rating:整数1〜5・comment:文字列・created_at:日時文字列。0件は[]、登録日時降順で全件 |
| AP03（201）・AP04（200） | data:保存後の共通書籍1件。レビュー配列・集計は加えない |
| AP05（204） | 本文なし。dataや成功文言も返さない。削除済みIDへの再削除は404 |
| links | 最上位オブジェクト。first・last:ページURL文字列、prev・next:URL文字列またはnull。端では該当の前後リンクをnullとする案 |
| meta | 最上位オブジェクト。current_page・last_page・per_page・total:整数、from・to:整数またはnull、path:文字列。totalは全体件数、from/toはページ内の先頭／末尾の通し番号 |

投稿者メール等の要件外の個人情報は返さない。AP05の物理削除・CASCADE範囲は [data.md](data.md#外部キーと親削除時の動作) を参照。0件・範囲外のmetaと検索条件を含むリンクはQ-22の案。

AP01の構造例（該当1冊、相対URL。Q-22の表現案）：

```json
{
  "data": [{
    "id": 1, "title": "吾輩は猫である", "author": "夏目漱石", "isbn": "9784101010014",
    "published_date": "1905-01-01", "description": "書籍の説明です。", "image_url": null,
    "genres": [{"id": 1, "name": "小説"}], "average_rating": 5, "reviews_count": 1
  }],
  "links": {"first": "/api/v1/books?genre_id=1&per_page=1&page=1", "last": "/api/v1/books?genre_id=1&per_page=1&page=1", "prev": null, "next": null},
  "meta": {"current_page": 1, "from": 1, "last_page": 1, "path": "/api/v1/books", "per_page": 1, "to": 1, "total": 1}
}
```

### エラーの返し方

FormRequestに分離しmessages()で日本語を定義する（S8）。422はmessageとerrors、404はmessage。errorsは項目名→メッセージ文字列配列とする案。genres.0等の配列要素キーもQ-22で確認する。

```json
{
  "message": "入力内容に誤りがあります。",
  "errors": {"title": ["タイトルは必須です。"], "isbn": ["ISBNは13桁で入力してください。"]}
}
```

上例の項目別2文言と、ISBN重複「そのISBNは既に使用されています。」はQ-05で確定。最上位message・404文言・未指定の項目別文言はQ-19・22の案で未確定。user_id・検索genre_id・genres内の不正IDは422、URL上の書籍不存在404と区別する。

基本APIに認証・所有者制限由来の401・403は加えない。対象なしと入力不正の優先順位、不正JSON・非対応メソッド・障害応答はQ-23の未承認案を [behavior.md](behavior.md#例外方針) と合わせて確認する。

## 未確定事項

具体的な想定と採否の確認は [questions.md](questions.md) のQ-18・19・22〜23に集約する。Webのお気に入りのページ補正（Q-24）や出版日のBladeへの受け渡し（Q-25）から、API契約を確定しない。
