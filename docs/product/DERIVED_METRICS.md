# Derived Metrics Engine Design (Phase 2)

## 1. Purpose

Phase 1(`MetricAggregationAction`)により、AIはdimension × measureの正確な
sum / count / avgを利用できるようになった。しかし ROAS = revenue / spend の
ような**比率・派生指標**については、AI自身が`aggregated_metrics`の数値を
使って自分で除算していた。

Derived Metrics Engineの目的はただ一つ:

> AIが「どの派生指標が必要か」を定義し、Laravelがその定義を
> **安全かつ正確に計算**し、AIはその結果を解釈するだけにする。

基本フロー:

```text
CSV
↓
DataProfilingAction
↓
Data Profile
↓
MetricAggregationAction
↓
aggregated_metrics
↓
PlanDerivedMetricsAction        [AI呼び出し #1: Metric Planning]
↓
CalculationDefinition[]
↓
CalculateDerivedMetricsAction   [AI呼び出しなし: Calculation Engine]
↓
derived_metrics
↓
BuildAnalysisContextAction
↓
AiAnalysisClient::analyze()     [AI呼び出し #2: 最終分析]
↓
NormalizeAnalysisResultAction
```

Phase 1の設計原則をさらに強化する:

```text
Laravel = Facts(集計値・派生指標の計算)
AI      = Interpretation(解釈・推奨)
```

---

## 2. Architecture

新規Action 2つを`app/Actions/AnalysisJob/`配下に追加する。

```text
app/Actions/AnalysisJob/
├── PlanDerivedMetricsAction.php       (AI呼び出し#1のオーケストレーション)
├── CalculateDerivedMetricsAction.php  (純粋なCalculation Engine、AI呼び出しなし)
├── BuildAnalysisContextAction.php     (変更: derived_metrics引数を追加)
└── ExecuteAnalysisJobAction.php       (変更: パイプラインに2ステップ追加)

app/AI/
└── AiAnalysisClient.php               (変更: planMetrics()メソッド追加、analyze()は責務不変)
```

`MetricAggregationAction`(`app/Actions/DataProfiling/`)自体は変更しない。

---

## 3. Planning AI(PlanDerivedMetricsAction)

### 責務

- `aggregated_metrics`から dimension名一覧・measure名一覧を抽出する
  (**実数値は一切含めない**)
- `user_prompt`と合わせてPlanning Context を構築する
- `AiAnalysisClient::planMetrics()`を呼び出す
- 応答をJSONとしてデコードし、`derived_metrics`配列を返す
  (この時点では**個々のdefinitionの意味検証は行わない** —
  それは`CalculateDerivedMetricsAction`の責務)

### Planning Context

```json
{
  "user_prompt": "各広告チャネルの成果を比較し...",
  "available_dimensions": ["channel", "region"],
  "available_measures": ["spend", "revenue", "conversions"],
  "available_aggregations": ["sum", "count", "avg"],
  "max_derived_metrics": 5
}
```

`aggregated_metrics`の実際の集計値(sum/count/avg等)はPlanning ContextとしてAIへ
**一切送信しない**。AIは「revenueとspendという列が存在する」ことは知っているが、
「revenueの合計がいくつか」は知らない状態でderived metricsを提案する。

`max_derived_metrics`は`config('derived_metrics.max_derived_metrics')`から
取得した実際の値をそのまま渡す。System Instruction側に固定値をハードコード
しない(§11参照)。

### System Instruction

- 提案件数は`max_derived_metrics`(Planning Contextで渡された実際の値)まで。
  System Instructionの文面自体には固定の数値を書かない
- ユーザーの質問に明確に関連する派生指標のみ提案する
- 全measureの組み合わせを網羅的に生成しない
- **operand(`left`/`right`)が参照する`metric` / `aggregation` / `group_by`は、
  対応する`available_*`リストに実在する値のみを使用する**。存在しない値を
  発明してはならない
- 一方、派生指標自身の`name`は`available_*`から探すものではなく、AIが
  新しく作る識別子である。`name`は「何を表す指標か」が分かる一意な
  identifierにする(例: `revenue_per_spend`, `conversions_per_spend`,
  `revenue_per_conversion`, `conversion_rate`, `click_through_rate`)。
  `revenue`や`spend`のように入力measure名をそのまま`name`として使うことは
  禁止し、レスポンス内の全派生指標で`name`が重複しないよう明示的に指示する
  (§6「name一意性」参照)
- `operator`は指標の意味に応じて選択する(§5「rate / ratioの使い分け」参照)。
  名前のパターンだけで機械的に決めない
- 有用な派生指標がなければ空リストを返してよい(空リストは正しい回答になり得る)

### AIを呼ばないケース

`aggregated_metrics`にdimensionまたはmeasureが1件も無い場合、Planningする対象が
存在しないため、AI呼び出し自体を行わない(`MetricAggregationAction`の
「何もなければCSVすら読まない」という設計と同じ考え方)。

---

## 4. CalculationDefinition Schema

```json
{
  "name": "ROAS",
  "operator": "divide",
  "left": { "metric": "revenue", "aggregation": "sum" },
  "right": { "metric": "spend", "aggregation": "sum" },
  "group_by": "channel"
}
```

| フィールド | 型 | 制約 |
|---|---|---|
| `name` | string | `^[A-Za-z][A-Za-z0-9_]{0,63}$` |
| `operator` | enum | `divide` / `multiply` / `add` / `subtract` / `percentage` のみ |
| `left` / `right` | Operand | `{ "metric": string, "aggregation": "sum"\|"count"\|"avg" }` の2階層のみ |
| `group_by` | string | **必須**。`aggregated_metrics.dimensions[].dimension`に実在する値のみ |

**ネスト式は許可しない。** 派生指標を別の派生指標の`left`/`right`として参照する
ことはPhase 2ではできない(`derived metric → derived metric`の禁止)。

---

## 5. Allowed Operators

allow-listは以下5つのみ。

```text
divide
multiply
add
subtract
percentage
```

`CalculateDerivedMetricsAction`はこれを`match`文で実行する。**自由なformula
文字列の解釈・`eval`・expression parser・動的関数呼び出しは一切行わない。**
`operator`の値は5つの固定分岐のうちどれを実行するかを選ぶだけであり、
コードになることはない。

### percentageの定義

```text
percentage(left, right) = (left / right) * 100
```

構成比(grand totalに対する割合)などの別の意味は持たせない。将来別の意味が
必要になった場合は、既存の`percentage`を変更せず、新しいoperatorとして追加する。

### rate / ratioの使い分け

`CalculateDerivedMetricsAction`自体はoperatorの「意味」を判断しない
(`divide`と`percentage`はどちらも安全に実行されるだけの操作である)。
どちらを使うべきかは、Planning AIが派生指標の**意味**に応じて選択する。

| 指標の性質 | operator | 例 |
|---|---|---|
| rate / percentage系(比率を%で表現するのが自然) | `percentage` | `conversion_rate = conversions / clicks`、`click_through_rate = clicks / impressions` |
| ratio / per-unit efficiency系(単位あたりの値として表現するのが自然) | `divide` | `revenue_per_spend = revenue / spend`、`revenue_per_conversion = revenue / conversions` |

`PlanDerivedMetricsAction`のSystem Instruction(§3)は、`name`のパターン
(例:「`_rate`で終わるから`percentage`」)だけで機械的に判断しないよう、
上記の例を挙げて意味に基づく選択を明示的に指示する。最終的にどちらを選ぶかは
AIの判断であり、Laravel側は選ばれた`operator`をallow-list検証した上で
そのまま実行する(§6)。

---

## 6. Validation(CalculateDerivedMetricsAction)

各`CalculationDefinition`を独立に検証する。1件が不正でも他の有効な定義の処理は
継続する。

| 検証項目 | 失敗時のreason |
|---|---|
| `name`が安全なidentifierでない | `invalid_name` |
| `operator`がallow-list外 | `invalid_operator` |
| `group_by`が欠落/空文字 | `missing_group_by` |
| `group_by`が`aggregated_metrics.dimensions[]`に実在しない | `unknown_group_by` |
| `left`/`right`の形が不正(object以外、`metric`/`aggregation`欠落) | `invalid_operand` |
| `metric`が`aggregated_metrics.measures`に存在しない | `unknown_metric` |
| `aggregation`が`sum`/`count`/`avg`以外 | `invalid_aggregation` |
| Plan内での出現位置が`max_derived_metrics`を超過 | `limit_exceeded` |
| 同一Calculation Plan内で`name`が既に(他の有効な定義で)使われている | `duplicate_name` |

OpenAIのStructured Output(`enum`制約)によって`operator`/`aggregation`は
API応答の時点である程度絞り込まれるが、これを**唯一の防御とはしない**。
Structured Outputは「そのデータセットに実在する列名か」までは保証できないため、
Laravel側での意味検証は常に独立して行う。

### name一意性(重複検出)

`name`は、最終Analysis呼び出しでAIが`derived_metrics`内の個々の派生指標を
指し示すための識別子である。そのため同一Calculation Plan内で一意である
ことを保証する。

`PlanDerivedMetricsAction`のSystem Instruction(§3)がAIへ一意な`name`を
要求しているが、これは唯一の防御ではない。`CalculateDerivedMetricsAction`は
以下のルールで独立に重複を検出する。

- 各`name`について、**他の検証(operator/group_by/operand等)を通過した
  最初の定義**が採用される
- 同じ`name`を持つ、それ以降の定義は(他の検証を独立に通過していたとしても)
  `duplicate_name`として`rejected`へ記録される
- 他の理由(`unknown_metric`等)で既に`rejected`となった定義は、その`name`を
  「使用済み」として占有しない。つまり、無効な定義の後に同じ`name`を持つ
  有効な定義が現れた場合、その定義は正常に採用される

いずれの場合もJob全体は失敗しない(例外を投げない)。

---

## 7. Zero Division

`divide` / `percentage` で右辺(right)が0の場合:

- 例外を投げない
- `INF` / `NAN`を生成しない(PHPの`json_encode`は両方とも安全にエンコードできない)
- 結果は次の形にする:

```json
{ "value": "Display", "result": null, "reason": "division_by_zero" }
```

---

## 8. NULL Handling

`left`または`right`の解決値が`null`の場合(例: `aggregated_metrics`側で
`count = 0`のため`avg`が`null`になっているケース)、演算を行わず:

```json
{ "value": "Display", "result": null, "reason": "missing_operand" }
```

とする。**nullを0へ変換して計算を続行することはしない。** 数値の`0`自体は
正当な値として扱われ、`missing_operand`にはならない。

---

## 9. Rejected Definitions

無効なdefinitionは例外にせず、`derived_metrics.rejected`へ理由付きで記録する。

```json
{ "name": "Bogus", "reason": "unknown_metric" }
```

これにより、AIが何を提案し、そのうち何が採用/却下されたかを追跡できる
(将来的なデバッグ・Prompt改善のための可視性を確保する)。

---

## 10. group_by必須(nullを許可しない)

Phase 2では`group_by`を**必須**とする。`null`または存在しないdimensionは
`rejected`とする。

### grand total(全体集計)がPhase 2対象外である理由

現在の`aggregated_metrics`は「dimension × measure」でグループ化された値
**しか**保持しておらず、CSV全体のgrand total(グループ化なしの総合計)を
保持していない。

もし`group_by: null`を許可した場合、Laravel側にその値を安全に算出する材料が
ない。あるdimensionのgroupsを合算する方法もあり得るが、そのdimensionが
`MetricAggregationAction`の上限(`max_dimensions` / `max_cardinality_per_dimension`
/ `max_aggregated_rows`)超過で一部groupが除外されていた場合、合計が実際の
全体値と一致しない危険がある。

そのため、Phase 2では`group_by`を必須とし、grand totalが必要なケースは
`aggregated_metrics.overall`のような専用ブロックを追加するPhase 2.1として
切り出す(§16参照)。

---

## 11. max_derived_metrics

`config/derived_metrics.php`が**Single Source of Truth**である。

```php
'max_derived_metrics' => 5,
```

この値は以下の経路で一貫して使われる。

```text
config/derived_metrics.php
↓
PlanDerivedMetricsAction が Planning Context の
"max_derived_metrics" フィールドへそのまま埋め込む
↓
Planning AI が System Instruction("Propose no more than
max_derived_metrics derived metrics")に従って提案件数を決める
↓
CalculateDerivedMetricsAction が同じconfig値を上限として
Planの出現順で超過分を安全に除外する(例外を投げない)
```

**System Instructionの文面には固定の数値(`5`等)をハードコードしない。**
Instructionは「max_derived_metricsを超えないこと」とだけ述べ、実際の数値は
Planning Contextの`max_derived_metrics`フィールドから読み取らせる。これにより、
`config/derived_metrics.php`の値を変更するだけで、Planning AIへの指示と
Laravel側の実際の上限が常に一致する。

Planning AIへの指示は唯一の防御ではない。`CalculateDerivedMetricsAction`は
Planの出現順で`max_derived_metrics`を超えるdefinitionを`limit_exceeded`として
安全に除外する(例外を投げない)。AIがInstructionに従わなかった場合の
防御ラインとして機能する。

`AiAnalysisClient::planMetrics()`のStructured Output Schema自体には、
`derived_metrics`配列に対する`maxItems`のような固定件数制約を**設けていない**。
OpenAI Structured Output Schemaへ動的な値を埋め込む一貫した方法がなく、
埋め込んだとしてもconfig値との同期を別途保守する必要が生じるためである。
上限の実効的な担保はPlanning Contextでのガイダンスと
`CalculateDerivedMetricsAction`側の検証に一本化している。

---

## 12. AIを2回呼ぶ理由

1回目(Planning)でAIが「何を計算したいか」を宣言し、Laravelが計算した**後**
でなければ、2回目(Analyze)でその計算結果を解釈できない。同一リクエスト内で
「提案→計算→解釈」を完結させることはできない。

検討し、却下した代替案:

| 案 | 却下理由 |
|---|---|
| Tool Calling / Function Calling | ネットワーク的には結局複数往復が必要になり複雑さが増すだけ。会話状態管理が必要。`docs/product/AI_CONTEXT.md` §30で"Tool Calling"はV1対象外と明記されており、既存の`store: false`(状態を持たない)という設計方針からも逸脱する |
| Laravel側で機械的に生成(AI不要) | ユーザーの質問に基づく意味的な取捨選択ができない。無意味な組み合わせを大量生成しかねない |

→ 2つの独立したstructured output requestとする。`AiAnalysisClient`の
`analyze()`と同じ「単発リクエスト・単発structured output」パターンを
`planMetrics()`にも適用し、新しいAI機能(tool calling等)は導入しない。

`AiAnalysisClient::planMetrics()`は`analyze()`とHTTPの仕組み(認証チェック・
タイムアウト・ステータス/出力抽出・エラーメッセージ)をあえて共有せず、
並行した実装としている。`analyze()`には既存の広範なテストがあり、内部を
共有ロジックへ切り出すことはそこへ回帰バグを持ち込むリスクがあるため。

---

## 13. Token / Latency Tradeoff

呼び出しが2回になるため、コストは確実に増加する。

- **Planning呼び出し**: `aggregated_metrics`の実数値を送らないため、
  Analyze呼び出しよりかなり軽量。
- **Analyze呼び出し**: Phase 1の`aggregated_metrics`に加えて`derived_metrics`
  が追加される。件数は`max_derived_metrics`で上限管理される。

2回の呼び出しは**直列依存**(Analyzeの入力はPlanning結果に依存する)のため、
並列化できない。レイテンシは単純計算で従来のほぼ2倍になる。

実測値は「実装完了後のE2E確認」セクション(実装完了報告)を参照。

---

## 14. Security

- **eval禁止**: `CalculateDerivedMetricsAction`は`eval`・expression parser・
  動的関数呼び出しを一切使用しない。`operator`は`match`文による固定5分岐の
  どれかを選ぶだけで、コードとして実行されることはない。
- **allow-list operator**: 5つの演算のみ許可。
- **allow-list aggregation**: `sum`/`count`/`avg`のみ許可。
- **metric / group_by の実在確認**: `aggregated_metrics`に実際に存在する
  列・dimensionのみ参照可能。
- **Planning raw responseの非永続化**: Planning呼び出しのraw AI responseは
  DBへ保存しない。1回のJob実行中のみ利用する内部データとして扱う
  (§15参照)。

---

## 15. Planning Raw Responseの非永続化

`analysis_job_details.raw_response`は引き続き**最終Analysis呼び出しの
raw responseのみ**を保存する。Planning呼び出しの結果(提案された
`CalculationDefinition[]`、AI応答文字列そのもの)はDBスキーマを変更せず、
1回のAnalysisJob実行中だけ利用する内部データとして扱う。

理由:

- Phase 1の`docs/product/AI_ANALYSIS.md` §14が踏襲している
  「V1ではAI ContextをDBへ永続保存しない」という最小主義を維持する
- Planning結果の監査価値が実際に問題になった場合(再現性要件・デバッグ困難等)、
  改めてマイグレーションを検討する(Phase 2.1候補、§16)

---

## 16. Phase 2対象外

以下は今回実装しない。

```text
grand total / overall metrics
literal operands(定数オペランド)
nested derived metrics(派生指標の派生指標)
arbitrary formulas / eval / expression parser
tool calling / function calling
AI agent
budget allocation engine(来週予算配分)
analysis templates
recommendations schemaの変更
Controller / Routing / FormRequestの変更
Planning raw responseの永続化
```

---

## 17. Phase 2.1候補

将来必要になった時点で個別に検討する。

- `aggregated_metrics.overall`(grand total)ブロックの追加とnull group_byの解禁
- リテラル(定数)オペランドのサポート(例: target CPAとの比較)
- Planning / Derived Metricsの監査用永続化(専用テーブル、または
  `analysis_job_details`への列追加)
- `CsvDatasetReader`等のCSV読込共通コンポーネント抽出(Phase1の
  `docs/product/METRIC_AGGREGATION.md` §10で既に候補として言及済み)
- 来週予算配分(Budget Reallocation Engine): `derived_metrics`のROAS等を
  根拠に、AIが再配分方針を提案し、Laravel側が「合計が予算総額と一致する」
  ことを保証する実際の配分計算を行う
