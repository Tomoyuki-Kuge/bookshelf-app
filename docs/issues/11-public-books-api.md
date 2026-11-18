## 概要と背景

認証不要の公開書籍APIを5経路実装し、検索付き一覧・詳細・登録・更新・物理削除をJSONで利用できるようにする。

- 実装単位: 11 / 12
- 対象: F-22〜26、AP01〜05
- 現状: `routes/api.php`、API用Controller・FormRequest・Resourceと業務モデルは未実装。Web向け本人認可をAPIへ流用できる契約ではない。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)、[behavior.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/behavior.md)、[questions.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#11-公開書籍apiを利用できる)の原文を記載する。全項目未確認で、実装・確認済みを示さない。

- [ ] AC-11-01: ログインしていないブラウザで `/api/v1/books`にアクセスすると、書籍・ジャンル・平均評価・レビュー件数を含むJSONがdataと一覧のlinks・meta付きで表示される。
- [ ] AC-11-02: 検索用データに対応するkeywordとgenre_idを付けて一覧URLにアクセスすると、「タイトルまたは著者の部分一致」と「指定ジャンル」を両方満たす書籍だけが表示される。
- [ ] AC-11-03: `/api/v1/books/{存在する書籍ID}`にアクセスすると、対象書籍・ジャンル・レビューの投稿者名・評価・コメント・投稿日時を含むJSONが表示される。
- [ ] AC-11-04: 存在しない書籍IDの詳細URLにアクセスすると、対象が見つからない旨のJSONが表示され、検索結果が0件になるkeywordで一覧にアクセスすると、空の書籍配列が表示される。

確認時期・データ準備: ACは未ログインのブラウザでGETを開いて確認する。POST・PUT・DELETE、認証不要、内部保存・削除・境界はT-11で検証し、Web本人制限と分ける。単位6などと入力・関連・削除範囲の整合を確認する。

## スコープの切り分け

### 今回実装するもの

- GET／POST `/api/v1/books`とGET／PUT／DELETE `/api/v1/books/{book}`を接続する。5経路とも認証・トークン・Web本人Policyを要求しない。
- 一覧はタイトルOR著者の部分一致keywordとgenre_idのAND条件、登録日時降順、per_page既定20・1〜100でページ分割する。keyword上限255、pageは1以上、参照ジャンル不正は422、結果0件は200＋空配列とする。
- API Resourceで成功本文をdataに包み、一覧へlinks・metaとジャンル・平均評価・レビュー件数を含める。未評価はnull・0件。詳細へ書籍・ジャンルとレビューの投稿者名・評価・コメント・投稿日時を含め、不要な個人情報を返さない。
- POST・PUTとも実在user_idとWeb同等の必須入力・上限・形式・一意性をFormRequestで検証し、日本語エラーを返す。PUTも必須項目を要求し、ISBNは自身を除外、ジャンル集合は置換する。
- 一覧・詳細・更新200、登録201、削除204・本文なし、対象なし404、入力不正422を実装する。削除はWebと同じCASCADE範囲とし、フォームへリダイレクトしない。

### 対象外・維持する既存動作

- Sanctum・トークン発行・APIの所有者制限、ジャンル／レビュー／お気に入りAPIは追加しない。APIのuser_idを認証主体の証明として扱わない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-11-1〜T-11-24（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#11-公開書籍apiを利用できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 今回はIssue作成のみのため、受け入れ条件の手動確認・自動テストは未実行。

## 未決事項・確認事項

- [Q-18](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-18更新apiによる登録者変更の可否): （※仕様保留中）PUTの登録者変更可否と変更後のWeb権限。実在user_idへの変更を許可する推奨案は未承認。user_id必須という確定条件と分けて実装前に確認する。
- [Q-22](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-22公開apiの入出力表現): （※仕様保留中）入出力キー・JSON型・配置・要求ヘッダー、任意項目の省略／null／空文字、日時・平均表現、検索比較・ページ条件保持・範囲外応答。permissions-api.mdのJSON例とquestions.mdの推奨案は未承認。契約確定前に確認する。
- [Q-19](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-19画面のエラー文言表示先): （※仕様保留中）未指定の項目別エラー文言。確定文言と区別し、Q-22の最上位message・404文言などと併せて確認する。
- [Q-23](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/questions.md#q-23保存失敗同時操作障害時の扱い): （※仕様保留中）404と422の優先順位、不正JSON・非対応メソッド、保存失敗・競合・障害時の保存結果と応答。推奨案を確定扱いしない。

### 初期案・決定事項の扱い

- 未回答事項の課題・推奨案・確認先はquestions.mdを正本とする。上記の案は承認・仕様確定を意味しない。回答または開発側の決定を設計書へ記録してから実装・検証する。
- 会話で合意したIssue化の範囲は「設計書の12実装単位を、1単位1件」。個々の未確定仕様の採用は含まない。
- 関連・着手順の目安（提案）: 実装単位1を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
