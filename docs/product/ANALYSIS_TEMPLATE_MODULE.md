# Analysis Template Module (Phase 3-A)

## 1. Purpose

Phase 1/2までは「CSVをアップロードし、自由にPromptを書く」分析方式のみを
提供していた。Phase 3では、Businessユーザーが分析目的をTemplateとして
選択できるようにし、CSVの列名の違い(顧客ごとに異なる`revenue`/`sales`/
`売上`等)をAIによるColumn Mappingで吸収した上で、Phase 1/2で構築した
汎用分析Engine(`DataProfilingAction` / `MetricAggregationAction` /
`PlanDerivedMetricsAction` / `CalculateDerivedMetricsAction`)へそのまま
接続する。

基本フロー:

```text
CSVをアップロード
↓
分析テンプレートを選択(または自由分析)
↓
必要な列を自動判定(Column Mapping)
↓
Phase 1 / Phase 2 の分析Engineを実行
↓
Business向け分析結果
```

自由分析(Free Analysis)は削除せず、`template_key = null`の場合は
Phase 2までと完全に同じコードパスを通る。

---

## 2. Phase 3-A Scope

### 対象

- Template Framework(config・Column Mapping・required判定の仕組み全体)
- 広告パフォーマンス分析Template(`ad_performance`)1件
- 自由分析との完全な後方互換

### 対象外(§15参照)

- 売上分析Template(Phase 3-B)
- Mapping確認UI・ユーザーによる手動Mapping修正
- Template DB管理・顧客別Template・Template version専用カラム
- Budget Allocation Engine・Report PDF・Charts・Tool Calling・AI Agent

---

## 3. Architecture Overview

```text
ExecuteAnalysisJobAction
 ├─ markProcessing()
 ├─ DataProfilingAction                    (無変更)
 ├─ MetricAggregationAction                (無変更)
 │
 ├─ [template_keyがある場合のみ]
 │   ResolveAnalysisTemplateAction
 │    ├─ config/analysis_templates.php 読込
 │    ├─ Column Candidate Filtering(型情報のみ、列名ヒューリスティックなし)
 │    ├─ MapAnalysisTemplateColumnsAction → AiAnalysisClient::mapColumns()  [AI call]
 │    ├─ ValidateColumnMappingAction(AI呼び出しなし、決定的validation)
 │    └─ Required不足なら例外 → Job Failed(既存lifecycle)
 │       → analysisTemplate / columnMapping を返す
 │
 ├─ PlanDerivedMetricsAction(拡張。analysisTemplate/columnMappingを追加受領) [AI call]
 ├─ CalculateDerivedMetricsAction          (無変更)
 ├─ BuildAnalysisContextAction(拡張。analysisTemplate/columnMappingを追加受領)
 ├─ AiAnalysisClient::analyze()            [AI call]
 ├─ NormalizeAnalysisResultAction          (無変更)
 └─ markCompleted()
```

自由分析(`template_key = null`)は`ResolveAnalysisTemplateAction`のブロック
全体をスキップし、AI呼び出しは従来通り2回(Planning + Analyze)のまま。
Template指定時のみ、Column Mappingの分だけAI呼び出しが3回になる。

---

## 4. Structured Context設計(重要な設計方針)

Template情報・Column Mappingは、Planning Context / 最終Analysis Context
へ**structured keyとして分離**して渡す。`user_prompt`へ埋め込んで
自然言語として渡すことはしない。

理由:

```text
Laravel = Facts / Validation / Deterministic Processing
AI      = Semantic Interpretation / Planning
```

Column Mappingは既にLaravel側で検証済みのFactであり、AIに文章として
読み取らせる対象ではない。`aggregated_metrics`/`derived_metrics`を
structured keyとして渡してきたPhase 1/2の設計と一貫させる。

`user_prompt`は常にユーザーが実際に入力した文字列のみを保持する
(Template Instructionを混ぜて書き換えることはしない)。

最終的なContext(`PlanDerivedMetricsAction`/`BuildAnalysisContextAction`
共通の考え方):

```php
[
    // ...既存キー...
    'analysis_template' => [
        'name' => '広告パフォーマンス分析',
        'instruction' => '広告チャネルごとの成果を比較し...',
        'recommended_derived_metrics' => [
            ['name' => 'return_on_ad_spend', 'left_field' => 'revenue', 'right_field' => 'spend', 'operator_hint' => 'divide'],
            // ...
        ],
    ], // 自由分析時は null
    'column_mapping' => [
        'channel' => '媒体',
        'spend' => '広告コスト',
    ], // 自由分析時は []
]
```

`analysis_template`はAI向けに整形済みの形(`name`/`instruction`/
`recommended_derived_metrics`のみ)であり、`required_fields`や`fields`
といったLaravel専用の情報は含めない。

---

## 5. Column Mapping

### 5.1 Candidate Filtering(Laravel側)

`ResolveAnalysisTemplateAction`が、Templateの各semantic fieldの`kind`と
`DataProfilingAction`の`inferred_type`を突き合わせて候補列を絞り込む。
列名ヒューリスティック(固定辞書)は一切使わない。

```text
dimension -> inferred_type === 'string'
measure   -> inferred_type in ['integer', 'decimal']
temporal  -> inferred_type in ['date', 'datetime']
```

各候補には`column`(実列名)・`inferred_type`・`sample_values`
(既存Data Profileの`sample_rows`から抽出。新規データ送信は行わない)を
含める。

### 5.2 AI Mapping(`MapAnalysisTemplateColumnsAction` → `AiAnalysisClient::mapColumns()`)

Mapping Contextには`user_prompt`・`template_fields`・
`column_candidates`(型フィルタ済み)のみを渡す。AIは各semantic fieldに
ついて、候補の中から最も適切な実列名を選び、`confidence`
(`high`/`low`/`unmapped`)とともに返す。列は日本語・英語を問わず、
意味で判断する(専用の対訳辞書は持たない)。

`AiAnalysisClient::mapColumns()`は既存`analyze()`/`planMetrics()`と
同じ「単発リクエスト・単発structured output」パターンを踏襲し、
Tool Calling/Agentは使用しない。

### 5.3 Validation(`ValidateColumnMappingAction`、AI呼び出しなし)

AIのconfidenceは最終判断ではない。以下を決定的に検証する。

**confidence方針**(「分からない場合は推測して分析しない」を優先):

| | Required | Optional |
|---|---|---|
| high | 使用 | 使用 |
| low | 使用しない(Required不足として扱う) | 使用しない |
| unmapped | 使用しない(Required不足として扱う) | 使用しない |

**duplicate / ambiguous mapping**: 同一実CSV列が複数semantic fieldへ
Mappingされた場合:

```text
high + low  -> highのみ採用、lowはignored
high + high -> 両方ambiguous、いずれも不採用
```

**unknown candidate**: AIが`column_candidates`に存在しない列を返した
場合、例外にせず`unmapped`へ強制する。

### 5.4 Status語彙(DB保存用、4種類のみ)

```text
mapped     使用される
unmapped   候補が無い/AIがunmappedと回答/未知の列
ambiguous  同一列を複数fieldがhigh confidenceで主張
ignored    有効な候補はあったが不採用(low confidenceまたは重複の敗者)
```

---

## 6. Required / Optional Fields

広告パフォーマンス分析(`ad_performance`):

```php
'required_fields' => ['channel'],
'required_field_groups' => [
    ['spend', 'revenue', 'conversions', 'clicks', 'impressions'], // 最低1つ必須
],
```

`channel`のみを直接必須とし、数値指標は「いずれか1つ」という
`required_field_groups`で表現する。これにより、広告費のみ・売上のみ・
コンバージョンのみ、といった様々なCSVでも「広告パフォーマンス分析」が
成立する。

---

## 7. DB保存

| テーブル | カラム | 内容 |
|---|---|---|
| `analysis_jobs` | `template_key`(string, nullable, indexed) | 使用したTemplateのkey。自由分析はnull |
| `analysis_job_details` | `column_mapping`(json, nullable, array cast) | `ValidateColumnMappingAction`が返す各fieldの`{column, confidence, status}` |
| `analysis_job_details` | `prompt`(既存、無変更) | ユーザーが実際に入力した文字列のみ。Template選択時は追加要望(空文字列も可) |

`prompt`の意味はPhase 2までと変わらない。Template Instructionを結合した
文字列を保存することはしない(Instruction自体は`config`から`template_key`
経由でいつでも再導出できるため、DBへ複製しない)。

`column_mapping`のDB保存形式とAI Contextへ渡す形式は異なる。DB保存形式は
全fieldを含む詳細な監査用レコード、AI Context用は`mapped`のfieldのみを
含む単純な`{field: column}`辞書へ`ResolveAnalysisTemplateAction`が変換する。

---

## 8. Free Analysis(自由分析)との互換性

- `template_key`は`analysis_jobs`のnullableカラム。未指定時は完全に
  Phase 2までの挙動。
- `CreateAnalysisJobRequest`: `template_key`が無い場合は`prompt`が
  必須(`required_without:template_key`)。既存の自由分析バリデーション
  ルールと完全に同じ結果になる。
- `ExecuteAnalysisJobAction`: `template_key === null`の場合、
  `ResolveAnalysisTemplateAction`のブロックごとスキップする(条件分岐で
  空値を渡すのではなく、呼び出し自体を行わない)。
- `PlanDerivedMetricsAction`/`BuildAnalysisContextAction`の新規引数は
  末尾のoptional引数(`?array $analysisTemplate = null, array $columnMapping = []`)
  のため、既存の呼び出し側・既存テストは無改修で動作する。

---

## 9. Derived Metrics Engineとの接続

`recommended_derived_metrics`はsemantic field名(例: `spend`)で
書かれたヒントであり、実列名ではない。Planning AI自身が
`column_mapping`を使って実列名へ翻訳し、`CalculationDefinition`を
構築する。Laravel側は翻訳を代行しない。

`CalculateDerivedMetricsAction`は一切変更していない。Template経由か
自由分析かに関わらず、渡された`CalculationDefinition`が
`aggregated_metrics`(実列名ベース)に対して妥当かを検証するだけである。

### 9.1 Recommended Derived Metrics Filtering(`ResolveAnalysisTemplateAction`)

E2Eで、`click_through_rate = clicks / impressions`という
recommendationに対し、`impressions`がunmappedだったにもかかわらず
Planning AIが`clicks / 広告コスト`(spend)へ無断で差し替え、名前は
`click_through_rate`のまま提案する事象が確認された。Laravel側の計算
自体は正確でも、指標名とBusiness上の意味が一致せず許容できない。

これを防ぐため、`recommended_derived_metrics`を各AIへ渡す前に
`ResolveAnalysisTemplateAction`が、直前に確定した`column_mapping`
(`ValidateColumnMappingAction`の検証結果)を使って**決定的に
filtering**する。

```text
recommendationを残す条件:
  left_field  の status === 'mapped' かつ column !== null
  right_field の status === 'mapped' かつ column !== null

どちらか一方でも unmapped / ignored / ambiguous / column = null
であれば、そのrecommendationごと除外する。
```

判定に使うのは`ValidateColumnMappingAction`が返す検証済みmapping
(§5.4のstatus語彙)のみであり、AIの申告や信頼度を再解釈することは
ない。`operator_hint`や`name`の中身は見ない — 参照している2つの
semantic fieldが実列として使える状態かどうかだけを見る。

**hintであってwhitelistではない**: このfilteringは
`recommended_derived_metrics`という「Templateからの提案」を絞り込む
だけであり、Planning AIが`available_measures`から独自に有用な
Derived Metricを提案すること自体は引き続き許可される
(`PlanDerivedMetricsAction`のSystem Instruction Rule 9)。

---

## 10. Token / API Call増加への影響

| | 自由分析 | Template分析 |
|---|---|---|
| AI呼び出し回数 | 2回(Planning, Analyze) | 3回(Mapping, Planning, Analyze) |

Mapping Contextは列名・型・少数のsample_valuesのみで、Planning Context
と同程度の軽量さを意図している。実測値は完了報告を参照。

---

## 11. Security

Column Mapping AIへ送るデータ(列名・inferred_type・sample values)は、
既存のData Profile/Planning Contextで既に外部AI送信対象となっている
データの範囲内であり、新しいデータカテゴリを追加するものではない
(`docs/product/DATA_PROFILING.md` §41参照)。

---

## 12. Failure UX

Required fieldが解決できない場合、専用のstatusやUIを追加せず、既存の
`AnalysisJobStatus::Failed` + `AnalysisJobDetail.error_message`を再利用
する。

```text
テンプレート「広告パフォーマンス分析」に必要な項目「チャネル」に
対応する列を確実に特定できませんでした。CSVの列名と内容をご確認ください。
```

Mapping確認UI・ユーザーによる手動修正はPhase 3-Aでは実装しない。結果
画面(成功時)には、実際に使用された列を読み取り専用で表示する
(`使用した列`セクション)。

---

## 13. Phase 3-B(予定)

- 売上分析Template(`sales_analysis`)の追加
- Field定義案(§14参照)
- Framework自体(Mapping/Validation/DB/UI)はPhase 3-Aのものをそのまま
  再利用する想定

---

## 14. Sales Template案(Phase 3-Bで実装予定、設計のみ確定)

```php
'sales_analysis' => [
    'name' => '売上分析',
    'description' => '売上推移、カテゴリ・店舗・地域別の売上を分析します。',
    'fields' => [
        'revenue'  => ['kind' => 'measure',  'label' => '売上'],
        'quantity' => ['kind' => 'measure',  'label' => '数量'],
        'orders'   => ['kind' => 'measure',  'label' => '注文数'],
        'product'  => ['kind' => 'dimension','label' => '商品'],
        'category' => ['kind' => 'dimension','label' => 'カテゴリ'],
        'store'    => ['kind' => 'dimension','label' => '店舗'],
        'region'   => ['kind' => 'dimension','label' => '地域'],
        'customer' => ['kind' => 'dimension','label' => '顧客'],
        'date'     => ['kind' => 'temporal', 'label' => '日付'],
    ],
    'required_fields' => ['revenue'],
    'required_field_groups' => [],
    'instruction' => '売上の推移・構成・上位/下位カテゴリを分析し、成長率や異常な変化があれば指摘してください。',
    'recommended_derived_metrics' => [
        ['name' => 'average_unit_price', 'left_field' => 'revenue', 'right_field' => 'quantity', 'operator_hint' => 'divide'],
        ['name' => 'AOV', 'left_field' => 'revenue', 'right_field' => 'orders', 'operator_hint' => 'divide'],
    ],
],
```

`revenue / quantity`は商品単価(`average_unit_price`)であり、Business上の
AOV(客単価)ではないため区別した。AOVは`revenue / orders`(注文数)として
定義する。`orders`がCSVに存在せずunmappedの場合、AOV recommendationは
`ResolveAnalysisTemplateAction`のdeterministic filtering(§9.1)により、
Planning Contextへ渡る**前**に除外される。Planning AIがこのhintを見て
「参照先が無いから無視する」と判断することを期待する設計ではない —
Planning AIの指示追従には依存しない。
