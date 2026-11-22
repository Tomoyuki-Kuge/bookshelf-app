## 概要と背景

レビューがある書籍の評価ランキングを公開し、平均・件数・IDによる確定した順序で最大10冊を表示する。

- 実装単位: 10 / 12
- 対象: F-21、SC-11
- 現状（2026-11-23、main `062b9aa`時点）: ranking/index.blade.phpと公開ranking.indexルートは存在する。ルートは一時的な501で、`$rankedBooks`の集計・取得・画面接続は未実装。
- 設計: [features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md)、[screens.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/screens.md)、[permissions-api.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/permissions-api.md)、[data.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/data.md)

## 受け入れ条件（AC）

[acceptance.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/acceptance.md#10-評価ランキングを閲覧できる)の現行本文に合わせる。チェック状態は今回変更せず、過去の確認結果・後続確認は検証欄を参照する。設計の承認を実装完了・手動確認済みとは扱わない。

- [ ] AC-10-01: ゲストでランキングにアクセスすると、レビューのある書籍だけが最大10冊、1位からの連番で表示され、タイトルから対象詳細へ移動できる。
- [ ] AC-10-02: 平均評価・件数が異なる確認用データでランキングにアクセスすると、丸め前の平均評価が高い順、同平均ならレビューの多い順、さらに同じなら書籍IDの小さい順に表示される。
- [ ] AC-10-03: 全書籍にレビューがない状態でランキングにアクセスすると、「まだレビューが投稿された書籍がありません。」が表示される。

確認時期・データ準備: 単位1のテーブル・関連と独立した確認用データで集計を検証できる。手動確認ではSeederまたは追加データを用意し、初期データだけで同点・丸め差が揃うと仮定しない。更新操作との連携は単位6・7などの接続後にも確認する。

## スコープの切り分け

### この単位の実装範囲

- 公開GET `/ranking`（ranking.index）を接続し、レビューのない書籍を対象から除外する。
- 未丸め平均評価の降順→レビュー件数降順→書籍ID昇順で最大10冊を取得する。同点でも同順位にせず1位からの連番にする。
- 既存Bladeへ順位・画像・タイトル・著者・平均・レビュー件数と詳細リンクを渡し、星隣は小数2桁、バッジは1桁、画像なしはNo Imageとする。
- 対象0件の確定文言、0・1・10・11冊の件数境界と、レビュー投稿・評価更新・削除および書籍削除後の再集計を確認する。

### 対象外・維持する既存動作

- 表示用に丸めた平均で並べ替えない。期間別・利用者別ランキング、同順位共有、保存用の平均・件数列は追加しない。
- 完成済みBlade（レイアウト・共通部品・文言・入力項目・ルート参照）とdocs/reference/の控えを維持する。基本要件の対象外は[features.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/features.md#今回作らないもの)に従う。

### 検証

- 対応するテスト観点: T-10-1〜T-10-6（[test_case.md](https://github.com/Tomoyuki-Kuge/bookshelf-app/blob/main/docs/design/test_case.md#10-評価ランキングを閲覧できる)）。
- 正常系・異常系・境界値と、（※仕様保留中）の観点を分けて扱う。未承認の推奨案を期待値にしない。
- 本単位の処理本体・手動ACの完了は未確認。既存のルート・モデル・認可テストと区別する。今回の本文更新では実装・自動テスト・画面確認を行っていない。

## 確定事項と残る確認事項

- この単位に固有の未回答仕様はない。未丸め平均による比較とWebの表示桁数は確定済みで、APIの平均小数1桁の契約をランキングの並び順へ流用しない。新たな不明点はquestions.mdへ推奨案付きで記録する。

### 設計・決定事項の扱い

- 回答状況・未指定文言の正本はquestions.md。Q-18・22〜26とDB物理条件は承認済みで、Q-19の未指定の個別文言は保留を維持する。新たな仕様判断が必要なら、設計へ記録・承認後に実装・検証する。
- 「設計書の12実装単位を、1単位1件」の範囲を維持する。今回の本文同期は仕様の新規決定・アプリの変更・テスト再実行ではなく、Issueの開閉状態とACのチェック状態も変更しない。
- 関連・着手順の目安（提案）: 実装単位1・2・4を参照する。実装の前提と、上記ACを画面で確認できる時期は分けて扱う。
