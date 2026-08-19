# Data Profiling Design

## 1. Overview

Data Profiling は、ReportFlow AI にアップロードされたCSVをLaravel側で解析し、
AI分析に必要な客観的・再現可能なデータ情報を生成する機能である。

Data Profiling 自体はAI分析を行わない。

責務は以下に限定する。

> DataFile のCSVを解析し、AIへ渡すためのコンパクトな構造化データを生成する。

基本フロー:

```text
DataFile
↓
DataProfilingAction
↓
Data Profile
↓
AI Context
↓
AiAnalysisClient
```

AI Analysis全体の設計については以下を参照する。

```text
docs/product/AI_ANALYSIS.md
```

---

## 2. Responsibility

DataProfilingAction の責務:

- DataFileのCSVをstreamとして読み込む
- CSV Headerを解析する
- row数 / column数を取得する
- columnごとの型を推定する
- null / non-null件数を集計する
- unique情報を集計する
- 数値columnの基本統計を計算する
- categorical columnの代表値を集計する
- sample rowsを取得する
- AI Contextとして利用可能なarrayを返す

DataProfilingAction が行わないこと:

- AI API呼び出し
- User Prompt解析
- AIによる分析判断
- Report生成
- AnalysisJob status更新
- DBへのProfile保存
- Aggregation生成
- Anomaly Detection
- Forecasting

---

## 3. V1 Scope

### V1対象

- CSV
- UTF-8
- Streaming CSV Processing
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

### V1対象外

- Excel
- Median
- Percentiles
- Standard Deviation
- Quartiles
- Correlation
- Automatic Aggregation
- Anomaly Candidates
- Advanced Anomaly Detection
- Forecasting
- ML Processing
- Multi-file Profiling

これらは必要性を確認した上で Profiling V2 以降で検討する。

---

## 4. Application Structure

V1では以下のActionを作成する。

```text
app/
└── Actions/
    └── DataProfiling/
        └── DataProfilingAction.php
```

Data Profiling は AnalysisJob 固有の処理ではないため、

```text
app/Actions/AnalysisJob/
```

配下には配置しない。

将来的に以下から再利用する可能性がある。

- AI Analysis
- DataFile Preview
- Data Profile画面
- Data Quality Check
- Import Validation

---

## 5. Public Interface

基本interface:

```php
/**
 * @return array<string, mixed>
 */
public function execute(DataFile $dataFile): array
```

DataProfilingAction は `DataFile` のみを受け取る。

以下は渡さない。

- AnalysisJob
- AnalysisJobDetail
- title
- prompt

理由:

Data Profiling はユーザーの分析要求とは独立した、
DataFileそのものの客観的Profileを生成する処理だからである。

```text
DataFile
   ↓
DataProfilingAction
   ↓
Data Profile

User Prompt
   ↓

Data Profile + User Prompt
   ↓
AiAnalysisClient
```

---

## 6. Output Contract

DataProfilingAction は以下の構造を返す。

```php
[
    'file' => [
        'name' => 'sales.csv',
        'row_count' => 1000,
        'column_count' => 5,
    ],

    'columns' => [
        [
            'name' => 'sales_amount',
            'inferred_type' => 'integer',
            'non_null_count' => 998,
            'null_count' => 2,
            'unique_count' => 450,
        ],
    ],

    'numeric_statistics' => [
        [
            'column' => 'sales_amount',
            'count' => 998,
            'min' => 100,
            'max' => 1500000,
            'mean' => 45800.5,
        ],
    ],

    'categorical_summaries' => [
        [
            'column' => 'region',
            'top_values' => [
                [
                    'value' => 'Tokyo',
                    'count' => 320,
                ],
                [
                    'value' => 'Osaka',
                    'count' => 210,
                ],
            ],
        ],
    ],

    'sample_rows' => [
        [
            'date' => '2026-01-01',
            'region' => 'Tokyo',
            'product' => 'Product A',
            'sales_amount' => '120000',
        ],
    ],
]
```

この構造を Data Profile と呼ぶ。

AI_ANALYSIS.md で定義する AI Context の基礎データとして利用する。

---

## 7. CSV Reading Strategy

CSV全体を以下のように一括読み込みしない。

```php
$content = Storage::get($path);
```

大容量CSVではファイルサイズに比例してPHPメモリを消費するためである。

V1ではstreaming方式を使用する。

基本イメージ:

```text
Storage
↓
readStream()
↓
Header
↓
Row 1
↓
Row 2
↓
...
↓
EOF
```

PHP標準のCSV parserを利用し、
行単位で処理する。

実装候補:

```php
$stream = Storage::readStream($dataFile->stored_path);

while (($row = fgetcsv($stream)) !== false) {
    // profiling
}
```

実際のDiskは既存DataFileのStorage設計に従うこと。

DataProfilingAction内にdisk名をハードコードしない。

---

## 8. Stream Lifecycle

取得したstreamは必ずcloseする。

例:

```php
$stream = Storage::readStream($path);

if ($stream === false) {
    throw new RuntimeException('Unable to open data file.');
}

try {
    // profiling
} finally {
    fclose($stream);
}
```

途中で例外が発生した場合もstreamをcloseする。

---

## 9. CSV Header

CSVの最初の行をHeaderとして扱う。

例:

```csv
date,region,product,sales_amount
```

↓

```php
[
    'date',
    'region',
    'product',
    'sales_amount',
]
```

HeaderはData Profileのcolumn定義に利用する。

---

## 10. Header Validation

最低限以下を検証する。

### Empty Header

Headerが存在しないCSVはprofilingできない。

例:

```text
empty file
```

この場合は例外とする。

### Empty Column Name

以下のようなHeader:

```csv
date,,sales_amount
```

はV1ではinvalidとする。

### Duplicate Column Name

以下:

```csv
date,region,region
```

はV1ではinvalidとする。

理由:

rowをassociative arrayへ変換した際に、
どのcolumnか一意に判断できないため。

### Column Count

Header column数が0の場合はinvalid。

将来的には最大column数もconfigで制限可能とする。

---

## 11. Row Validation

各Data Rowのcolumn数はHeaderと一致する必要がある。

Header:

```csv
date,region,sales
```

Data:

```csv
2026-01-01,Tokyo,1000
```

はvalid。

以下:

```csv
2026-01-01,Tokyo
```

はcolumn不足。

以下:

```csv
2026-01-01,Tokyo,1000,extra
```

はcolumn過多。

V1ではcolumn数不一致を黙って補完・切り捨てしない。

invalid CSVとして例外をthrowする。

理由:

誤ったcolumn対応で統計を生成する方が危険だからである。

---

## 12. Row Representation

HeaderとData Rowを組み合わせ、

```php
array_combine($headers, $row);
```

相当のassociative structureとして扱う。

例:

```php
[
    'date' => '2026-01-01',
    'region' => 'Tokyo',
    'sales_amount' => '120000',
]
```

CSVの元値は原則stringとして保持する。

Type Inferenceによって元値そのものを破壊しない。

---

## 13. Null Handling

V1では以下をmissing valueとして扱う。

```text
null
''
```

CSV parserから取得した値について、
空文字をnull相当として統計処理する。

以下の文字列は自動的にnullとして扱わない。

```text
NULL
N/A
#N/A
-
null
None
```

理由:

業務データ上の有効な値である可能性があるため。

例:

```text
status = N/A
```

をシステム側の判断でmissingへ変換しない。

---

## 14. Whitespace

V1ではHeaderについて前後の不要なwhitespaceをtrimする。

Data valueについては、
元データを不用意に変更しないことを優先する。

Type Inference等の判定時にtrimmed valueを利用することは可能だが、
Sample Rows等へ出力する元値を勝手に書き換えない。

例:

```text
" 00123 "
```

をProfile生成過程で整数 `123` へ変換して元情報を失わない。

---

## 15. Column Type Inference

V1では以下の型を扱う。

```text
integer
decimal
date
datetime
boolean
string
unknown
```

型推定は単一cellだけで決定しない。

column内の複数のnon-null値を確認し、
column全体として推定する。

V1では、column内の全distinct non-null valuesを型判定対象にする。

frequency map（§25）は既に全distinct値を保持しているため、
type inference専用の別sample構造・sample上限（例: `type_inference_sample_size`）は設けない。

理由:

- sample windowの外側に異なる型の値が存在すると、
  mixed type = string fallbackという設計原則が成立しなくなる
- 10MB upload上限を前提としたmemory benchmarkで、
  全distinct値を型判定に使っても安全であることを確認済み

---

## 16. Type Inference Principle

基本的な判定候補:

```text
integer
↓
decimal
↓
date / datetime
↓
boolean
↓
string
```

ただし単純な順番判定だけではなく、
column全体で整合する型を決定する。

異なる型が混在して安全に推定できない場合は、
stringを優先する。

---

## 17. Integer Detection

以下のような値:

```text
1
10
-20
5000
```

はinteger候補。

ただしleading zeroを持つ値:

```text
00123
00456
```

はintegerとして扱わないことを優先する。

理由:

以下の可能性がある。

- customer_id
- product_code
- postal_code
- account_code

数値変換による情報消失を避ける。

---

## 18. Decimal Detection

以下:

```text
10.5
-3.14
100.00
```

はdecimal候補。

V1では通常のdecimal表現を対象とする。

通貨記号やカンマを含む以下のような値:

```text
¥1,000
$100.50
1,000,000
```

を自動的にnumericへ変換する高度な正規化はV1対象外とする。

必要性を確認した上で将来拡張する。

---

## 19. Date / Datetime Detection

一般的な日付形式を対象とする。

例:

```text
2026-08-14
2026/08/14
```

Datetime例:

```text
2026-08-14 10:30:00
2026-08-14T10:30:00
```

曖昧な日付:

```text
01/02/03
```

等を無理に推定しない。

安全に判断できない場合はstringとする。

---

## 20. Boolean Detection

columnのnon-null値がbooleanとして一貫している場合のみ
booleanと推定する。

V1でサポートする値は実装時に明示的に定義する。

例えば:

```text
true
false
```

必要に応じて:

```text
0
1
```

をbooleanとして扱うかは別途判断する。

IDや数値columnを誤判定する可能性があるため、
安易に `0 / 1` をbooleanとしない。

---

## 21. Type Inference Conflict

例えば:

```text
100
200
ABC
300
```

のように型が混在する場合、
無理にintegerへ変換しない。

V1では安全側に倒し、

```text
string
```

とする。

AIへ誤った型情報を渡すより、
stringとして扱う方を優先する。

---

## 22. Column Statistics State

Streaming中は各columnについて、
必要な集計状態のみ保持する。

概念例:

```php
[
    'sales_amount' => [
        'non_null_count' => 0,
        'null_count' => 0,

        // type inference state
        'type_candidates' => [],

        // numeric state
        'numeric_count' => 0,
        'numeric_sum' => 0,
        'numeric_min' => null,
        'numeric_max' => null,

        // categorical / unique state
        // implementation strategy described below
    ],
]
```

実際の内部構造は実装時に調整可能だが、
DataProfilingActionのpublic output contractは維持する。

V1では `unique_count` と categorical frequency count を別々の
データ構造として保持せず、columnごとに単一の frequency map
（`value => count`）を保持し、そこから両方を導出する。

10MBの既存DataFile upload上限を前提としたbenchmarkにより、
high-cardinality columnを含むworst caseでも512MB memory_limitに
十分な余裕があることを確認済み。

---

## 23. Numeric Statistics

最終的にintegerまたはdecimalと推定されたcolumnについて、
以下を出力する。

```text
count
min
max
mean
```

### count

non-null numeric value数。

### min

最小値。

### max

最大値。

### mean

平均値。

Streaming中に:

```text
sum
count
```

を保持し、

```text
mean = sum / count
```

で算出する。

全numeric valuesをメモリに保持する必要はない。

---

## 24. Numeric Precision

V1ではPHPで扱える通常の業務数値を対象とする。

極端に大きい整数、
金融計算で完全なdecimal precisionを要求するケース、
任意精度計算はV1対象外。

Data Profilingは会計計算エンジンではなく、
AI分析用のProfile生成を目的とする。

将来的に高精度decimalが必要な場合は、
BCMath等を含めて別途検討する。

---

## 25. Unique Count

各columnについて `unique_count` を取得する。

ただし、単純にすべてのunique valueを、

```php
$uniqueValues[$value] = true;
```

として無制限に保持すると、
high-cardinality columnでメモリ使用量が増大する。

例:

```text
customer_id
transaction_id
uuid
email
```

そのため `unique_count` は
Data Profilingにおけるメモリ負荷の主要ポイントとして扱う。

---

## 26. V1 Unique Count Strategy

V1ではまず正確な `unique_count` を提供することを優先するが、
アップロード可能なCSVサイズを安全な範囲に制限することを前提とする。

つまりV1の安全境界は、

```text
Unlimited CSV
+
Unlimited Unique Tracking
```

ではなく、

```text
Upload File Size Limit
+
Streaming CSV
+
Controlled In-memory Statistics
```

とする。

実装前に既存DataFile uploadの最大ファイルサイズを確認し、
その制限内で正確なunique trackingが安全かテストする。

メモリ負荷が許容できない場合は、
以下のいずれかを採用する。

- unique tracking上限
- high-cardinality判定
- approximate distinct count
- temporary database aggregation

ただしV1で複雑な近似アルゴリズムを先行実装しない。

---

## 27. Categorical Summary

string等のcategorical columnについて、
出現頻度上位の値を取得する。

出力:

```php
[
    'column' => 'region',
    'top_values' => [
        [
            'value' => 'Tokyo',
            'count' => 320,
        ],
        [
            'value' => 'Osaka',
            'count' => 210,
        ],
    ],
]
```

AIへ全unique valueを送らない。

---

## 28. Categorical Top Values Limit

Categorical Summaryの出力件数には上限を設定する。

例:

```text
MAX_TOP_VALUES = 10
```

具体値は実装時にconfigとして定義する。

重要:

Outputを10件に制限することと、
集計中に全unique valueを保持することは別問題である。

high-cardinality columnで全value countsを保持すると
メモリを消費するため、
実装時にはUnique Count Strategyと合わせて検討する。

---

## 29. High-cardinality Columns

以下のようなcolumn:

```text
transaction_id
customer_id
email
uuid
```

はcategorical summaryとしての価値が低い場合がある。

例えば:

```text
unique_count ≈ non_null_count
```

の場合、
ほぼ全行が異なる値である可能性が高い。

将来的にはhigh-cardinality判定によって
Categorical Summaryから除外することを検討する。

V1ではまず安全なメモリ制御を優先し、
過度な自動分類ロジックは実装しない。

---

## 30. Sample Rows

AIが実際のデータ形式を理解するため、
少量のsample rowsをData Profileへ含める。

Sample Rowsの上限を設定する。

例:

```text
MAX_SAMPLE_ROWS = 10
```

具体値はconfigで管理する。

---

## 31. Sampling Strategy

単純にCSVの先頭N行だけをsampleにすると、
データの並び順によって偏る可能性がある。

例:

```text
CSVがdate ASC
↓
先頭10件
↓
すべて1月のデータ
```

そのためV1では、
可能であればReservoir Samplingを使用する。

目的:

streamingを維持しながら、
CSV全体から均等にsample rowsを取得する。

---

## 32. Reservoir Sampling

概念:

```text
最初のN行
↓
sampleへ格納

N+1行目以降
↓
一定確率で既存sampleと置換
```

これにより、

```text
全CSVをメモリへ保持せず
+
全行から均等にsample
```

を取得できる。

Sample Rows数が小さいため、
メモリ負荷は限定的。

V1で実装可能であれば採用する。

---

## 33. Row Count

`row_count` はHeaderを除いたData Row数とする。

例:

```csv
name,sales
A,100
B,200
C,300
```

の場合:

```text
row_count = 3
column_count = 2
```

空行の扱いはCSV parserの挙動を確認した上で、
V1で統一する。

意図しない空行をData Rowとして数えないことを基本とする。

---

## 34. Profiling Row Limit

V1では原則として、
アップロードされたCSVの全Data Rowをstreamingでprofilingする。

理由:

- row_countを正確に取得する
- null countを正確に取得する
- numeric statisticsを正確に取得する
- AIへ誤解を与えるsample statisticsを避ける

そのためV1では、
任意の `MAX_PROFILE_ROWS` で途中打ち切りする設計を標準にはしない。

安全性は主に以下で確保する。

- DataFile upload size limit
- Streaming
- Memory-controlled aggregation
- Queue timeout
- Unique / categorical tracking制御

将来的に非常に大きなCSVを扱う場合は、

```text
profiled_row_count
total_row_count
is_sampled
```

等を追加し、
sampling-based profilingを導入することを検討する。

---

## 35. File Size Limit

V1ではData Profilingだけで独自のファイルサイズ制限を持つのではなく、
DataFile upload時のvalidationと整合させる。

実装前に既存のDataFile upload仕様を確認する。

Data Profilingが安全に処理できないサイズまで
DataFile uploadを許可している場合は、
DataFile Module側の制限変更を別途検討する。

DataProfilingAction内部に
根拠のないmagic numberを追加しない。

---

## 36. Configuration

Data Profiling固有の上限値は
コード内に分散させない。

例:

```text
sample_rows
categorical_top_values
max_columns
```

Laravel configとして管理することを検討する。

例:

```php
return [
    'sample_rows' => 10,
    'categorical_top_values' => 10,
];
```

`type_inference_sample_size` のようなtype inference用のsample上限は設けない。

理由は §15 を参照。

実際のconfig file名・配置は実装時に
既存プロジェクト構成との整合性を確認して決定する。

---

## 37. Error Handling

DataProfilingActionは、
profiling不能な状態を無理に補正しない。

例外対象:

- DataFileがStorage上に存在しない
- streamをopenできない
- empty CSV
- Header不正
- duplicate Header
- empty Header name
- Data Rowのcolumn数不一致
- CSVとして安全に処理できない状態

これらの場合は例外を上位へ伝播する。

DataProfilingAction内でAnalysisJobをFailedへ更新しない。

```text
DataProfilingAction
↓
Exception
↓
ExecuteAnalysisJobAction
↓
ExecuteAnalysisJob
↓
Laravel Retry
↓
failed()
```

既存のQueue failure lifecycleを利用する。

---

## 38. Logging

DataProfilingAction内で
Queue retryやAnalysisJob failureのログを重複して出さない。

最終的なjob execution failure loggingは
ExecuteAnalysisJob側の責務とする。

Data Profiling固有のdiagnostic logが将来必要になった場合のみ、
目的を明確にした上で追加する。

---

## 39. Persistence

V1ではData ProfileをDBへ保存しない。

```text
DataFile
↓
DataProfilingAction
↓
array
↓
AiAnalysisClient
```

同一DataFileに対して複数AnalysisJobが存在する場合、
V1ではData Profilingの再実行を許容する。

理由:

- まずEnd-to-EndのAI分析を完成させる
- Cache invalidationを持ち込まない
- Profile Schema変更を容易にする
- 不要なDB設計を先行しない

---

## 40. Future Persistence

以下が実際の問題になった場合、
Data Profile persistenceを検討する。

- Profiling処理時間が長い
- 同じDataFileの分析回数が多い
- Data Profile画面が必要
- AI実行時点のProfile再現が必要
- Audit要件
- Profile比較
- Profiling結果のCache

候補:

```text
data_files
    1
    └── 1 data_file_profiles
```

ただしV1では作成しない。

---

## 41. Security

Sample RowsおよびCategorical Summaryには
元CSVの実データが含まれる。

そのためData Profileは、
外部AI Providerへ送信される可能性があるデータとして扱う。

V1では高度なPII検出は対象外だが、
将来的に以下を検討する。

- Sensitive Column Detection
- PII Masking
- Sample Row Masking
- AI送信対象column選択
- AI送信禁止column
- Organization-level Policy

DataProfilingActionの設計は、
将来これらを追加できるように
元CSV処理とAI送信処理を分離しておく。

---

## 42. Data Accuracy

Data Profilingは、
可能な限り元CSVに忠実な客観情報を生成する。

以下を避ける。

- 業務的意味の推測
- 値の勝手な補完
- unknown valueの自動変換
- AI向けに都合の良いデータ改変
- 根拠のない型変換

Laravel側:

```text
Facts / Statistics
```

AI側:

```text
Interpretation / Analysis / Recommendation
```

この責務分離を維持する。

---

## 43. Integration with ExecuteAnalysisJobAction

最終的な呼び出しイメージ:

```php
$profile = $this->dataProfilingAction->execute(
    $analysisJob->dataFile,
);

$rawResponse = $this->aiAnalysisClient->analyze(
    $analysisJob->dataFile,
    $analysisJob->analysisJobDetail->prompt,
    $profile,
);

$result = $this->normalizeAnalysisResultAction->execute(
    $rawResponse,
);
```

AiAnalysisClientの最終interfaceは
AI Provider実装時に確定する。

DataProfilingActionはAiAnalysisClientを呼び出さない。

---

## 44. Testing Strategy

DataProfilingActionは
AI APIを使用せず単独でテスト可能であること。

最低限以下をテストする。

### File Metadata

- row_count
- column_count
- original filename

### Column Information

- column name
- non-null count
- null count
- unique count

### Type Inference

- integer
- decimal
- string
- date
- datetime
- boolean
- leading-zero value
- mixed type fallback to string

### Numeric Statistics

- count
- min
- max
- mean

### Categorical Summary

- frequency count
- top values limit

### Sample Rows

- sample row count limit
- returned column structure

### CSV Validation

- empty file
- duplicate Header
- empty Header name
- row column shortage
- row column excess

### Null Handling

- empty string
- literal `NULL`
- literal `N/A`
- literal `#N/A`

### Resource Handling

- streamが正常にcloseされること
- exception発生時もresource leakしないこと

---

## 45. Profiling V2

V2以降では以下を検討する。

### Aggregation

> **実装済み(Phase 1)**: dimension(categorical column)× measure
> (numeric column)ごとの sum / count / avg を Laravel側で正確に計算し、
> AI Contextへ渡す機能を `MetricAggregationAction` として実装した。
> 詳細は docs/product/METRIC_AGGREGATION.md を参照。
>
> `DataProfilingAction` 自体には引き続きAggregation責務を追加していない
> (本ファイル §2 の方針を維持)。`MetricAggregationAction` は
> `app/Actions/DataProfiling/` 配下の別Actionとして実装されている。
>
> User Promptの内容に応じて必要なAggregationを動的に判断する
> (Analysis Intentに基づく選択的集計)仕組みはPhase 1のスコープ外であり、
> 引き続き将来検討事項として残る。Phase 1では、Data Profileの列型情報
> (`inferred_type` / `unique_count`)のみから機械的にdimension /
> measure候補を選定する。

User Promptに応じて必要な集計を生成する。

例:

```text
User Prompt
↓
Analysis Intent
↓
Required Aggregation
↓
Laravel
↓
AI Context
```

候補:

- date × numeric
- category × numeric
- value counts
- period comparison

### Anomaly Candidates

統計的な異常値候補をLaravel側で抽出する。

候補:

- IQR
- extreme deviation
- sudden change

Laravel側では異常と断定せず、
AIへcandidateとして提供する。

### Advanced Statistics

候補:

- median
- standard deviation
- quartiles
- percentiles
- correlation

### Large Dataset Profiling

候補:

- sampled profiling
- approximate unique count
- database aggregation
- chunk processing
- profile persistence
- profile cache

---

## 46. V1 Processing Flow

```text
DataFile
 │
 ▼
DataProfilingAction
 │
 ├── Open Stream
 │
 ├── Read / Validate Header
 │
 ├── Initialize Column State
 │
 ├── Stream Rows
 │     │
 │     ├── Validate Row
 │     ├── Count Rows
 │     ├── Null / Non-null Count
 │     ├── Type Inference State
 │     ├── Unique Tracking
 │     ├── Numeric State
 │     ├── Categorical State
 │     └── Reservoir Sampling
 │
 ├── Finalize Types
 │
 ├── Finalize Numeric Statistics
 │
 ├── Finalize Categorical Summary
 │
 ├── Build Column Information
 │
 └── Close Stream
 │
 ▼
Data Profile
 │
 ▼
AI Context
 │
 ▼
AiAnalysisClient
```

---

## 47. Design Principle Summary

DataProfilingActionの基本原則:

> CSV全体をAIへ送る代わりに、
> Laravelが元データから客観的でコンパクトなProfileを生成する。

V1では高度な分析機能を作り込まず、

```text
File Metadata
+
Column Information
+
Basic Numeric Statistics
+
Categorical Summary
+
Sample Rows
```

に限定する。

実装上は以下を優先する。

```text
Streaming
+
Accuracy
+
Controlled Memory Usage
+
Clear Responsibility
+
Testability
```

DataProfilingActionはAIの代わりに
「何が重要か」を判断するものではない。

```text
DataProfilingAction
= Data Facts

AiAnalysisClient
= AI Analysis

NormalizeAnalysisResultAction
= Report Contract

Report Renderer
= Presentation
```

この責務分離をReportFlow AI V1の基本設計とする。
