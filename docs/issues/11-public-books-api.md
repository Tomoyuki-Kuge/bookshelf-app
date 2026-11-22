## 概要と背景

認証不要の公開書籍APIを5経路実装し、検索付き一覧・詳細・登録・更新・物理削除をJSONで利用できるようにする。

- 実装単位: 11 / 12
- 対象: F-22〜26、AP01〜05
- 現状（2026-11-23、main `062b9aa`時点）: 業務モデル・関連・DB制約とWeb用Policyは実装済み。routes/api.php、API経路の登録、API用Controller・FormRequest・Resourceは未実装。APIへWebの本人認可を持ち込まない。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#11-公開書籍apiを利用できる)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-11-01: ログインしていないブラウザで `/api/v1/books`にアクセスすると、書籍・ジャンル・小数1桁へ丸めた数値のaverage_rating（未評価null）・review_countを含むJSONがdataと一覧のlinks・meta付きで表示される。
- [ ] AC-11-02: 検索用データに対応するkeywordとgenre_idを付けて一覧URLにアクセスすると、「タイトルまたは著者の部分一致」と「指定ジャンル」を両方満たす書籍だけが表示される。
- [ ] AC-11-03: `/api/v1/books/{存在する書籍ID}`にアクセスすると、対象書籍・ジャンル・平均・レビュー件数と、全レビューの投稿者名・評価・コメント・UTCの投稿日時を含むJSONが表示される。
- [ ] AC-11-04: 存在しない書籍IDの詳細URLにアクセスすると、{"error":"書籍が見つかりませんでした。"}が表示され、検索結果が0件になるkeywordや最終ページを超える正のpageで一覧にアクセスすると、空の書籍配列が表示される。

確認時期・データ準備: ACは未ログインのブラウザでGETを開いて確認する。POST・PUT・DELETE、認証不要、内部保存・削除・境界はT-11で検証し、Web本人制限と分ける。単位6などと入力・関連・削除範囲の整合を確認する。

## スコープの切り分け

### この単位の実装範囲

- GET／POST `/api/v1/books`とGET／PUT／DELETE `/api/v1/books/{book}`を接続する。5経路とも認証・トークン・Web本人Policyを要求しない。
- 一覧はタイトルOR著者の部分一致keywordとgenre_idのAND条件、登録日時降順、per_page既定20・1〜100でページ分割する。keyword上限255、pageは1以上、参照ジャンル不正は422、結果0件は200＋空配列とする。
- API Resourceで成功本文をdataに包み、一覧へlinks・metaとジャンル・平均評価・レビュー件数を含める。未評価はnull・0件。詳細へ書籍・ジャンルとレビューの投稿者名・評価・コメント・投稿日時を含め、不要な個人情報を返さない。
- POST・PUTとも実在user_idとWeb同等の必須入力・上限・形式・一意性をFormRequestで検証し、日本語エラーを返す。PUTも必須項目を要求し、ISBNは自身を除外、ジャンル集合は置換する。
- 一覧・詳細・更新200、登録201、削除204・本文なし、対象なし404、入力不正422を実装する。削除はWebと同じCASCADE範囲とし、フォームへリダイレクトしない。

- Q-18・22・23の確定契約に従い、登録者変更、共通BookResourceの返却範囲・キー・型・日時、検索比較・ページ境界、保存競合と400／405／422／404／500の応答を実装・検証する。

### 対象外・維持する既存動作

- Sanctum・トークン発行・APIの所有者制限、ジャンル／レビュー／お気に入りAPIは追加しない。APIのuser_idを認証主体の証明として扱わない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-11-1〜T-11-24（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#11-公開書籍apiを利用できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 本単位の処理本体・手動ACの完了は未確認。既存のルート・モデル・認可テストと区別する。今回の本文更新では実装・自動テスト・画面確認を行っていない。

## 確定事項と残る確認事項

- [Q-18](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-18更新apiによる登録者変更の可否)：PUTで実在する指定user_idへの登録者変更を許可し、Webの本人権限を新登録者へ移す。APIのuser_idを認証主体の証明に使わない。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：要求ヘッダーはAccept: application/json、POST・PUTはContent-Type: application/json。入出力キー・型・data／links／meta、任意項目、検索比較、ページ条件保持・範囲外応答は承認済み。平均は数値のaverage_rating（小数1桁に四捨五入、未評価null）、件数はreview_count。詳細・POST・PUTは共通BookResourceで全レビュー・投稿者・平均・件数を返す。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：出版日はYYYY-MM-DD、投稿日時はISO 8601・UTC・秒単位。レビューは登録日時降順・同日時ID降順。POST・PUTは保存後に関連・件数・平均を取得してResourceへ渡す。個人情報を返さない。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：POSTで任意2項目の省略・null・空文字はNULL、PUTで省略は既存値維持・明示的null／空文字はNULL。検索語は前後空白除去、英字大小同一・全半角区別、%・_は通常文字として扱う。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：page省略は1、登録日時降順・同日時ID降順。範囲外の正のpageも200＋空配列で要求pageを保持し、from/toはnull、last_pageは実際の最終ページ（全体0件なら1）。リンクへ適用中の検索・ページ件数条件を引き継ぐ。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現)：422はmessage「入力内容に誤りがあります。」＋項目別errors配列。書籍不存在404は{"error":"書籍が見つかりませんでした。"}。400・405・422・500のmessage形式と区別する。ID・ページ等の正式文言は設計を参照。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い)：書籍・ジャンルは原子保存し、対象なし404を入力不正422より優先する。不正JSON400・非対応メソッド405・想定外障害500、保存競合の分類と取消はbehavior.mdに従う。
- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先)：未指定の個別エラー文言のみ（※仕様保留中）。Q-22で指定済みのAPI最上位message・404形式・参照ID等の文言を保留に戻さない。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
