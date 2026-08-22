# AI Analysis Design

## 1. Overview

ReportFlow AI のAI分析機能は、ユーザーがアップロードした業務データに対して、
自然言語で分析したい内容を指定し、AIが専門的な分析レポートを生成する機能である。

V1 の基本コンセプト:

> 業務データをアップロードし、分析したいことを自然言語で伝えるだけで、
> AIがデータを分析し、構造化された専門レポートを生成する。

基本フロー:

```text
DataFile
↓
Laravel Data Profiling
↓
AI Context生成
↓
User Prompt
↓
AI Analysis
↓
Structured Result
↓
Professional Report
```

---

## 2. Product Principle

ユーザーに固定の分析タイプを選択させない。

例えば以下のような分析タイプをシステム側で固定定義するのではなく、
ユーザーが自然言語で自由に分析内容を指定する。

- 売上傾向分析
- 顧客分類分析
- 異常値分析
- 商品分析
- 地域分析
- その他の業務分析

例:

```text
最近12ヶ月の地域別売上傾向を分析し、
売上が大きく低下している地域とその要因を分析してください。
```

同じ DataFile に対して、複数の AnalysisJob を作成可能とする。

```text
DataFile
├── AnalysisJob #1 地域別売上分析
├── AnalysisJob #2 高価値顧客分析
└── AnalysisJob #3 商品別売上分析
```

ユーザーが「何を分析するか」を決定し、
ReportFlow AI はその要求と利用可能なデータに基づいて分析結果を生成する。

---

## 3. Domain Model

既存DB設計を維持する。

### 3.1 analysis_jobs

主な項目:

- analysis_job_id
- data_file_id
- title
- status
- created_at
- updated_at
- deleted_at

`title` はユーザーが自由入力する分析タイトル。

例:

```text
2026年度 地域別売上分析
```

V1では `analysis_type` は持たない。

理由:

ReportFlow AI は固定された分析タイプではなく、
ユーザーの自然言語による自由分析を提供するため。

---

### 3.2 analysis_job_details

主な項目:

- analysis_job_id
- prompt
- raw_response
- result
- error_message
- started_at
- completed_at

#### prompt

ユーザーが入力した分析要求をそのまま保存する。

例:

```text
商品別の売上推移を分析し、
売上が急減している商品を特定してください。
```

#### raw_response

AI Provider から取得した原始レスポンスを保存する。

#### result

ReportFlow AI の Report Schema に正規化したJSONを保存する。

---

## 4. AI Input Architecture

原則として、アップロードされたCSV全体をそのままAIへ送信しない。

理由:

- Tokenコストの増加
- Context Windowの消費
- APIレスポンス時間の増加
- 不要なデータ送信
- 大容量CSVへの対応が困難
- AIに必要のない詳細データまで送信する可能性がある

代わりにLaravel側で Data Profiling を実行し、
AI分析に必要な情報をコンパクトな AI Context として生成する。

```text
CSV
↓
Laravel
↓
Data Profiling
↓
AI Context
↓
AI
```

V1では、Data Profilingによってデータ全体の基本的な構造・統計・代表値をAIへ提供する。

---

## 5. Data Profiling

Laravel側でCSVを解析し、
AIが分析に利用できるコンパクトなデータプロファイルを生成する。

V1では以下を対象とする。

- File Metadata
- Column Information
- Column Type Inference
- Numeric Statistics
- Categorical Summary
- Sample Rows

以下は Profiling V2 以降で対応を検討する。

- Aggregation
- Anomaly Candidates
- Median
- Percentiles
- Standard Deviation
- その他の高度な統計処理

---

## 6. File Metadata

ファイル全体の基本情報を取得する。

最低限以下を含む。

- name
- row_count
- column_count

例:

```json
{
  "name": "sales.csv",
  "row_count": 1000,
  "column_count": 5
}
```

---

## 7. Column Information

各columnについて最低限以下を取得する。

- name
- inferred_type
- non_null_count
- null_count
- unique_count

`inferred_type` の候補:

- string
- integer
- decimal
- date
- datetime
- boolean
- unknown

例:

```json
{
  "name": "sales_amount",
  "inferred_type": "integer",
  "non_null_count": 998,
  "null_count": 2,
  "unique_count": 450
}
```

### Type Inference

型推定は単一のcellだけで判断せず、
column内の複数のnon-null値を基に判断する。

例えば以下の値:

```text
00123
00456
00889
```

は数値として解釈できるが、
customer_id、postal_code、product_code 等である可能性がある。

leading zeroを持つ値など、
数値変換によって情報が失われる可能性がある場合は
stringとして扱うことを優先する。

V1では、column内の全distinct non-null valuesを型判定対象にする。
type inference用に別途sample上限を設けることはしない
（詳細は docs/product/DATA_PROFILING.md §15 を参照）。

V1では過度に複雑な型推定を行わない。

---

## 8. Null Handling

V1では以下をmissing valueとして扱う。

- null
- 空文字

以下の文字列を自動的にnullとして扱わない。

- NULL
- N/A
- #N/A
- -
- その他の業務固有値

理由:

これらは業務データ上の有効なカテゴリ値である可能性があるため。

業務固有のnullルールが必要になった場合は、
将来設定可能な仕組みを検討する。

---

## 9. Numeric Statistics

数値columnについて基本統計を算出する。

対象:

- integer
- decimal

V1では以下を算出する。

- count
- min
- max
- mean

例:

```json
{
  "column": "sales_amount",
  "count": 998,
  "min": 100,
  "max": 1500000,
  "mean": 45800.5
}
```

以下はV1対象外とし、将来対応を検討する。

- median
- standard deviation
- quartiles
- percentiles

理由:

V1ではstreaming処理との相性が良く、
低コストで算出可能な基本統計を優先する。

---

## 10. Categorical Summary

文字列・カテゴリcolumnについて、
出現頻度の高い値を取得する。

例:

```json
{
  "column": "region",
  "top_values": [
    {
      "value": "Tokyo",
      "count": 320
    },
    {
      "value": "Osaka",
      "count": 210
    }
  ]
}
```

全unique値をAIへ送信しない。

理由:

- AI Contextの肥大化防止
- Tokenコスト削減
- high-cardinality columnへの対応

V1では上位N件のみをAI Contextへ含める。

Nは固定値としてコードに分散させず、
設定値として管理可能な設計を検討する。

---

## 11. Sample Rows

AIがデータ構造と実際の値の意味を理解できるよう、
CSVから少量のsample rowsを取得する。

例:

```json
{
  "sample_rows": [
    {
      "date": "2026-01-01",
      "region": "Tokyo",
      "product": "Product A",
      "sales_amount": "120000"
    }
  ]
}
```

V1ではsample row数に上限を設定する。

想定:

```text
10〜20 rows程度
```

CSV全行をsampleとして送信しない。

また、単純にCSV先頭行だけを取得すると、
ソート順によって偏ったsampleになる可能性がある。

必要に応じて、streaming処理と組み合わせた
reservoir sampling 等の均等sample方式を利用する。

---

## 12. CSV Processing

Data Profilingでは、
原則としてCSV全体を一度にメモリへ読み込まない。

Laravel Storageからstreamとして読み込み、
行単位で処理する。

基本イメージ:

```text
Storage
↓
Stream
↓
CSV Header
↓
Row
↓
Row
↓
Row
↓
Statistics / Sample
```

目的:

- 大容量CSVでのメモリ使用量抑制
- 将来的なファイルサイズ拡大への対応
- 安定したQueue処理

V1でも以下の安全制限を設定可能な設計とする。

- 最大ファイルサイズ
- 最大column数
- 最大profiling row数
- 最大sample row数
- categorical summary上限

具体的な上限値は Data Profiling 実装設計で決定する。

---

## 13. AI Context

Data Profiling結果をAI入力用のJSONへ変換する。

V1の基本構造:

```json
{
  "file": {
    "name": "sales.csv",
    "row_count": 1000,
    "column_count": 5
  },

  "columns": [
    {
      "name": "sales_amount",
      "inferred_type": "integer",
      "non_null_count": 998,
      "null_count": 2,
      "unique_count": 450
    }
  ],

  "numeric_statistics": [
    {
      "column": "sales_amount",
      "count": 998,
      "min": 100,
      "max": 1500000,
      "mean": 45800.5
    }
  ],

  "categorical_summaries": [
    {
      "column": "region",
      "top_values": [
        {
          "value": "Tokyo",
          "count": 320
        }
      ]
    }
  ],

  "sample_rows": [
    {
      "date": "2026-01-01",
      "region": "Tokyo",
      "sales_amount": "120000"
    }
  ]
}
```

V1では以下をAI Contextへ含めない。

- aggregations
- anomaly_candidates
- median
- percentiles
- standard deviation

---

## 14. AI Context Persistence

V1ではAI ContextをDBへ永続保存しない。

```text
DataFile
↓
DataProfilingAction
↓
AI Context
↓
AiAnalysisClient
```

DataFileをSource of Truthとし、
AI ContextはDataFileから再生成可能な派生データとして扱う。

理由:

- DB肥大化を避ける
- Profiling Schema変更への柔軟性を維持する
- V1の実装を過度に複雑化しない

同じDataFileに対して複数のAnalysisJobを実行した場合、
V1では必要に応じてData Profilingを再実行することを許容する。

将来以下の要件が発生した場合は永続化を再検討する。

- Profiling結果の再利用
- Profiling処理コストが高い
- Data Profile画面
- Audit
- Reproducibility
- AI実行時点のContext Snapshot保存
- Profile Cache

---

## 15. AI Request

AI Request は主に以下の3要素から構成する。

```text
System Instruction
+
User Prompt
+
AI Context
↓
AI Provider
```

### 15.1 System Instruction

ReportFlow AI側で管理する。

主な責務:

- Business Data Analystとして分析する
- User Promptに従う
- supplied data以外の事実を捏造しない
- 観測事実と解釈を区別する
- 情報不足の場合は不足していることを明示する
- Structured Professional Reportを返す

基本原則:

```text
Use only the supplied data context.

Do not invent unsupported facts.

Clearly distinguish observed facts from interpretations.

State when the available data context is insufficient.

Base recommendations on observed data where possible.
```

System Instructionの具体的な文面は
AI Provider実装時に別途定義する。

---

### 15.2 User Prompt

`analysis_job_details.prompt` に保存された
ユーザー自由入力の分析要求。

例:

```text
最近12ヶ月の地域別売上傾向を分析し、
売上が大きく低下している地域とその要因を分析してください。
```

User PromptはReportFlow AI側で固定の分析タイプへ変換しない。

---

### 15.3 AI Context

DataProfilingActionによって生成した、
業務データの構造化されたProfileを渡す。

AI Providerは原則として元CSV全体ではなく、
このAI Contextを利用して分析する。

---

## 16. Report Result Schema

AIから取得する分析結果は、
ReportFlow AI共通Schemaへ正規化する。

V1:

```json
{
  "summary": "string",

  "highlights": [
    "string"
  ],

  "metrics": [
    {
      "label": "string",
      "value": "string",
      "unit": "string|null",
      "change": "string|null"
    }
  ],

  "tables": [
    {
      "title": "string",
      "columns": [
        "string"
      ],
      "rows": [
        [
          "string"
        ]
      ]
    }
  ],

  "insights": [
    {
      "title": "string",
      "description": "string",
      "evidence": "string|null"
    }
  ],

  "recommendations": [
    {
      "title": "string",
      "description": "string"
    }
  ]
}
```

このSchemaは、

```text
AI
↓
raw_response
↓
NormalizeAnalysisResultAction
↓
result
↓
HTML / PDF / Excel
```

の共通contractとして使用する。

---

## 17. Result Responsibilities

### raw_response

AI Providerから取得した原始レスポンスを保存する。

目的:

- Debugging
- Audit
- AIレスポンス確認
- Normalization再実行
- Report Schema変更対応

### result

`NormalizeAnalysisResultAction` によって生成された
ReportFlow AI標準Schema。

Report生成では原則として `result` を利用する。

以下の処理ではAI APIを再実行しない。

- HTML表示
- HTMLレイアウト変更
- PDF生成
- PDF再生成
- Excel生成
- Excel再生成

```text
raw_response
      ↓
Normalize
      ↓
result
 ├── HTML
 ├── PDF
 └── Excel
```

---

## 18. Cost Control

AI APIコスト削減を重要な設計要件とする。

以下を原則とする。

1. CSV全件をそのままAIへ送らない
2. Laravel側でData Profilingを実施する
3. Numeric StatisticsはLaravel側で算出する
4. Sample Rowsに上限を設定する
5. Categorical Valuesに上限を設定する
6. AI Context全体のサイズを制御する
7. 不要なデータをAI Contextへ含めない
8. 同一AnalysisJobのReport再生成ではAIを再実行しない
9. raw_response / resultを再利用する
10. AI Providerへの入力token量を可能な限り抑制する

将来的にはAI Providerから取得可能なusage情報を利用し、

- input tokens
- output tokens
- model
- estimated cost

等の記録・可視化を検討する。

V1でのDB保存要否はAI Provider実装時に判断する。

---

## 19. Accuracy Principle

Data Profilingによって生成したAI Contextは、
元データ全体そのものではない。

そのため、AIに渡していない情報について
AIが断定的な分析結果を生成してはいけない。

例えばユーザーが、

```text
customer_id = 12345 の全取引履歴を詳細に分析してください。
```

と要求した場合でも、
AI Contextにcustomer_id 12345の詳細データが含まれていなければ、
AIはその取引履歴を推測して生成してはいけない。

AIは以下のいずれかを返す。

- 現在のContextでは分析できない
- より詳細なデータが必要
- 現在確認可能な範囲のみ分析する

System Instructionにもこの原則を含める。

---

## 20. Security / Data Handling Principle

業務データを外部AI Providerへ送信するため、
AIへ送信する情報は必要最小限とする。

V1ではCSV全体を原則送信せず、
Data Profilingによって生成したAI Contextのみを送信する。

Sample Rowsには元データの実値が含まれる可能性があるため、
将来的には以下を検討する。

- Sensitive column detection
- PII masking
- Sample Row除外設定
- AI送信対象column選択
- Organization単位のAI利用ポリシー

V1ではこれらの高度な制御は対象外とするが、
将来拡張可能な構造を維持する。

---

## 21. V1 Scope

### V1対象

- CSV
- UTF-8
- Natural Language Prompt
- 同一DataFileに対する複数AnalysisJob
- File Metadata
- Column Information
- Column Type Inference
- Null / Non-null Count
- Unique Count
- Numeric Statistics
  - count
  - min
  - max
  - mean
- Categorical Summary
- Sample Rows
- Streaming CSV Processing
- AI Context生成
- Structured AI Response
- raw_response保存
- Result Normalization
- Professional Report用共通Result Schema

### V1対象外

- Excel input
- PDF input
- Image input
- Median
- Percentiles
- Standard Deviation
- Automatic Aggregation
- Anomaly Candidates
- Advanced Anomaly Detection
- Automatic Forecasting
- ML Model Training
- Huge Dataset Distributed Processing
- Arbitrary SQL Generation / Execution
- AI-generated Code Execution
- Multi-file Analysis
- AI Context Persistence
- Advanced PII Detection / Masking

---

## 22. Profiling V2

V1ではAI分析の基本フロー完成を優先し、
高度な集計・異常値候補抽出は実装しない。

### 22.1 Aggregation

> **実装済み(Phase 1)**: `MetricAggregationAction`
> (docs/product/METRIC_AGGREGATION.md)により、dimension × measureごとの
> sum / count / avgをLaravel側で正確に計算し、`aggregated_metrics`として
> AI Contextへ追加する機能を実装した。System Instructionにも、
> `aggregated_metrics`を確定値(ground truth)として扱い、`sample_rows`
> から数値を再計算しないというルールを追加している。
>
> 以下で述べる「User Promptに応じた動的なAggregation選択」は、
> Phase 1では実装していない。Phase 1はData Profileの列型情報のみに
> 基づく機械的な選定であり、User Promptの内容(Analysis Intent)は
> 一切参照しない。

ユーザーの分析要求に応じて、
Laravel側でAIに有用な集計データを生成する機能を検討する。

候補:

- date column × numeric column
- categorical column × numeric column
- categorical value counts

例:

```json
{
  "aggregation": {
    "group_by": "region",
    "metric": "sales_amount",
    "operation": "sum",
    "rows": [
      {
        "region": "Tokyo",
        "value": 12500000
      }
    ]
  }
}
```

全column組み合わせを自動生成しない。

将来的にはUser Promptの内容から必要なaggregationを判断し、
必要なデータのみ生成する方式を検討する。

```text
User Prompt
↓
Analysis Intent
↓
Required Aggregation
↓
Laravel Data Processing
↓
AI Context
↓
AI
```

> **Derived Metrics(Phase 2で実装済み)**: 上記の「Required Aggregation」を
> AIが直接判断してLaravelに指示する仕組みを、ROAS等の比率指標に限定した形で
> `PlanDerivedMetricsAction` + `CalculateDerivedMetricsAction`として実装した。
> AIは`{"operator": "divide", "left": {...}, "right": {...}, "group_by": ...}`
> という構造化された`CalculationDefinition`を提案し(自由な数式文字列ではない)、
> Laravelがallow-listされた5つの演算(`divide`/`multiply`/`add`/`subtract`/
> `percentage`)のみを`eval`なしで実行する。詳細はdocs/product/DERIVED_METRICS.mdを参照。
> AIへの入力はaggregated_metricsの2倍の呼び出し(Planning + 最終Analysis)を
> 必要とする点、およびgroup_byが必須でgrand total(全体集計)には対応していない
> 点が、上記で構想されていた汎用Aggregation機構との違いである。

---

### 22.2 Anomaly Candidates

Laravel側で統計的な異常値候補を抽出し、
AI Contextに追加する機能を検討する。

候補:

- IQR
- Extreme Deviation
- Sudden Large Change

Laravel側では「異常である」と断定しない。

あくまで `anomaly_candidates` としてAIへ提供し、
業務上の意味の解釈はAIに任せる。

高度な機械学習によるAnomaly Detectionは
必要性を確認した上で別途検討する。

---

### 22.3 Advanced Statistics

将来的に以下を追加可能とする。

- median
- standard deviation
- quartiles
- percentiles
- correlation

大容量データに対しては、
streaming処理・近似アルゴリズム・DB側集計等を含めて検討する。

---

## 23. Future Extensions

将来候補:

- Excel Support
- Data Profile Persistence
- Data Profile UI
- Data Profile Cache
- Prompt Templates
- Analysis History Comparison
- Multi-file Analysis
- Provider Switching
- User-selectable AI Models
- Model / Token Usage Tracking
- Cost Tracking
- Aggregation based on Analysis Intent
- Anomaly Candidate Detection
- Advanced Statistical Analysis
- Report Templates
- Scheduled Analysis
- Recurring Reports
- AI Context Snapshot
- Sensitive Data Detection
- PII Masking
- User-configurable Data Profiling Rules

---

## 24. V1 Processing Flow

V1の最終的な処理フローは以下とする。

```text
User
 │
 │ Upload CSV
 ▼
DataFile
 │
 │ Create Analysis
 │
 ├── title
 └── prompt
 │
 ▼
AnalysisJob
 │
 │ Queue
 ▼
ExecuteAnalysisJob
 │
 ▼
ExecuteAnalysisJobAction
 │
 ├── markProcessing()
 │
 ▼
DataProfilingAction
 │
 ├── File Metadata
 ├── Column Information
 ├── Type Inference
 ├── Numeric Statistics
 │     ├── count
 │     ├── min
 │     ├── max
 │     └── mean
 ├── Categorical Summary
 └── Sample Rows
 │
 ▼
AI Context
 │
 │ + System Instruction
 │ + User Prompt
 ▼
AiAnalysisClient
 │
 ▼
AI Provider
 │
 ▼
raw_response
 │
 ▼
NormalizeAnalysisResultAction
 │
 ▼
result
 │
 ├── summary
 ├── highlights
 ├── metrics
 ├── tables
 ├── insights
 └── recommendations
 │
 ▼
markCompleted()
 │
 ▼
Professional Report
 ├── Web / HTML
 ├── PDF
 └── Excel
```

---

## 25. Design Principle Summary

ReportFlow AI V1では以下を最優先する。

> ユーザーが業務データをアップロードし、
> 自然言語で分析したい内容を指定するだけで、
> AIが構造化された専門レポートを生成できること。

V1では高度な統計機能を先に作り込まず、

```text
DataFile
↓
Data Profiling
↓
Natural Language Prompt
↓
AI Analysis
↓
Structured Result
↓
Professional Report
```

というEnd-to-Endの基本体験を完成させる。

Data ProfilingはAIの代わりに分析判断を行うものではない。

Laravelは客観的・再現可能なデータ情報を生成し、
AIはUser Promptに基づいてその情報を解釈する。

```text
Laravel
= Data Processing / Facts

AI
= Interpretation / Analysis / Recommendations

ReportFlow AI
= Structured Professional Report
```

この責務分離をV1の基本設計とする。
