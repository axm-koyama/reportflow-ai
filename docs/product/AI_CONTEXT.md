# AI Context Design

## 1. Overview

AI Context は、ReportFlow AI がユーザーの分析要求と Data Profiling 結果を
AI Provider に渡すための中間表現である。

ReportFlow AI V1 の基本フロー:

```text
DataFile
↓
DataProfilingAction
↓
Data Profile
        +
User Prompt
        +
System Instruction
        +
Output Schema
↓
BuildAnalysisContextAction
↓
AI Context
↓
AiAnalysisClient
↓
AI Provider
```

AI Context の目的は、
Data Profilingによって生成された客観的なデータ情報と
ユーザーの自然言語による分析要求を、
AI Providerへ安全かつ一貫した形で渡すことである。

---

## 2. Responsibility

`BuildAnalysisContextAction` の責務:

- System Instruction を提供する
- User Prompt を保持する
- Data Profile を保持する
- ReportFlow AI の Output Schema を付与する
- 上記を AI Context として構造化して返す

`BuildAnalysisContextAction` の責務外:

- CSV読込
- Data Profiling
- DataFile Storageアクセス
- AI API呼び出し
- AI Provider固有形式への変換
- AnalysisJob status更新
- DB更新
- AI Response normalization
- Report生成

責務分離:

```text
DataProfilingAction
= Data Facts

BuildAnalysisContextAction
= AI Request Context

AiAnalysisClient
= Provider Transport

NormalizeAnalysisResultAction
= Application Result Contract
```

---

## 3. Application Structure

作成先:

```text
app/
└── Actions/
    └── AnalysisJob/
        └── BuildAnalysisContextAction.php
```

V1では新たなDTO / ValueObject / Builderを作成しない。

まずは単一Actionで実装する。

---

## 4. Public Interface

基本interface:

```php
/**
 * @param array<string, mixed> $dataProfile
 * @return array<string, mixed>
 */
public function execute(
    string $prompt,
    array $dataProfile,
): array
```

入力:

- `prompt`
- `dataProfile`

出力:

- AI Context array

AnalysisJob / DataFile / AI Provider object は直接受け取らない。

---

## 5. Input Contract

### 5.1 User Prompt

`analysis_job_details.prompt` に保存された
ユーザーの自然言語による分析要求。

例:

```text
各広告チャネルの成果を比較し、
効率が悪いチャネルと、
来週の予算配分で改善すべきポイントを分析してください。
```

V1では User Prompt を
固定の analysis_type に変換しない。

以下も行わない:

- 自動翻訳
- 自動要約
- Prompt rewrite
- Templateへの強制変換

ユーザーの入力内容を分析意図のSource of Truthとして扱う。

---

### 5.2 Data Profile

`DataProfilingAction::execute()` の出力を受け取る。

基本構造:

```json
{
  "file": {
    "name": "campaign.csv",
    "row_count": 1200,
    "column_count": 6
  },

  "columns": [],

  "numeric_statistics": [],

  "categorical_summaries": [],

  "sample_rows": []
}
```

BuildAnalysisContextAction 内では Data Profile を再計算しない。

以下を行わない:

- column再推定
- statistics再計算
- sample再取得
- category再集計
- Data Profile内容の削除
- Data Profile内容の追加分析

---

## 6. Output Contract

> **Phase 1 / Phase 2 / Phase 3-A で更新**: `BuildAnalysisContextAction` は
> このAI_CONTEXT.mdが最初に構想した4-key contractに加え、Phase 1で
> `aggregated_metrics`(docs/product/METRIC_AGGREGATION.md)、Phase 2で
> `derived_metrics`(docs/product/DERIVED_METRICS.md)、Phase 3-Aで
> `analysis_template` / `column_mapping`
> (docs/product/ANALYSIS_TEMPLATE_MODULE.md)を追加している。以下は
> 現在の実際のcontract。
>
> **Phase 3-C で更新**: `BuildAnalysisContextAction`自身は無変更だが、
> `ExecuteAnalysisJobAction`が渡す`column_mapping`は、手動Mapping確認
> を経由したTemplate Jobでは`effective_column_mapping`(Manual >
> Validated AI Mapping)由来のsimple dictになる。AI Contextの`column_mapping`
> が常に「実際にPlanning/Calculationで使われたのと同じFact」であること
> を保証するための変更で、`BuildAnalysisContextAction`のcontract自体は
> 変わらない。詳細はdocs/product/MAPPING_CONTROL.mdを参照。

`BuildAnalysisContextAction` は以下の構造を返す。

```php
[
    'system_instruction' => string,
    'user_prompt' => string,
    'data_profile' => array,
    'aggregated_metrics' => array,
    'derived_metrics' => array,
    'analysis_template' => array|null,
    'column_mapping' => array,
    'output_schema' => array,
]
```

このarrayを `AI Context` と呼ぶ(これは`AiAnalysisClient::analyze()` —
最終分析呼び出し — 用のAI Contextである。Phase 2ではこれとは別に、
`PlanDerivedMetricsAction`が構築する、より小さな Metric Planning Context
が存在する。詳細はdocs/product/DERIVED_METRICS.md §3を参照)。

---

## 7. AI Context Example

例:

```php
[
    'system_instruction' => '...',
    'user_prompt' => 'Which channels are performing best?',
    'data_profile' => [
        'file' => [
            'name' => 'campaign.csv',
            'row_count' => 1200,
            'column_count' => 6,
        ],
        'columns' => [],
        'numeric_statistics' => [],
        'categorical_summaries' => [],
        'sample_rows' => [],
    ],
    'aggregated_metrics' => [
        'dimensions' => [],
        'measures' => [],
    ],
    'derived_metrics' => [
        'metrics' => [],
        'rejected' => [],
    ],
    'analysis_template' => null, // or {name, instruction, recommended_derived_metrics} when a Template was used
    'column_mapping' => [], // or {semantic_field: real_column_name} when a Template was used
    'output_schema' => [
        // ReportFlow AI Result Schema
    ],
]
```

---

## 8. System Instruction

V1では以下を基本System Instructionとする。

```text
You are a professional business data analyst.

Analyze the supplied business data profile according to the user's request.

Rules:

1. Use only the information provided in the supplied data profile.

2. Do not invent facts, metrics, trends, causes, relationships, or events
   that are not supported by the supplied data.

3. Clearly distinguish observed facts from interpretations.

4. If the supplied data is insufficient to answer part of the user's request,
   clearly state that the available context is insufficient.

5. Do not claim row-level facts that are not represented in the supplied
   sample rows, statistics, or summaries.

6. Recommendations must be logically connected to observed data.

7. Prefer concise, decision-oriented business analysis.

8. Do not assume business context that was not provided by the user or data.

9. When a requested conclusion cannot be supported by the available data,
   explain what additional data would be required.

10. Return only the required structured output.
```

---

## 9. System Instruction Principles

System Instruction の目的は、
AIに「もっと賢く分析させる」ことではなく、
分析結果の安全性・再現性・一貫性を高めることである。

特に以下を重視する。

### Evidence-based Analysis

AIは supplied data に基づく内容のみ断定する。

### No Hallucination

Data Profile に存在しない値や傾向を生成しない。

### Observation vs Interpretation

例:

```text
Observation:
Tokyo has the highest recorded sales in the supplied summary.

Interpretation:
This may indicate stronger demand in Tokyo.
```

InterpretationをFactとして表現しない。

### Insufficient Context

分析できない場合は推測せず、
不足している情報を明示する。

---

## 10. Important Limitation

V1では元CSV全体をAIへ送信しない。

AIが利用できる情報は基本的に以下のみ。

```text
File Metadata
Column Information
Numeric Statistics
Categorical Summaries
Sample Rows
User Prompt
```

そのため例えば:

```text
customer_id = 12345 の全取引履歴を分析してください
```

と要求されても、
Data Profile内にcustomer_id 12345の詳細データが存在しなければ、
AIはその履歴を推測してはいけない。

期待される応答:

```text
The supplied data profile does not contain enough row-level information
to analyze the full transaction history of customer_id 12345.
```

---

## 11. Result Schema Overview

AI Providerには、
ReportFlow AI V1 の標準Result Schemaに沿った出力を要求する。

基本構造:

```json
{
  "summary": "string",
  "highlights": [],
  "metrics": [],
  "tables": [],
  "insights": [],
  "recommendations": []
}
```

この構造は provider-neutral とする。

OpenAI / Anthropic 等のAPI固有schema形式ではない。

---

## 12. Summary

必須。

型:

```json
"summary": "string"
```

目的:

分析結果全体のExecutive Summary。

例:

```json
{
  "summary": "Paid Search generated the highest conversion volume, while Display showed lower efficiency relative to spend."
}
```

---

## 13. Highlights

型:

```json
"highlights": [
  "string"
]
```

目的:

重要な発見を簡潔に列挙する。

例:

```json
{
  "highlights": [
    "Paid Search has the highest conversion volume.",
    "Display has the highest spend but lower relative efficiency."
  ]
}
```

---

## 14. Metrics

型:

```json
"metrics": [
  {
    "label": "string",
    "value": "string",
    "unit": "string|null",
    "change": "string|null"
  }
]
```

用途:

レポート上部のKPI表示。

例:

```json
{
  "label": "Highest Revenue Channel",
  "value": "Paid Search",
  "unit": null,
  "change": null
}
```

`value` はV1ではstringとする。

理由:

以下のような様々な値を柔軟に扱うため。

- 数値
- 金額
- 比率
- ラベル
- 日付
- カテゴリ名

---

## 15. Tables

型:

```json
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
]
```

目的:

分析結果の根拠となる比較表・ランキング等を表現する。

例:

```json
{
  "title": "Channel Performance",
  "columns": [
    "Channel",
    "Spend",
    "Conversions"
  ],
  "rows": [
    [
      "Paid Search",
      "120000",
      "320"
    ],
    [
      "Display",
      "150000",
      "180"
    ]
  ]
}
```

V1ではすべてのcellをstringとして扱う。

---

## 16. Insights

型:

```json
"insights": [
  {
    "title": "string",
    "description": "string",
    "evidence": "string|null"
  }
]
```

目的:

データから読み取れる意味・解釈を表現する。

例:

```json
{
  "title": "Display efficiency appears weaker",
  "description": "Display spend is high relative to the conversion volume shown in the supplied data.",
  "evidence": "Display has the highest spend while its conversions are below Paid Search."
}
```

`evidence` は、
可能な限り Data Profile 内の観測事実を説明する。

---

## 17. Recommendations

型:

```json
"recommendations": [
  {
    "title": "string",
    "description": "string",
    "priority": "high|medium|low|null"
  }
]
```

目的:

ユーザーが次に取るべきActionを提示する。

例:

```json
{
  "title": "Review Display budget allocation",
  "description": "Consider testing a partial budget shift from Display toward channels with stronger observed conversion efficiency.",
  "priority": "high"
}
```

Recommendationsは推奨であり、
Factとして表現しない。

---

## 18. Recommendation Priority

許可値:

```text
high
medium
low
null
```

意味:

### high

ユーザーの意思決定に直接影響し、
観測データから優先度が高いと判断できるもの。

### medium

重要だが即時対応までは必要ないもの。

### low

補助的・探索的な改善提案。

### null

十分な根拠がなくpriorityを付けるべきでない場合。

> **Phase 4-B追記**: このschema(`recommendations[].priority`)自体は
> 後方互換性のため変更していない。ただしDecision-enabled AnalysisJob
> (`template_key`が`config/evaluation_metrics.php`にエントリを持つ)
> では、Final AnalyzeのSystem Instructionへ`recommendations`を空配列で
> 返すよう追加Ruleを付与しており、新規実行ではこのpriority自体が実質
> 出現しなくなる——将来的なLaravel deterministic priority formula
> (Phase 4-C以降のFuture Scope)との二重Source of Truthを避けるため。
> 過去に保存されたrecommendations(Free Analysis / sales_analysis /
> Phase 4-B以前のAnalysisJob)はUI上引き続き表示される。詳細は
> docs/product/DIAGNOSIS_ENGINE.md参照。

---

## 19. Provider-neutral Output Schema

`output_schema` は ReportFlow AI内部contractであり、
特定AI ProviderのJSON Schema記法には依存しない。

例:

```php
[
    'summary' => [
        'type' => 'string',
        'required' => true,
    ],

    'highlights' => [
        'type' => 'array',
        'items' => 'string',
    ],

    'metrics' => [
        'type' => 'array',
        'items' => [
            'label' => 'string',
            'value' => 'string',
            'unit' => 'string|null',
            'change' => 'string|null',
        ],
    ],

    'tables' => [
        'type' => 'array',
        'items' => [
            'title' => 'string',
            'columns' => 'string[]',
            'rows' => 'string[][]',
        ],
    ],

    'insights' => [
        'type' => 'array',
        'items' => [
            'title' => 'string',
            'description' => 'string',
            'evidence' => 'string|null',
        ],
    ],

    'recommendations' => [
        'type' => 'array',
        'items' => [
            'title' => 'string',
            'description' => 'string',
            'priority' => 'high|medium|low|null',
        ],
    ],
]
```

実際の内部配列形式は実装時に
読みやすさを優先して調整可能。

ただしReport Result Contract自体は維持する。

---

## 20. Provider Mapping

BuildAnalysisContextActionは
OpenAI / Anthropic等のProvider仕様を知らない。

例:

```text
BuildAnalysisContextAction
↓
Provider-neutral AI Context
↓
AiAnalysisClient
↓
OpenAI Responses API format
```

OpenAI Structured OutputsのJSON Schemaへの変換は
AiAnalysisClientまたは将来のprovider layer側の責務とする。

V1ではprovider abstractionを先行実装しない。

---

## 21. NormalizeAnalysisResultActionとの関係

AI Providerから返されたraw responseは:

```text
AI Provider
↓
raw_response
↓
NormalizeAnalysisResultAction
↓
result
```

となる。

`BuildAnalysisContextAction.output_schema` と
`NormalizeAnalysisResultAction` のvalidation contractは
可能な限り一致させる。

V1 Result:

```text
summary
highlights
metrics
tables
insights
recommendations
```

`NormalizeAnalysisResultAction` 側でも
この構造を検証する必要がある。

---

## 22. Recommendations Compatibility

現在のNormalizeAnalysisResultActionが
`recommendations` を扱っていない場合、
BuildAnalysisContextAction実装後の次Stepで対応する。

今回のBuildAnalysisContextAction実装時に
NormalizeAnalysisResultActionを同時変更しない。

責務単位でStepを分ける。

---

## 23. Empty Prompt

V1では、
User Promptの必須validationはHTTP Request / AnalysisJob作成処理側の責務とする。

BuildAnalysisContextActionは
既に有効なpromptが渡されることを基本前提とする。

そのためV1ではAction内部で:

```php
trim($prompt)
```

や

```php
if ($prompt === '') ...
```

等の入力変換・validationを追加しない。

もし既存Request側でprompt validationが存在しない場合は、
AnalysisJob UI実装時に別途対応する。

---

## 24. Data Profile Validation

BuildAnalysisContextActionは
DataProfilingActionのoutput contractを信頼する。

V1では以下を再validationしない。

- file存在
- columns存在
- statistics structure
- categorical structure
- sample rows structure

理由:

同一application内部で
DataProfilingAction直後に利用するため。

AI Provider境界で必要なvalidationが発生した場合は
別途検討する。

---

## 25. Cost Control

BuildAnalysisContextAction自体は
token計算やtruncateを行わない。

コスト制御は主に:

```text
DataProfilingAction
↓
Compact Data Profile
```

によって実現する。

V1では:

- 元CSV全件送信禁止
- Data Profileのみ送信
- unnecessary duplicationを避ける

AI Context内に同じData Profileを
複数形式で重複して持たせない。

---

## 26. Security

AI Contextには元CSV由来の以下が含まれる可能性がある。

- Sample Rows
- Categorical values
- File name

そのため外部AI Providerへ送信する情報として扱う。

V1では高度なPII maskingは実装しないが、
BuildAnalysisContextAction内で元CSVへ再アクセスしないことで
AI送信対象をData Profileに限定する。

将来:

- PII masking
- Sensitive column removal
- AI send policy

等を導入可能とする。

---

## 27. Error Handling

BuildAnalysisContextActionは基本的に
外部I/Oを行わないpure application transformationとする。

そのため通常は外部要因による例外は発生しない。

以下は行わない:

- Log
- Retry
- AnalysisJob Failed更新
- HTTP error handling
- Storage error handling

これらは上位/下位の責務とする。

---

## 28. Testing Strategy

最低限以下をpublic `execute()`経由でテストする。

### Output Structure

- system_instruction
- user_prompt
- data_profile
- output_schema

が存在する。

### User Prompt

入力promptと完全一致する。

### Data Profile

入力arrayと完全一致する。

### System Instruction

全文一致ではなく、
重要なルールが含まれることを確認する。

例:

- unsupported factsをinventしない
- insufficient data/contextを明示
- structured outputのみ返す

### Output Schema

以下が存在:

- summary
- highlights
- metrics
- tables
- insights
- recommendations

Recommendations:

- title
- description
- priority

Priority:

- high
- medium
- low
- null

### Independence

Actionが以下へ依存しない:

- DataFile
- AnalysisJob
- Storage
- HTTP Client
- AI Provider

private methodをReflectionで直接テストしない。

---

## 29. V1 Integration Flow

最終的なIntegration:

```text
ExecuteAnalysisJobAction
 │
 ├── AnalysisJob取得
 │
 ├── markProcessing()
 │
 ▼
DataProfilingAction
 │
 ▼
Data Profile
 │
 ▼
BuildAnalysisContextAction
 │
 ├── System Instruction
 │
 ├── User Prompt
 │
 ├── Data Profile
 │
 └── Output Schema
 │
 ▼
AI Context
 │
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
 ▼
markCompleted()
```

---

## 30. V1 Scope

V1対象:

- Provider-neutral AI Context
- System Instruction
- User Prompt
- Data Profile
- Structured Result Contract
- Recommendations
- Recommendation Priority

V1対象外:

- Prompt Templates
- Prompt Versioning
- Prompt Management UI
- Multi-language prompt rewrite
- Provider switching
- Multiple System Prompts
- User-selectable System Prompt
- RAG Context
- Knowledge Base Context
- Tool Calling
- AI Agent Context
- Multi-file Context
- Conversation History
- Automatic Intent Classification

---

## 31. Future Extensions

将来候補:

- Prompt Template
- Prompt Version
- Context Version
- Model-specific Context
- Multi-file Context
- RAG Context
- Organization-level System Rules
- Sensitive Data Rules
- Prompt Evaluation
- Context Snapshot
- Token Estimation
- Cost Estimation
- Provider-specific Optimization

---

## 32. Design Principle Summary

BuildAnalysisContextActionの基本原則:

> AIに何を送るかを、Provider実装から分離して定義する。

```text
DataProfilingAction
= What the data says

User Prompt
= What the user wants to know

System Instruction
= How the AI must behave

Output Schema
= How the AI must answer
```

これらを組み合わせるのが:

```text
BuildAnalysisContextAction
```

である。

V1では高度なPrompt EngineeringやAgent化を行わず、

```text
Reliable Data Context
+
Clear User Intent
+
Strict Analysis Rules
+
Stable Structured Output
```

を優先する。
