# 設計書の読み方

最終整理日: 2026-11-18

## 共通方針

- 対象は**基本要件のみ**。完成済みBladeと `docs/reference/` の控えは変更しない。設計書はバックエンドの実装完了を示さない。
- QA・ユーザーの確定回答を要件シートの未反映箇所より優先する。未回答の想定案・文言例・JSON例は、回答が記録されるまで**未確定**。
- Q番号と回答状態は [questions.md](questions.md)、各分野の詳細は下表の文書を参照する。同じ説明・履歴は繰り返さず、変更時は参照先を先に更新する。
- 新たに定義・更新する確認項目で、未確定仕様や資料に記載のない期待結果は「（※仕様保留中）」と明記し、合否を決めない。確定している条件まで保留扱いにはしない。
- S番号は [features.mdの参照資料](features.md#参照資料) に対応する。「行」はシート左端の番号。QAの日付は回答日ではなく確認・反映日。

## 文書ごとの役割

| 文書 | 記載する内容 | 対応する型 |
|---|---|---|
| [features.md](features.md) | F-01〜28の機能・対象外・Seederの責務と初期データ・資料一覧 | Tutorial 13 Chapter 2 |
| [questions.md](questions.md) | 確定回答の要約、未確定の想定案と確認事項、回答記録 | Tutorial 13 Chapter 2 |
| [screens.md](screens.md) | 画面・遷移・入力条件・Webの表示文言 | Tutorial 13 Chapter 3 |
| [permissions-api.md](permissions-api.md) | 権限・全経路・APIの入出力構造 | Tutorial 13 Chapter 4 |
| [data.md](data.md) | ER図・全物理列・制約・削除動作・CRUD | Tutorial 13 Chapter 5 |
| [behavior.md](behavior.md) | 状態図・代表シーケンス・例外処理 | Tutorial 13 Chapter 6 |
| [acceptance.md](acceptance.md) | 12実装単位のブラウザで確認する受け入れ条件 | Tutorial 13 Chapter 8 |
| [test_case.md](test_case.md) | テストユーザーの役割・確認用データの準備・12実装単位のテスト観点 |  |

## 記載量と更新

画面項目は代表画面と差分、シーケンスは代表1本、受け入れ条件はIssueごとに2〜4行、テストは観点のみ。受け入れ条件はacceptance.md、テストユーザーの役割・データ準備・詳細なテスト観点はtest_case.mdへ分け、同じ12実装単位の順序・番号で対応させる。ACの連番は01からの2桁（例：AC-3-01）とする。Seederの責務・投入仕様はfeatures.mdを正本とし、手動で見える結果とDB内部の検証を分ける。図はMarkdown内のMermaidを使い、クラス図はLaravelのMVCに従うため省略する。仕様変更・応用追加は、先に設計へ反映してから実装・Issue化する。

2026-11-17に設計書・参照仕様書・QA・UI画像と `resources/` を照合した。主要仕様の新たな重大矛盾はなく、残件は [確認先一覧](questions.md#未確定事項の確認先一覧) に集約。資料のUNIQUE記載不足は [data.md](data.md#参照資料からの反映修正)、QAの画像説明誤りは [screens.md](screens.md#根拠と照合結果) に補足する。
