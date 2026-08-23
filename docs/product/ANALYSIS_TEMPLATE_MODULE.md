# Analysis Template Module

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

### 対象外(§13/§14参照)

- 売上分析Template(Phase 3-B、実装済み — §13/§14参照)
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

### 9.2 group_by Exact-Copy Rule / Dynamic Enum(Phase 3-B検証で追加)

`sales_analysis`の実OpenAI API E2Eで、Planning AIが実列名「分類」の
代わりにTemplateのfield label「カテゴリ」を`group_by`として提案し、
`CalculateDerivedMetricsAction`に`unknown_group_by`としてrejectされる
事象が観測された。§9.1のfilteringとは別種の問題であり
(filteringはhint自体を渡す前の絞り込み、こちらはPlanning AIが
`group_by`という値そのものを言い換えてしまう問題)、`ad_performance`/
`sales_analysis`を問わず起こり得るFramework共通の課題として、以下の
2箇所を改善した。Sales固有の分岐は追加していない。

1. **`PlanDerivedMetricsAction`のSystem Instruction Rule 4a**:
   `group_by`は`available_dimensions`の値をcharacter-for-characterで
   exact copyすること、翻訳・言い換え・同義語・Template field labelへの
   変換は禁止であることを明示。
2. **`AiAnalysisClient::planMetrics()`のStructured Output Schema**:
   `derivedMetricsPlanSchema()`が、そのリクエストの
   `available_dimensions`をそのまま`group_by`の`enum`制約として組み込む
   ようになった。OpenAI Responses API自身が、実在しない値
   (Template field labelを含む)を`group_by`として返すこと自体を
   構造的に拒否する。

`CalculateDerivedMetricsAction`の`unknown_group_by`検証は変更していない
— 上記2つはPlanning AIが誤った値を**提案する頻度を下げる**ための対策で
あり、Laravel側の最終防御を置き換えるものではない。`group_by`は
Phase 2から一貫して必須・null不可(docs/product/DERIVED_METRICS.md
§10「group_by必須(nullを許可しない)」参照)であり、今回の`enum`追加も
この制約を緩めていない(nullを`enum`へ加える等は行っていない)。詳細は
docs/product/DERIVED_METRICS.mdの該当箇所を参照。

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

## 13. Phase 3-B: 売上分析Template(実装済み)

- 売上分析Template(`sales_analysis`)を追加した(§14)
- `sales_analysis`自体は、既存のTemplate Framework上で
  `config/analysis_templates.php`への追加のみで実装できた。
  `ResolveAnalysisTemplateAction` / `MapAnalysisTemplateColumnsAction` /
  `ValidateColumnMappingAction` / `CalculateDerivedMetricsAction` /
  `BuildAnalysisContextAction` / `ExecuteAnalysisJobAction` /
  `MetricAggregationAction`にSales固有の条件分岐は一切追加していない —
  Phase 3-Aで構築したTemplate Frameworkが広告分析専用ではなく、別業務
  (売上分析)でも再利用できることの実証でもある。
- ただし実OpenAI API E2Eによる検証過程で、`sales_analysis`固有ではない
  **Planning AIの汎用的な課題**が見つかった: Planning AIが実列名
  「分類」の代わりにTemplateのfield label「カテゴリ」を`group_by`として
  提案し、`CalculateDerivedMetricsAction`に`unknown_group_by`として
  rejectされる事象が観測された(計算結果自体が誤るわけではないが、
  本来算出できたはずのDerived Metricsが欠落する)。これを受けて、
  `PlanDerivedMetricsAction`のSystem Instruction(group_byは
  available_dimensionsの値をcharacter-for-characterでexact copyする、
  翻訳・言い換え・Template field labelへの変換は禁止)と、
  `AiAnalysisClient::planMetrics()`のStructured Output Schema
  (`group_by`をその場のavailable_dimensionsに基づく動的enumで制約)を
  **Framework共通の改善**として追加した。この2箇所は
  `ad_performance`/`sales_analysis`を問わず全Templateに効くFrameworkの
  信頼性改善であり、Sales固有の分岐ではない。詳細は§9.2・
  docs/product/DERIVED_METRICS.md「group_by」の項を参照。
- `date`(temporal)フィールドの扱いには制約がある。詳細は§15を参照。

---

## 14. Sales Template(`sales_analysis`、実装済み)

```php
'sales_analysis' => [
    'name' => '売上分析',
    'description' => '商品・カテゴリ・店舗・地域・顧客など、データに存在する切り口で売上規模と構成を分析します。',
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
    'instruction' => '商品・カテゴリ・店舗・地域・顧客など、実際に利用可能な集計切り口を分析対象とし、売上の規模と構成における特徴的な傾向や偏りがあれば指摘し、可能であれば改善点も示してください。日付列が存在する場合、日付情報を含むデータであることの認識には利用できますが、このPhaseでは日付範囲の確定、日付別・月別・週別などの時系列集計、前年比・前月比・前週比などの期間比較には使用しないでください。',
    'recommended_derived_metrics' => [
        ['name' => 'average_unit_price', 'left_field' => 'revenue', 'right_field' => 'quantity', 'operator_hint' => 'divide'],
        ['name' => 'average_order_value', 'left_field' => 'revenue', 'right_field' => 'orders', 'operator_hint' => 'divide'],
    ],
],
```

`description`/`instruction`のいずれも、`date`を「商品・カテゴリ・店舗・
地域・顧客」と並ぶ分析の切り口としては列挙していない。§15で述べる通り
`date`は現Phaseで集計軸として使われないため、AIへ渡すTemplate自身の
文言がその実態と矛盾しないようにしている。`instruction`は「日付情報を
含むデータであることの認識」までは許可しつつ、日付範囲の確定・
日付別/月別/週別の時系列集計・前年比/前月比/前週比の期間比較のいずれも
使用しないことを明示的に伝えている。「データの期間を把握できる」という
表現はあえて避けている — `sample_rows`(10〜20行のreservoir sample)
だけを根拠にfull datasetの日付範囲をAIが推測・断定してしまう余地を
残さないためである(§15参照)。

### required_fields / required_field_groups

`revenue`のみが`required_fields`。`required_field_groups`は空(`ad_performance`
と異なり「いずれか1つ」制約を持たない)。Sales CSVは形式が業種・顧客ごとに
大きく異なるため、`quantity`/`orders`/`product`/`category`/`store`/`region`/
`customer`/`date`は全てoptionalとし、実際にCSVへ存在してmapされたfieldだけが
分析の切り口として使われる(`instruction`もこの前提で「データに実際に存在
する切り口ごとに」という表現にしている)。この設計はValidateColumnMappingAction
の既存required/optional機構(§5.3)をそのまま使うだけで実現でき、Framework側の
変更は不要だった。

### average_unit_price と average_order_value の区別

`revenue / quantity`は商品単価(`average_unit_price`)であり、Business上の
AOV(客単価、`average_order_value`)ではないため区別している。AOVは
`revenue / orders`(注文数)として定義する。`quantity`または`orders`が
CSVに存在せずunmappedの場合、対応するrecommendationは
`ResolveAnalysisTemplateAction`のdeterministic filtering(§9.1)により、
Planning Contextへ渡る**前**に除外される。Planning AIがこのhintを見て
「参照先が無いから無視する」と判断することを期待する設計ではない —
Planning AIの指示追従には依存しない。このfilteringロジック自体は
`ad_performance`と完全に共通であり、Sales固有の分岐は一切追加していない。

### 5個のdimension fieldが同一候補プールを共有する点について

`product`/`category`/`store`/`region`/`customer`はいずれも`kind: dimension`
(`inferred_type === 'string'`)であるため、Column Mapping Contextでは
CSV内の同じ「文字列型列一覧」が5つのsemantic field全ての候補として渡る
(`ad_performance`の`channel`/`campaign`の2つより組み合わせが多い)。
候補の絞り込みロジック自体(型ベースのみ、列名ヒューリスティックなし)は
`ad_performance`と共通で変更していない。実際の意味的な振り分け精度は
AIのcolumn_candidates(ラベル・sample_values)からの判断に委ねられており、
実OpenAI API E2E(日本語CSV、商品名/分類/店舗名/地域を含むケース)で
実地検証している。

---

## 15. `date`(temporal)フィールドの制約(Phase 3-B時点)

Phase 3-Bでは、`date`はColumn Mapping対象のsemantic fieldとして完全に
サポートする(AIによるmapping、`column_mapping`/DB保存への記録まで)。
一方で、**集計(aggregation)への利用は今回のスコープに含めていない**。
理由と制約を明確にしておく。

### 何ができるか

- `date`(`kind: temporal`)をTemplateのfieldとして宣言できる
- `DataProfilingAction`が`inferred_type`を`date`/`datetime`と判定した列を、
  `ResolveAnalysisTemplateAction`の候補フィルタ(`temporal -> date/datetime`)
  が正しく候補としてAIへ渡す
- AIがcolumn_candidatesの中から実際の日付列を選び、`high`/`low`/`unmapped`
  で応答する
- `ValidateColumnMappingAction`による決定的な検証を経て、
  `column_mapping`(AI向け)・`column_mapping_for_storage`(DB保存用)の
  両方に他のfieldと全く同じ扱いで記録される

### 何ができないか(意図的なスコープ外)

- `date`/`datetime`と推定された列が`aggregated_metrics.dimensions`に
  現れることはない。`MetricAggregationAction::selectDimensions()`は
  `inferred_type === 'string'`の列のみをdimension候補とするため
  (`app/Actions/DataProfiling/MetricAggregationAction.php`)、`date`が
  Templateでどれだけ正しくmappedされていても、集計エンジンには一切
  接続されない
- 結果として、`date`を`CalculationDefinition.group_by`に指定した
  derived metric(例: 日別/月別のaverage_unit_price)は生成され得ない
  (Planning AIの`available_dimensions`にそもそも現れないため)
- 日次/週次/月次への丸め(bucketing)、YoY/MoM/WoW、前年比・前月比・
  前週比、Fiscal year処理、Forecastは実装しない
- `sample_rows`(10〜20行のreservoir sample)から時系列の数値傾向を
  再計算・推測することもしない — System Instruction Rule 12/13/17
  (aggregated_metricsが答えを持つ数値質問はsample_rowsから再計算しない)
  と矛盾するため
- CSV全体のmin/max日付(データ期間)をLaravel側で計算してAI Contextへ
  渡すことも行っていない。`sample_rows`は全行のごく一部のreservoir
  sampleでしかないため、そこに含まれる日付の最小値・最大値を「データ
  全体の期間」として断定することはできない。Template
  `instruction`(§14)が「日付情報を含むデータであることの認識」までしか
  許可しないのはこのため — 「期間を把握できる」とは書いていない

### なぜこの制約になるか

`MetricAggregationAction`はPhase 1で「dimension = カテゴリ列
(`inferred_type === 'string'`)」という前提で作られており、Phase 3-Bでは
この判定ロジックを変更しない方針とした。日次粒度の日付列(cardinalityが
非常に高い)をdimensionとして許可しても、`max_cardinality_per_dimension`
(既定20、`config/metric_aggregation.php`)を通常は超えるため実効性が薄く、
月次/週次への丸め機能なしでは中途半端になる。丸め機能自体は本格的な
Time-series Engineの領域であり、Phase 3-Bの意図的なスコープ外
(YoY/MoM/Forecast等)と一致する。

### Phase 3-Bにおける実質的な扱い

Phase 3-Bの`sales_analysis`は「売上構造分析」(商品・カテゴリ・店舗・
地域・顧客という**カテゴリ軸**の売上規模・構成分析)を対象とし、時系列
分析(トレンド・季節性・前期比較)は対象外とする。最終Analysis AIは
`data_profile`(`columns`/`sample_rows`)からデータが日付情報を含む事実
自体は認識できるが、Rule 12/13/17により、それを根拠に集計値なしの
定量的トレンドを断定することはできない — これは制限ではなく、既存の
Hallucination防止設計と一貫した挙動である。

### 将来拡張の候補: Temporal Aggregation / Trend Analysis

`date`/`datetime`型の列を実際に集計軸として使う場合、将来的に以下のような
専用の拡張(本Frameworkとは別モジュールとして切り出す想定)を検討する:

- 日付列の週次/月次/四半期への丸め(bucketing)
- 丸め後の期間をdimensionとして`aggregated_metrics`へ接続する仕組み
  (`MetricAggregationAction`の拡張、または専用の`TemporalAggregationAction`)
- 期間比較(前期比・前年比)を安全に計算するCalculation Engineの拡張
- Forecast/異常検知は本Frameworkの範囲外として、さらに別モジュールで検討

Phase 3-Bでは、これらの必要性が具体的な要件として確認されるまで
実装しない(過剰な一般化を避けるという既存方針を維持)。
