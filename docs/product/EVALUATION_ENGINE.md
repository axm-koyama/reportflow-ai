# Deterministic Evaluation Engine Design (Phase 4-A)

> **Target Architecture reference**: ReportFlow AIのPhase 4全体は
> `Facts → Evaluation → Diagnosis → Priority → Action` という5層の
> Target Architectureとして設計参考資料が存在する。このドキュメント自体が
> 記述するのはPhase 4-Aの`Evaluation`層の実装済み仕様のみ。Diagnosis層
> はPhase 4-B(docs/product/DIAGNOSIS_ENGINE.md)、Priority層はPhase 4-C
> (docs/product/PRIORITY_ENGINE.md)で実装済み。Action、および
> historical baseline / temporal trend / pipeline orchestration全体は
> 引き続き未実装(§20 "Phase 4-A対象外"を参照)。

## 1. Purpose

ReportFlow AIは既に

- `DataProfilingAction`(Data Profile)
- `MetricAggregationAction`(aggregated_metrics: dimensionごとの
  sum/count/avg)
- `effective_column_mapping`(Phase 3-C, Manual > Validated AI Mapping)
- Analysis Template(`config/analysis_templates.php`)

を持っている。Phase 4-Aの目的はこれらを使い、単なる数値:

> Social channel の conversion_rate = 4.0%

から、

> その数値が基準と比べてどういう状態か(baseline比較・統計的シグナル強度・
> 実務的な意味・数値方向・評価不能かどうか)

を **Laravelだけでdeterministicに評価する** ことである。

基本原則:

```text
Laravel = Facts, Baseline, Statistical Evaluation, Practical Significance, EvaluationFact
AI      = 一切参加しない(新規AI call = 0)
```

## 2. Phase 4-A v1 のスコープ

対象は1 Template × 1 Rate Metricのみ:

- Template: `ad_performance`
- Entity: `channel`
- Metric: `conversion_rate` = `conversions / clicks`

`sales_analysis`にはPhase 4-A v1時点でEvaluation Metricを定義しない
(`config/evaluation_metrics.php`にエントリがない=評価対象外。エラーでは
ない)。Free Analysis(`template_key === null`)もPhase 4-A v1では評価
対象外(`effective_column_mapping`が存在しないため)。

## 3. AI非依存(derived_metricsへ依存しない)

Evaluation Engineは`PlanDerivedMetricsAction` / `CalculateDerivedMetricsAction`
が生成する`derived_metrics`を**一切参照しない**。理由:

1. `derived_metrics`はnumerator/denominatorの生カウントを保持しない
   (`CalculateDerivedMetricsAction::computeGroup()`は`{value, result}`
   のみを返す)。
2. `percentage`演算子は`(left/right)*100`で0〜100スケール
   ([DERIVED_METRICS.md](DERIVED_METRICS.md)参照)。Evaluationの内部
   scale(§8)と異なる。
3. その`derived_metrics`が実際に提案されるか、`name`が何になるか、
   `operator`が`percentage`か`divide`かはPlanning AIの非決定的判断に
   依存する(`PlanDerivedMetricsAction`のSystem Instruction参照)。
4. 同じCSVでもAIの非決定性によりEvaluation対象そのものが変わり得る。

正しいSourceは:

```text
effective_column_mapping (既存Fact、AI再Callなし)
+ config/evaluation_metrics.php (Laravel-only, Template横断のEvaluation定義)
+ aggregated_metrics (Laravel計算のsum/count、既に同じ実行attempt内でMetricAggregationActionが計算済み)
```

の3つのみである。

## 4. config/evaluation_metrics.php

`config/analysis_templates.php`とは意図的に分離した(§21参照)。
Template keyごとに評価するmetricのlistを持つ:

```php
return [
    'ad_performance' => [
        'metrics' => [
            [
                'metric_key' => 'conversion_rate',
                'metric_type' => 'rate',
                'entity_field' => 'channel',
                'numerator_field' => 'conversions',
                'denominator_field' => 'clicks',
                'unfavorable_direction' => 'below',
                'practical_significance_floor' => 0.005, // 0〜1スケール(0.5pp)
                'normal_approximation_min_expected' => 5,
                'z_threshold_high' => 1.96,
                'z_threshold_medium' => 1.00,
                'rule_version' => 'evaluation_rule_v1.0',
            ],
        ],
    ],
    // sales_analysis はエントリなし = 評価対象外
];
```

`entity_field`/`numerator_field`/`denominator_field`は**semantic field key**
(Templateの`fields`と同じ語彙)であり、実CSV列名ではない。実列名は
`effective_column_mapping`を通じて実行時に解決される(§6)。

## 5. Effective Mapping Resolution

`ResolveEvaluationMetricDefinitionsAction`が
`config/evaluation_metrics.php`のsemantic定義を実列名へ変換する:

```text
semantic: channel / conversions / clicks
↓ (effective_column_mapping)
actual:   媒体 / CV数 / クリック数   (日本語CSVの例)
actual:   channel / conversions / clicks (英語CSVの例)
```

AI Mapping再実行は一切行わない。`effective_column_mapping`は
`ExecuteAnalysisJobAction`が既に確定させたFactをそのまま使うのみ
(Manual > Validated AI Mapping、いずれのsourceでも区別しない)。

Mapping不足時は2通りに分岐する(詳細は§7):

- `entity_field`自体が未Mapping → その metric は`skipped`(reason:
  `entity_field_unmapped`)としてまるごとskipされ、`resolved`には現れない。
- `entity_field`はMapping済みだが`numerator_field`/`denominator_field`の
  どちらかが未Mapping → `resolved`にnullのまま含まれる
  (`EvaluateRateMetricAction`側でCase Bとして扱う)。

## 6. aggregated_metrics依存性の確認

`EvaluateRateMetricAction`は評価開始前に必ず:

1. `entity_field`の実列名が`aggregated_metrics.dimensions[].dimension`に
   存在するか(`MetricAggregationAction`の`max_dimensions`/
   `max_cardinality_per_dimension`により、Mapping済みでも
   aggregation対象から外れている可能性がある)
2. `numerator_field`/`denominator_field`の実列名が
   `aggregated_metrics.measures`に存在するか(`max_measures`により
   落ちている可能性がある)

を確認する。**「aggregated_metricsは完全である」と暗黙に仮定しない。**
1が満たされない場合は空配列(facts無し)を返し、呼び出し元
(`EvaluateAnalysisJobAction`)がwarningログを残す。2が満たされない場合は
§7 Case Bと同じ扱いになる。

## 7. Mapping不足時 / 評価不能時のFact生成方針

| ケース | 挙動 |
|---|---|
| Case A: entity_field自体がMapping不足、または aggregated_metricsのdimensionに不在 | Entity一覧を列挙できない → EvaluationFactを**生成しない**(metric自体をskip) |
| Case B: entity_fieldはあるが numerator/denominator が Mapping不足 または aggregated_metricsのmeasuresに不在 | Entity一覧は取得可能 → 各entityに`evaluation_level=insufficient_data`のEvaluationFactを生成 |
| Entity個別のcount validity違反(§10) | そのentityのみ`insufficient_data`、他entityの集計には一切寄与しない |
| Normal approximation gate失敗(§13) | `z_score=null`, `evaluation_level=insufficient_data`。ただし`metric_value`等は計算できる限り保持 |

「評価できないこと自体をFactとして残す」原則は、Entity一覧が実際に
列挙できる範囲でのみ適用される。

## 8. Rate内部scale

Evaluation Engine内部では、rateは常に**0〜1スケール**で統一する
(4.0% = 0.04)。`config/evaluation_metrics.php`の
`practical_significance_floor`も0〜1スケール(0.005 = 0.5pp)。

これは`CalculateDerivedMetricsAction`の`percentage`演算子(0〜100
スケール)とは**意図的に異なる**——Evaluation Engineは`derived_metrics`
に一切依存しない設計(§3)なので、この境界を跨いでスケールが混在する
ことはない。UI表示のみ`値 × 100`で%/ppとして表示する
([show.blade.php](../../resources/views/analysis-jobs/show.blade.php)
の"Evaluation"セクションを参照)。

## 9. Display Baseline(weighted aggregate)

全entityを含めた加重平均:

```text
display_baseline = Σ numerator / Σ denominator
```

**`mean(entityごとのrate)`は禁止。** Simpson's paradoxを避けるため、
必ず生カウントの合計比で計算する(`EvaluateRateMetricAction`は
count-validityを通過したentityのみを合計対象とする——§10参照)。

例(§実データ検証で使用した値):

```text
Email    243/3000
Social   200/5000
Paid     220/4000
Organic  147/3000
合計     810/15000 = 0.054 (5.4%)
```

## 10. Test / Control Baseline(leave-one-out)

統計検定用baselineは、評価対象entity自身を除いた残りpeersの合算:

```text
test_baseline(Social) = (Email+Paid+Organic) の合計
                       = 610/10000 = 0.061 (6.1%)
```

Display Baseline(5.4%)とTest Baseline(6.1%)は別概念であり、
`EvaluationFact`へ`display_baseline_value`/`test_baseline_value`として
別々に保存する。監査・再計算のため`numerator_value`/`denominator_value`
(entity自身)と`control_numerator_value`/`control_denominator_value`
(peers合算)も保存する——これにより「表示基準は5.4%なのにz=-5.36なのは
なぜか」という疑問に、DB保存Factだけで再計算・説明できる。

## 11. Count Validity

`EvaluateRateMetricAction::validateCount()`が、two-proportion z-testへ
渡す前に各entityのnumerator/denominatorを検証する:

- 非負であること
- "integer-like"であること(§12)——`10.5`のような真に非整数の値は無効
- `numerator <= denominator`であること

不正な場合は**roundしない・truncateしない**。例外でAnalysisJob全体を
壊さず、そのentityを`evaluation_level=insufficient_data`とする。

個別に非負整数として妥当だが`numerator > denominator`という**関係性が
不正**なケース(例: conversions=120, clicks=100)は、各カウント自体は
`unsignedBigInteger`列へ安全に保存できるため、監査目的でそのまま保存する
(`numerator_value`/`denominator_value`は非nullのまま)。一方
`conversions=10.5`のように個々の値自体が無効な場合は、その値を
DBへ一切保存しない(nullのまま)——ロスのある丸め込みでの保存を防ぐため。

**重要**: count validityに違反したentityは、他のentityのdisplay
baseline/test baselineの計算に一切寄与しない(合算対象から除外される)。
1つの汚染されたentityが他のentityの評価を歪めないようにするため。

## 12. Integer-like判定

PHPのfloat/int両方を考慮し、`abs(value - round(value)) <= epsilon`で
判定する。`epsilon = 1e-9`(`TwoProportionZTestAction::INTEGER_LIKE_EPSILON`
/ `EvaluateRateMetricAction::COUNT_EPSILON`)——float累積誤差
(例: `10.000000000000002`)を吸収する程度に厳しく抑え、真に非整数な値
(`10.5`)を誤って許容しないようにしている。

## 13. Two-Proportion Z-Test

新規pure Action `TwoProportionZTestAction`(AI I/Oなし、DB I/Oなし):

```text
pA = xA / nA
pB = xB / nB
pPool = (xA + xB) / (nA + nB)
SE = sqrt(pPool * (1-pPool) * (1/nA + 1/nB))
z = (pA - pB) / SE
```

Edge caseはすべて例外を投げず`z_score=null`(insufficient)として処理する:

| Edge case | 扱い |
|---|---|
| `nA == 0` / `nB == 0` | insufficient |
| `pPool == 0` / `pPool == 1` | insufficient(SEが0になるケースを事前に遮断) |
| SE が (ほぼ) 0 | insufficient(pPool境界チェックで実質到達不能な防御的ガード) |
| non-finite な結果 | insufficient |
| invalid count(非整数・負・numerator>denominator) | insufficient |

PHPの`/`演算子は両オペランドがintで割り切れる場合int値を返すため
(`p_pool`/`metric_value`等の型が入力によって不安定になる)、明示的に
float castして常にfloatを返すようにしている。

### Normal Approximation Gate

```text
nA * pPool       >= threshold
nA * (1-pPool)   >= threshold
nB * pPool       >= threshold
nB * (1-pPool)   >= threshold
```

`threshold`(既定5)は`config/evaluation_metrics.php`の
`normal_approximation_min_expected`から渡され、hardcodeしていない。
Gate失敗時は`z_score=null`, `evaluation_level=insufficient_data`。
ただし`metric_value`/`display_baseline_value`/`test_baseline_value`/
`delta_absolute`/`delta_percent`/`direction`は計算できる限り保持する
(情報を捨てない)。

## 14. Delta / Direction

内部rate scale(0〜1)基準:

```text
delta_absolute = metric_value - display_baseline_value
delta_percent  = delta_absolute / display_baseline_value   (baseline=0の場合はnull)
```

`direction`は数値Factとして独立させる:

```text
metric_value > display_baseline → above
metric_value < display_baseline → below
metric_value == display_baseline → equal
```

`evaluation_level`が`low`/`insufficient_data`であっても`direction`を
`in_line`のような曖昧な値へ潰さない——情報量を落とさないため。

## 15. Practical Significance Floor

**floor判定は必ず`delta_absolute`(0〜1スケール)で行う。`delta_percent`
では判定しない。**

```text
abs(delta_absolute) < practical_significance_floor
  → high   → medium
  → medium → low
  → low    → low (変化なし)
```

実データ回帰テスト例(§18も参照): entity 4.0% vs baseline 4.4%は
`delta_absolute = -0.004`(0.5ppのfloor未満)なので、たとえ
`delta_percent`が約-9%であっても、high評価はmediumへ降格する。

### float境界比較(Comparison Epsilon)

上記の判定は実装上、raw IEEE 754 float comparison(`abs(delta_absolute)
< practical_significance_floor`そのまま)を使っていない。理由は、
`metric_value`/`display_baseline_value`はどちらも独立した除算
(`x/n`、`Σx/Σn`)から計算されるため、数学上`abs(delta_absolute)`が
`practical_significance_floor`と**厳密に等しい**はずのケースでも、
float減算誤差により`0.0049999999999999975`のような、floorよりわずかに
小さい値になり得る(実例: 4channel例のOrganicは`147/3000 - 810/15000`
が数学上ちょうど`-0.005`だが、実際の浮動小数点演算では
`-0.0049999999999999975`になる)。これをraw比較すると、本来
downgradeされるべきでない「境界で等しいケース」が非決定的に
downgradeされてしまう。

これを防ぐため、`EvaluateRateMetricAction`は比較専用の
`COMPARISON_EPSILON`(`1e-10`)を使い、

```text
abs(delta_absolute) + COMPARISON_EPSILON < practical_significance_floor
```

という形で判定する。数学上floorと同値のdeltaは(float誤差でどちらに
振れても)downgradeされず、floorより明確に小さいdeltaのみdowngradeされる。

**`COMPARISON_EPSILON`は`Count Validity`(§11/§12)で使う
`COUNT_EPSILON`/`INTEGER_LIKE_EPSILON`とは別概念であり、値も流用しない。**
前者は「0〜1スケールのrate差」の比較用、後者は「event count(数百〜数百万
オーダーの整数)がintegerとみなせるか」の判定用であり、意味も妥当な
epsilonのオーダーも異なる。

### Known Limitation: dominant-entity self-dilution(Phase 4-C cross-reference)

この`delta_absolute`は`display_baseline_value`(entity自身を含む
加重平均)基準であるため、traffic shareが極端に大きいentityでは
`display_baseline_value`自体がそのentityの値へ引き寄せられ、
`delta_absolute`が縮小し、上記floor判定によって`evaluation_level`が
downgradeされ得る(例: shareの90%を占めるentityでは、真のpeer差が
約4.0ppでも`delta_absolute`は約0.4ppまで縮小する)。これはPhase 4-A
自身の既知の制約であり、本ドキュメントの範囲では変更していない。
詳細と実例はdocs/product/PRIORITY_ENGINE.md §8-2「Known Limitation:
dominant-entity self-dilution in Evaluation practical significance」
を参照——Phase 4-CのPriority Gap自体はこの問題の影響を受けない
(`test_baseline_value`基準に修正済み)が、Priority Eligibilityが
依存するこの`evaluation_level`自体はこの制約の影響を受け得る。

## 16. Evaluation Level

```text
|z| >= z_threshold_high(既定1.96)               → high
z_threshold_medium(既定1.00) <= |z| < 1.96       → medium
|z| < z_threshold_medium                         → low
z_score が null (gate失敗 / insufficient count)  → insufficient_data
```

その後§15のpractical floorを適用する。閾値はすべて
`config/evaluation_metrics.php`のmetricごとの設定値。

### 名称: `significance_level`ではなく`evaluation_level`

この値は純粋な統計的significanceではなく、statistical z threshold /
practical significance floor / insufficient sample gateを組み合わせた
**Product Evaluation結果**である。将来ユーザーへ表示する際に「統計的に
highに有意」と誤解されないよう、DB/Model/UIすべてで`evaluation_level`
という名称に統一した。

## 17. unfavorable_direction

`config/evaluation_metrics.php`の`unfavorable_direction`(`below`/`above`)
は「どちらの方向がBusiness上悪いか」を示すconfig側の解釈である。
`direction`(数値Fact)とは明確に分離しており、`EvaluationFact`自体には
`unfavorable`のようなbooleanを保存しない——Phase 4-B以降のDiagnosis/
Priorityがconfigと`direction`を照合して利用する想定(Future Scope)。

## 18. Peer Cohort / Peer Count Gate

Peer group = 同一AnalysisJobの同一aggregation実行における、entity
dimensionの全groups(§6のaggregation上限の影響を受ける)。

- null/blank dimension値は`MetricAggregationAction`が既にgroupingから
  除外済み。
- zero denominator な entity は§11のcount validityにより
  `insufficient_data`(0<=0は有効な整数ペアなので保存はされるが、
  metric_valueは0/0のためnull)。
- 「active/inactive entity」という概念は現行ReportFlowに存在しないため
  導入していない。

**最低peer channel数という追加Gateは設けていない。** rate型
two-proportion testでは、channel数自体ではなく生カウント(nA/nB)が
sample sizeだからである。Control groupが1 channelだけでも、底層count
が十分なら検定は可能(Product要件として必要になった場合のみ将来追加)。

## 19. EvaluationFact Schema

```text
evaluation_fact_id          PK
analysis_job_id             FK -> analysis_jobs, cascadeOnDelete
entity_type                 string   (semantic key, e.g. "channel")
entity_key                  string   (entityの値, e.g. "Social")
metric_key                  string   (e.g. "conversion_rate")
metric_type                 string   (e.g. "rate")
metric_value                decimal(18,8) nullable
display_baseline_value      decimal(18,8) nullable
test_baseline_value         decimal(18,8) nullable
numerator_value             unsignedBigInteger nullable
denominator_value           unsignedBigInteger nullable
control_numerator_value     unsignedBigInteger nullable
control_denominator_value   unsignedBigInteger nullable
delta_absolute               decimal(18,8) nullable
delta_percent                decimal(18,8) nullable
z_score                      decimal(18,8) nullable
direction                    string(10) nullable   (above/below/equal)
evaluation_level              string(20)             (high/medium/low/insufficient_data)
rule_version                  string(50)
computed_at                   timestamp
timestamps
UNIQUE (analysis_job_id, entity_type, entity_key, metric_key, rule_version)
```

`report_snapshot_id`は現行ReportFlowに存在しないため採用していない。
親は`analysis_job_id`(AnalysisJobが最も自然な親——1 AnalysisJob attempt
につき複数entityのFactを持つ1:N関係)。

## 20. Persistence / Idempotency / Rule Version

EvaluationFactはDBへ保存する(DiagnosisのEvidence・監査・UI説明・
rule version追跡・再実行・Phase 4-Bへの基礎とするため)。

**Idempotency: delete + recreate。** `EvaluateAnalysisJobAction`実行時、
1つのtransaction内で:

1. `analysis_job_id`の既存EvaluationFactsを全delete
2. 今回のattemptで計算したfactsをinsert

同じAnalysisJobへQueue retryなどで複数回実行されても、常に最新attemptの
factsだけが残る(「latest wins」)。unique制約は防御的backstop。
`pipeline_run_id`はPhase 4-Aでは導入しない。

`rule_version`は必須(初期値`evaluation_rule_v1.0`)。Phase 4-A v1では
複数versionを並存させない——閾値変更時は再実行によって置き換わる
(version history比較はFuture Scope)。

## 21. Soft-fail

Evaluation Engineの**技術的例外**は、既存AnalysisJob全体を失敗させない
(soft-fail)。`ExecuteAnalysisJobAction`は`EvaluateAnalysisJobAction`の
呼び出しを`try/catch(Throwable)`で囲み、例外時はログのみ残してAnalyze
以降のpipelineを継続する:

```php
try {
    $this->evaluateAnalysisJobAction->execute($analysisJob, $aggregatedMetrics);
} catch (Throwable $evaluationException) {
    Log::error('EvaluateAnalysisJobAction: technical failure — continuing...', [...]);

    try {
        $this->evaluateAnalysisJobAction->clearForAnalysisJob($analysisJob);
    } catch (Throwable $cleanupException) {
        Log::critical('EvaluateAnalysisJobAction: failed to clear stale EvaluationFacts after a technical failure.', [...]);
    }
}
```

ただし、以下は例外ではなく**正常なBusiness Result**である(Evaluation
Engine自身が例外を投げない):

- denominator=0
- Mapping不足(Case A/B)
- sample不足(normal approximation gate失敗)
- invalid count

これらはすべて`evaluation_level=insufficient_data`またはmetric自体の
skipとして処理される。soft-failが対象とするのは、それ以外の真の
技術的バグ・想定外のaggregated_metrics形状などに限られる。

ログには`analysis_job_id`/`template_key`/exception class/messageを
残す。Phase 4-Aでは`pipeline_failures`テーブルは作らない(Future
orchestrationで統一監視へ移行予定)。

### Stale EvaluationFactを残さない

`execute()`自身の「delete + recreate」(§20)は、そのattemptが
**成功して初めて**古いfactsを新しいfactsで置き換える。しかし
`execute()`が(delete+recreateへ到達する前に)技術的例外を投げた場合、
その置き換えは一度も走らない——**にもかかわらず、以前の成功した
attemptが残したEvaluationFactsはDBに残ったまま**になる。これを
放置すると、「今回のEvaluationが成功したように見える古いFact」が
残り、AnalysisJobは実際には今回評価されていないのに評価済みのように
見えてしまう。

これを防ぐため、soft-fail時は`EvaluateAnalysisJobAction::clearForAnalysisJob()`
を呼び、そのAnalysisJobのEvaluationFactsを明示的に全delete(insertなし)
する。Evaluation persistenceの責務は引き続き`EvaluateAnalysisJobAction`
側にあり、`ExecuteAnalysisJobAction`が`EvaluationFact`モデルを直接
操作することはない——`clearForAnalysisJob()`は`execute()`の
「delete + recreate」の"delete"半分だけを単独で呼べる小さなpublic API
である。

**結果として:**

```text
Evaluation technical failure
→ stale EvaluationFact cleanup(clearForAnalysisJob())を必ず試行する
→ cleanup成功: そのAnalysisJobのEvaluationFactsは0件(stale factsは残らない)
→ cleanup失敗: stale factsが残る可能性がある
                (original exception + cleanup exceptionをLog::critical()へ記録)
→ いずれの場合もPhase 4-Aのsoft-fail方針に従い、
  AnalysisJob pipeline自体(Final Analysis)は継続する
```

**「technical failure時は必ず0件になる」という無条件のInvariantでは
ない点に注意。** cleanupの試行(`clearForAnalysisJob()`の呼び出し)
自体は必ず行われるが、その呼び出し自体がDB障害等で失敗した場合、
以前の成功したattemptが残したEvaluationFactsはDBに残ったままに
なり得る。この場合でも、元のEvaluation例外を隠さないよう両方の
例外(exception class / message)を`Log::critical()`で個別に記録し、
cleanup失敗自体はsoft-failの外側へは伝播させない——pipelineはこの
場合もAnalyze以降へ継続する。

これは既存の「latest wins」idempotency方針と矛盾しない——ただし
保証できるのは「正常にcleanup(またはdelete + recreate)が完了した
範囲では、失敗したattempt以前のEvaluationFactを最新結果として残さ
ない」という条件付きの不変条件であり、cleanup自体の失敗(DB障害など)
からは保護しない。

## 22. Action責務分割

```text
ResolveEvaluationMetricDefinitionsAction
  純粋: template_key + effective_column_mapping + config/evaluation_metrics.php
        → 実列名レベルのDefinition({resolved: [...], skipped: [...]})

TwoProportionZTestAction
  純粋数学: (xA, nA, xB, nB, threshold) → {z_score, p_pool, gate_passed}

EvaluateRateMetricAction
  1 metric分のorchestration: aggregated_metricsからdisplay baseline/
  leave-one-out control/count validity/z-test呼び出し/delta/direction/
  evaluation_level算出 → EvaluationFact形のarray list(未永続)を返す

EvaluateAnalysisJobAction
  top-level orchestration: AnalysisJob + aggregated_metrics を受け取り、
  上記3 Actionを呼び出し、delete+recreateで永続化。AI I/O・Queue知識なし
```

これ以上細分化していない(pure計算は小さいAction、orchestrationは1つ、
という方針)。

## 23. Queue

新規Queue/Jobは作っていない。`aggregated_metrics`は既に
`ExecuteAnalysisJobAction`内でin-memoryに計算済みであるため、
`CalculateDerivedMetricsAction`の直後・`BuildAnalysisContextAction`の
直前で`EvaluateAnalysisJobAction`を**同期呼び出し**する:

```text
MetricAggregationAction
↓
Template Mapping resolution (Mapping AI / Effective Mapping)
↓
PlanDerivedMetricsAction / CalculateDerivedMetricsAction
↓
EvaluateAnalysisJobAction   [Phase 4-A: AI呼び出しなし、soft-fail]
↓
BuildAnalysisContextAction / AiAnalysisClient::analyze()
↓
markCompleted()
```

これによりCSVの再streamや追加のaggregationが一切発生しない。
`EvaluateAnalysisJobAction`自体はAnalysisJob IDと計算済みFactを渡せば
完結する形になっており(Orchestration層とCalculation層は疎結合)、将来
Bus::batchなどのpipeline orchestrationへ移行する際も密結合を避けられる。

## 24. 既存Final AI Analysisとの関係

Phase 4-AではEvaluationFactを`BuildAnalysisContextAction` /
`AiAnalysisClient::analyze()`へ**渡さない**。既存AI分析結果は一切変更
していない。Phase 4-AはEvaluationの正確性を単独で検証するPhaseであり、
Phase 4-B Diagnosis開始時に正式にDecision Contextへ組み込む想定。

> **Phase 4-B実装済み追記**: 上記の想定通り、EvaluationFactは
> `BuildAnalysisContextAction`/`AiAnalysisClient::analyze()`へは
> 依然として渡していない(analyze()のcontext構造は不変)。代わりに、
> `RunDiagnosisForAnalysisJobAction`が`EvaluateAnalysisJobAction`永続化後の
> EvaluationFact行を読み、Diagnosis-eligibleなFactだけを対象に別のAI呼び出し
> (`AiAnalysisClient::diagnose()`)を行う——「EvaluationFactをFinal Analyzeへ
> 渡す」のではなく、「EvaluationFactを起点に専用のDiagnosis Layerを新設する」
> 形でDecision Contextへ組み込まれた。また`BuildAnalysisContextAction`は
> Decision-enabled(`template_key`が`config/evaluation_metrics.php`に
> エントリを持つ)AnalysisJobについてのみ、Final AnalyzeのSystem
> Instructionへ「causal diagnosis / priority / action recommendationを
> 行わない」追加ルールを付与するようになった(既存Rule 1-22は無変更)。
> 詳細はdocs/product/DIAGNOSIS_ENGINE.md参照。

## 25. UI

`resources/views/analysis-jobs/show.blade.php`に、AnalysisJobが
Completedかつ`evaluationFacts`が存在する場合のみ「Evaluation」
セクションを追加した。列: Entity / Metric / Value / Baseline /
Difference / Direction / Evaluation。rate値・baseline・deltaは
`× 100`して%/pp表示する(§8)。`evaluation_level`はbadgeで色分け
表示する。全面UI改修は行っていない。

## 26. Phase 4-A対象外(Future Scope)

以下は今回実装していない:

- historical_avg / previous_period / target baseline
- absolute metric evaluator(rate以外のmetric_type)
- percentile
- ~~Diagnosis AI / diagnosis_categories~~ → Phase 4-Bで実装済み。
  confidence calibration(self_reported_confidenceのcalibration)は
  引き続き未実装。docs/product/DIAGNOSIS_ENGINE.md参照。
- ~~Priority formula~~ → Phase 4-Cで実装済み。docs/product/PRIORITY_ENGINE.md参照。
- Action Catalog / Action AI
- user feedback
- pipeline_run_id / report_snapshot
- Bus::batch orchestration / 専用Evaluation Queue / circuit breaker / AI rate limiter
- temporal trend(YoY/MoM/WoW)
- EvaluationFactをFinal AI Contextへ渡すこと
- UI全面刷新
- 最低peer channel数の追加Gate
- rule_versionの複数並存・履歴比較

## 27. Independent Numerical Verification

Social(200/5000)vs peers(Email+Paid+Organic合算 610/10000)の例を
Pythonで独立計算し、`z ≈ -5.3643390485032`と一致することを確認した
(`tests/Feature/Evaluation/TwoProportionZTestActionTest.php`
`test_it_matches_the_independently_verified_social_channel_example`
にハードコードされている)。Real OpenAI API E2Eでも同一の値が実データ
から再現されることを確認済み(§28)。

## 28. Real API E2E 実施記録

`ad_performance`テンプレート・日本語列名CSV(媒体/クリック数/CV数)で
実際のOpenAI APIを使い、Mapping AI → Planning AI → Evaluation(Laravel)
→ Analyze AIの全パイプラインを実行し、以下を確認した:

- API request数: 3(Evaluationによる増加なし)
- Effective Mapping: 媒体→channel, クリック数→clicks, CV数→conversions
  が正しく解決
- EvaluationFact 4件生成、Social のz_score ≈ -5.3643(独立計算値と一致)
- AnalysisJobは`Completed`、`error_message`はnull
- テスト用Project/DataFile/AnalysisJob/EvaluationFactはすべて実行後に
  削除済み(本番/開発DBへ永続的な影響を残さない)
