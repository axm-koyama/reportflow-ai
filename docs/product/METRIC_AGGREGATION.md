# Metric Aggregation Design

## 1. Overview

Metric Aggregation は、DataFile のCSV全体をLaravel側で正確に集計し、
「dimension(categorical column)× measure(numeric column)」単位の
sum / count / avg を AI へ渡すための機能である。

Phase 1 (今回実装分) の目的はただ一つ:

> 数値集計そのものをLLMに依存させず、アプリケーション側で正確に集計した
> データを作成し、その結果をAIが解釈・分析する構成にする。

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
Aggregated Metrics
↓
BuildAnalysisContextAction
↓
AI Context
↓
AiAnalysisClient
```

Data Profiling / AI Context / AI Analysis 全体の設計については以下を参照する。

```text
docs/product/DATA_PROFILING.md
docs/product/AI_CONTEXT.md
docs/product/AI_ANALYSIS.md
```

---

## 2. Background

V1のAI分析パイプラインでは、AIへ渡る行レベルの情報は
`sample_rows`(reservoir samplingによる少数のサンプル行)のみであり、
channelごとの正確な合計・平均等の集計は一切渡していなかった。

そのため、AIが「サンプル行に基づく比較」「チャネル別の完全集計が必要」と
自らの限界を申告する事例が観測された。

Metric Aggregationは、この問題を解決するために
DataProfilingAction(客観的なcolumn単位の統計)の後段に、
Laravelが正確に計算したdimension×measure集計を追加するものである。

---

## 3. Responsibility

`MetricAggregationAction` の責務:

- `DataProfilingAction` が生成した Data Profile から、dimension候補・
  measure候補を選定する
- CSVを再度streamingで読み込み、dimension × measure ごとの
  sum / count / avg を正確に計算する
- 上限値(§7)に基づき、安全にAggregated Metricsを絞り込む
- AI Contextとして利用可能なarrayを返す

`MetricAggregationAction` が行わないこと:

- ROAS / CPA / CVR 等の比率・派生指標の計算(Phase 2以降)
- AIが提案した数式の実行(Phase 2以降)
- 予算再配分の計算(Phase 2以降)
- 列名(`channel` / `campaign` / `spend` / `revenue` / `conversions` 等)
  に依存した判定
- Data Profile自体の再計算・変更(`DataProfilingAction` の出力はそのまま
  読み取るのみ)
- AI API呼び出し
- AnalysisJob status更新
- DBへの永続化

`DataProfilingAction` 自体にはAggregation責務を追加しない。
`DataProfilingAction` は引き続き
「Aggregationを行わない」という既存の責務(DATA_PROFILING.md §2)を維持する。

---

## 4. Application Structure

Phase 1では以下のActionを作成する。

```text
app/
└── Actions/
    └── DataProfiling/
        ├── DataProfilingAction.php
        └── MetricAggregationAction.php
```

`DataProfilingAction` と同じ `app/Actions/DataProfiling/` 配下に配置する理由:

- Metric Aggregationも `AnalysisJob` 固有の処理ではなく、DataFileそのものに
  対する客観的な集計処理である
- DATA_PROFILING.md §45 は Aggregation を "Profiling V2" として既に
  位置づけている
- 将来的にData Profile画面等、AnalysisJob以外からの再利用可能性がある

---

## 5. Public Interface

基本interface:

```php
/**
 * @param array<string, mixed> $dataProfile DataProfilingAction::execute() の出力
 * @return array<string, mixed>
 */
public function execute(DataFile $dataFile, array $dataProfile): array
```

`MetricAggregationAction` は `DataFile` と、その `DataFile` に対して
`DataProfilingAction` が生成した Data Profile の両方を受け取る。

- `DataFile` … 集計のためCSVを再度streamingで読み込むために必要
- `dataProfile` … dimension候補 / measure候補を選定するために利用する
  (列の `inferred_type` / `unique_count` / 数値統計の対象列)

`dataProfile` の内容そのものを再計算・変更することはない。

---

## 6. Dimension / Measure Selection

### 6.1 Dimension候補

Data Profileの `columns` から、以下をすべて満たす列をdimension候補とする。

- `inferred_type === 'string'`(DataProfilingActionが categorical
  summaryを生成する対象と同じ定義)
- `unique_count >= 2`(定数列はgroup-byする価値がないため除外)
- `unique_count <= max_cardinality_per_dimension`(§7)

列名(`channel` / `campaign` / `media` 等)には一切依存しない。

boolean型・数値型・date型の列はdimension候補にしない
(Phase 1のスコープはDataProfilingActionが既に "categorical" として
扱っているstring型の列に限定する。booleanをdimension化する拡張は
将来必要になった時点で別途検討する)。

候補は unique_count の昇順(値が少ない列を優先)でソートし、
上限 `max_dimensions` 件までを採用する。値が同数の場合はCSVの列順を
維持する(PHPのソートはstableなため)。

### 6.2 Measure候補

Data Profileの `numeric_statistics` に含まれる列
(DataProfilingActionが既に integer / decimal と推定した列)を
そのままmeasure候補とする。

列名(`spend` / `revenue` / `conversions` 等)には一切依存しない。

候補はCSV列順のまま、上限 `max_measures` 件までを採用する。

---

## 7. Limits

無制限の Dimension × Measure 集計は、CPU負荷・メモリ使用量・
AI Contextサイズ・OpenAI APIのtoken数の増加につながるため、
`config/metric_aggregation.php` で以下を管理する。

| キー | 初期値 | 意味 |
|---|---|---|
| `max_dimensions` | 5 | 集計対象とするdimension列の最大数 |
| `max_measures` | 10 | 集計対象とするmeasure列の最大数 |
| `max_cardinality_per_dimension` | 20 | dimension候補として許容する最大distinct値数 |
| `max_aggregated_rows` | 100 | 全dimension合計のgroup数の最大値 |

### 初期値の根拠

- **`max_dimensions = 5`**: channel / region / product category / segment /
  status など、実務上意味のある低カーディナリティのdimensionは
  1つのCSVにつき数個程度であることが多い。列数の多いCSVでAI Contextが
  際限なく膨らむことを防ぐ。
- **`max_measures = 10`**: spend / revenue / conversions / clicks /
  impressions のような数値指標は、1つのCSVに数個〜十数個程度存在する
  ことを想定し、余裕を持たせつつ最悪ケースを制限する。
- **`max_cardinality_per_dimension = 20`**: 一般的な業務dimension
  (〜20程度のchannel / region / product category)を許容しつつ、
  customer_id や transaction_id のような高カーディナリティ列を
  dimension候補から確実に除外する。
- **`max_aggregated_rows = 100`**: `max_dimensions × max_cardinality_per_dimension`
  (5 × 20 = 100)と意図的に一致させている。デフォルト設定では
  この上限が実際に発動することはなく、あくまで
  「上2つの値を変更したが、この値の見直しを忘れた」ような設定ミスや、
  複数dimensionが同時に上限カーディナリティに達するような
  データ形状に対する最終的な安全弁として機能する。

### 上限超過時の挙動

上限を超えた場合、例外を投げず常に安全に絞り込む。

- `max_cardinality_per_dimension` 超過: そのdimension候補を丸ごと除外する
  (上位N件へのtruncateはしない。高カーディナリティ列の「代表値」は
  business dimensionとして意味を持たないため)
- `max_dimensions` 超過: cardinalityが低い候補から優先的に採用する
- `max_aggregated_rows` 超過: 優先順位(cardinality昇順)の順に
  dimensionを採用していき、予算を超える最初のdimensionはgroupを
  row count降順で上位から必要数だけ残し、それ以降のdimensionは
  丸ごと除外する

---

## 8. Output Contract

`MetricAggregationAction` は以下の構造を返す。

```php
[
    'dimensions' => [
        [
            'dimension' => 'channel',
            'group_count' => 2,
            'groups' => [
                [
                    'value' => 'Email',
                    'count' => 5,
                    'metrics' => [
                        'spend' => [
                            'sum' => 110000,
                            'count' => 5,
                            'avg' => 22000,
                        ],
                        'revenue' => [
                            'sum' => 4500000,
                            'count' => 5,
                            'avg' => 900000,
                        ],
                    ],
                ],
                // ...
            ],
        ],
        // ...
    ],

    'measures' => ['spend', 'revenue'],
]
```

- `dimensions[].dimension` … dimension列名
- `dimensions[].group_count` … 上限適用後に実際に含まれるgroup数
- `dimensions[].groups[].value` … dimension列のdistinct値
- `dimensions[].groups[].count` … そのgroupに属する行数
  (dimension値が存在する行数。measureの欠損有無に関わらずカウントする)
- `dimensions[].groups[].metrics[measure].sum/count/avg` … そのgroup・
  そのmeasureにおけるsum / count(非欠損のmeasure値の数) / avg
  (`sum / count`。countが0の場合は `null`)
- `measures` … 実際に採用されたmeasure列名の一覧

`groups` はrow count降順、同数の場合はvalueの昇順でソートされる。

dimension候補・measure候補が0件の場合は、CSVを読み込むことなく
`['dimensions' => [], 'measures' => []]`(または該当する方のみ空)を返す。

この構造を **Aggregated Metrics** と呼ぶ。

---

## 9. Null / Missing Value Handling

DataProfilingActionと同じ規約に従う(DATA_PROFILING.md §13)。

- dimension値が `null` または空文字の行は、そのdimensionのgroup化から
  除外する(「不明」groupを作らない)
- measure値が `null` または空文字の行は、そのmeasureの `sum` / `count`
  から除外する。ただし同じ行の他のmeasureや、そのgroup自体の `count`
  (行数)には影響しない
- 数値の `0` は欠損として扱わない。文字列としての `"0"` は
  DataProfilingActionと同じ厳密な整数/小数の正規表現で数値として
  パースされ、`sum` / `count` に正しく含まれる

---

## 10. 2-pass CSV Reading

Phase 1では、`DataProfilingAction` と `MetricAggregationAction` が
それぞれ独立にCSVをstreamingで読み込む、2-pass構成とする。

理由:

- DataProfilingActionは「Aggregationを行わない」という既存の責務を
  維持する必要があり(DATA_PROFILING.md §2)、Aggregation機能を
  同じstream passに混ぜ込むことはできない
- 10MBアップロード上限を前提とすると、2回のstreamingによるI/Oコストは
  許容範囲内と判断する
- まず正しく動くシンプルな実装を優先する

### 共有コンポーネントについて

両Actionのヘッダ読み込み・行streaming処理には、構造的な重複が存在する
(BOM除去・trim・`fgetcsv` iteration等)。

Phase 1では、この重複を吸収する `CsvDatasetReader` のような共有
コンポーネントを **あえて抽出しない**。理由:

- `MetricAggregationAction` はDataProfilingActionが既に検証済みの
  同一ファイルを読むため、CSV構造検証(重複ヘッダ・空ヘッダ名・
  行のcolumn数不一致等)を全て再実装する必要がなく、実際の重複量は
  見た目ほど大きくない
- Phase 1のスコープで新しい抽象化を導入すると、影響範囲が
  `DataProfilingAction`(既存の安定したAction)にまで及び、
  「既存機能への影響を最小限にする」という今回の方針に反する

3つ目の消費者が現れた場合、または重複が実際に保守上の問題になった
場合に、`CsvDatasetReader` 抽出をPhase 2以降で再検討する。

---

## 11. Numeric Parsing Consistency

`MetricAggregationAction` は、`DataProfilingAction` が列を
integer / decimal と推定する際に使ったものと同じ正規表現
(leading zeroを許容しないinteger pattern、標準的なdecimal pattern)を
使って、行ごとのmeasure値を数値へ変換する。

これは意図的な重複である。列が既に「distinct値が全てinteger/decimal
shapeに一致する」という前提でmeasure候補として選ばれているため、
異なるパースルールを使うと、DataProfilingActionの型推定結果と
MetricAggregationActionの実際の集計結果が食い違うリスクがある。
このロジックも将来 `CsvDatasetReader` 抽出時に併せて共通化する
候補となる。

---

## 12. Integration with ExecuteAnalysisJobAction

最終的な呼び出しイメージ:

```php
$dataProfile = $this->dataProfilingAction->execute(
    $analysisJob->dataFile,
);

$aggregatedMetrics = $this->metricAggregationAction->execute(
    $analysisJob->dataFile,
    $dataProfile,
);

$context = $this->buildAnalysisContextAction->execute(
    $analysisJob->analysisJobDetail->prompt,
    $dataProfile,
    $aggregatedMetrics,
);
```

`MetricAggregationAction` は `DataProfilingAction` の直後、
`BuildAnalysisContextAction` の直前に実行される。

---

## 13. AI Context / Responsibility Boundary with AI

`BuildAnalysisContextAction` はAI Contextに `aggregated_metrics` を
そのまま追加する(再計算・変更しない)。

System Instructionには以下のルールを追加する。

1. `aggregated_metrics` はLaravelがCSV全体から計算した確定値であり、
   推定値ではない。ground truthとして扱うこと。
2. `sample_rows` から数値を再計算・再推定しないこと。`sample_rows` は
   データの形式・意味を理解する補助としてのみ使用すること。
3. 数値の比較・集計に関する質問には、`sample_rows` からの推論ではなく
   `aggregated_metrics` の数値を優先して使用すること。

この責務分離は、既存ドキュメントが繰り返し強調している原則
(DATA_PROFILING.md §42, AI_ANALYSIS.md §25)をそのまま踏襲したものである。

```text
Laravel (DataProfilingAction + MetricAggregationAction)
= Facts / 正確な集計

AI
= Interpretation / Insights / Recommendations
```

---

## 14. Phase 1 Scope

### Phase 1対象

- Dimension候補の選定(categorical column、cardinality制限あり)
- Measure候補の選定(numeric column)
- dimension × measure ごとの sum / count / avg
- 上限値による安全な絞り込み(例外を投げない)
- AI Contextへの `aggregated_metrics` 追加
- System Instructionへの利用ルール追加

### Phase 1対象外

- ROAS / CPA / CVR 等の比率・派生指標
- AIが提案した数式のLaravel側実行
- 来週予算配分の提案・計算
- median / percentile / standard deviation 等の高度な統計
- 列名に基づくヒューリスティック
- `recommendations` schemaの変更
- Controller / Routing / FormRequestの変更
- Aggregated MetricsのDB永続化

---

## 15. Phase 2 (予定)

Phase 2では、以下を別ステップとして検討する。

### 15.1 AI提案式の実行

AIが「どの集計列とどの集計列を、どういう式で組み合わせたいか」を
提案し(例: `{"metric": "ROAS", "formula": "revenue_sum / spend_sum", "group_by": "channel"}`)、
Laravelがその式を正確に実行する。AIには四則演算をさせない。

これにより、ROAS / CPA / CVRのような比率指標を、列名にハードコードする
ことなく、任意のCSV・任意の業務ドメインに対応できる形で実現する。

### 15.2 予算再配分

Phase 15.1で得られる比率指標(ROAS等)を根拠に、AIが来週の予算配分案
(現在配分の維持を基本方針とした再配分)を提案し、Laravel側が
「合計が予算総額と一致する」ことを保証する実際の配分計算を行う。

### 15.3 その他

- `CsvDatasetReader` 等の共有CSV読み込みコンポーネント抽出の再検討
- Aggregated Metricsの妥当性検証(AIが返した `metrics.value` が
  `aggregated_metrics` の値と一致することの検証)
