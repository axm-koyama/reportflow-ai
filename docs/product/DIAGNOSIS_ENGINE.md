# Controlled Diagnosis Engine Design (Phase 4-B v1)

> **Target Architecture reference**: ReportFlow AIのPhase 4全体は
> `Facts → Evaluation → Diagnosis → Priority → Action` という5層の
> Target Architectureとして設計参考資料が存在する(docs/product/EVALUATION_ENGINE.md
> 冒頭も参照)。Phase 4-B時点で実装したのは`Diagnosis`層のみであり、
> Priority / Actionは実装していなかった(§16 "Phase 4-B v1対象外"を
> 参照)。**Priority層はPhase 4-Cで実装済み** —
> docs/product/PRIORITY_ENGINE.md参照。Actionは引き続き未実装。

## 1. Purpose

Phase 4-A(docs/product/EVALUATION_ENGINE.md)は、Laravelがdeterministicに

> Social channel の conversion_rate = 4.0%、baselineは4.9%、
> 統計的にhigh signal、below(不利な方向)

という「**何が起きているか**」を確定できるようにした。

Phase 4-Bの目的は、その確定済みFactの上に、

> なぜ起きている可能性があるか

についてAIに**制御された仮説(Diagnosis)**を生成させることである。ただし
AIは:

```text
Evaluationを再判断しない
Factを書き換えない
証拠のない原因を作らない
Priorityを決めない
Actionを提案しない
budgetを増減しない
targeting / creative / bid / landing page変更を提案しない
```

基本原則:

```text
Laravel = Eligibility判定, Evidence Package構築, Evidence Gate, 応答検証, 永続化
AI      = Evidence-gatedな候補集合の中から1つのcategory_keyを選ぶだけ
```

## 2. 最終Architecture

```text
Raw Data
↓
Aggregation
↓
Derived Metrics                          [AI, Planning — Diagnosisは非依存]
↓
Evaluation                                [Laravel deterministic, Phase 4-A]
    EvaluationFact
↓
Final Analyze                             [AI, Decision-enabledならDescriptive only]
↓
Diagnosis Eligibility                     [Laravel deterministic]
↓
Evidence Gate                             [Laravel deterministic]
↓
Controlled Diagnosis AI                   [AI, dynamic enum]
↓
Laravel Validation
↓
DiagnosisResult (DB)
↓
markCompleted()
```

`markCompleted()`より**前**にDiagnosisが完了する——「Completed表示後に
Diagnosisが遅れて追加される」中間状態は存在しない(§13参照)。

## 3. Final Analyze Boundary(責務縮小)

Phase 4-B v1から、**Decision-enabled AnalysisJob**(§4)についてのみ、
Final Analyze(`AiAnalysisClient::analyze()`)の責務を「記述的分析
(Descriptive Analysis)」のみへ縮小する。

引き続き担当:

- Summary / Highlights / Metrics / Tables
- Descriptive Insights(観測済みFactの説明)
- 事実ベースの比較の説明

担当しない(Decision-enabledの場合):

- causal diagnosis(原因特定)
- Priority(high/medium/low)の業務判断
- budget increase/decrease
- targeting / creative / bid / landing page変更提案
- execute action全般

**Final Analyze自体は削除していない。** `BuildAnalysisContextAction::systemInstruction()`
の既存Rule 1-22は一切変更せず(削除・書き換えなし)、Decision-enabledの
場合のみRule 23-28を**追記**する:

```text
23. This analysis is Decision-enabled. A separate deterministic
Evaluation layer and a dedicated Diagnosis layer handle evaluation,
diagnosis, priority, and action responsibilities for this data.

24. Do not diagnose causes. Do not state or imply why a metric moved.

25. Do not assign priority such as high, medium, or low.

26. Do not recommend operational actions, including:
- budget increase or decrease
- targeting changes
- creative changes
- bid changes
- landing-page changes

27. "recommendations" must be an empty array. Express any noteworthy
observation as a descriptive insight instead.

28. Restrict your output to descriptive analysis: what happened,
not why it happened, and not what should be done.
```

Structured Output Schema(`recommendations[].priority`を含む)自体は
**変更していない** — 後方互換性維持(§4-B "Legacy recommendations")。
Rule 23-28はpromptレベルの制約であり、AI応答を100%保証するものではない
——最終的にはschema/専用Layerへ移す前提での第一歩(Future Scope)。

## 4. Decision-enabled Analysisの定義

```php
$decisionEnabled = $analysisJob->template_key !== null
    && array_key_exists($analysisJob->template_key, config('evaluation_metrics', []));
```

個別Template名でのhardcode(`if ($templateKey === 'ad_performance')`)は
一切行っていない。`config('evaluation_metrics')`にエントリを持つ
Templateだけが対象——v1では`ad_performance`のみ。

| AnalysisJob種別 | Decision-enabled? | Final Analyze | Diagnosis |
|---|---|---|---|
| Free Analysis(`template_key === null`) | ❌ | 既存動作を維持 | 実行しない |
| `ad_performance` | ✅ | Descriptive only | 実行する |
| `sales_analysis`(evaluation_metrics.phpにエントリなし) | ❌ | 既存動作を維持 | 実行しない |

`sales_analysis`に将来`config/evaluation_metrics.php`のエントリが追加
されれば、コード変更なしに自動的にDecision-enabledへ移行する。

### Legacy recommendations(後方互換性)

- `AiAnalysisClient::openAiResultSchema()`の`recommendations`schemaは
  削除していない。
- `NormalizeAnalysisResultAction`も変更していない。
- `analysis_job_details.result`のJSON構造も不変。
- Decision-enabledの新規結果は、Rule 27によりAI自身が`recommendations: []`
  を返す(Laravel側で強制的に書き換える後処理は追加していない)。
- 過去に保存されたrecommendations(Free Analysis / sales_analysis /
  Phase 4-B以前のAnalysisJob)はUI上引き続き表示される(§14「Recommendation UI」)。

### Legacy Priority

`recommendations[].priority`フィールド自体はschema互換性のため残す。
Decision-enabledの新規結果ではrecommendations自体が空になるため
priorityも実質出現しない。**Priority Engine(Laravel deterministic
formula)はPhase 4-Cで実装済み**(docs/product/PRIORITY_ENGINE.md) —
ただし新設の`PriorityResult`は`recommendations[].priority`とは完全に
別のテーブル/UIであり、legacy AI priorityフィールド自体の廃止はまだ
実施していない(後方互換性維持のため、Free Analysis/sales_analysis/
Phase 4-B以前のAnalysisJobでは引き続きUI表示される、§14参照)。

## 5. Diagnosis Eligibility

新規Action: `App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction`

```text
evaluation_level ∈ {high, medium}     (whitelist)
AND
direction == config('evaluation_metrics.{template}.metrics[].unfavorable_direction')
```

新しいconfig keyは追加していない——既存`config/evaluation_metrics.php`
の`unfavorable_direction`をそのまま使う。

対象外:

- `low` / `insufficient_data` / `equal`
- favorable方向(direction ≠ unfavorable_direction)
- 未知の将来`evaluation_level`値(whitelist方式のため自動的に対象外)

`insufficient_data`は**物理的に**Diagnosis AI callが発生しないコード
パスになっている(Eligibility判定で除外され、`RunDiagnosisForAnalysisJobAction`
のループへ到達しない)。Promptによる自己抑制には依存していない。

favorable anomaly(例: Above + High)もv1では一切対象外——「なぜ成功
したか」「予算を増やすべきか」はPhase 4-Bのスコープ外(将来Opportunity /
Success Pattern Layerの領域)。

## 6. Evidence Package

新規Action: `App\Actions\Diagnosis\BuildDiagnosisEvidencePackageAction`

```json
{
  "trigger_fact": {
    "evidence_id": "trigger:evaluation_fact:123",
    "evaluation_fact_id": 123,
    "entity_type": "channel",
    "entity_key": "Social",
    "metric_key": "conversion_rate",
    "metric_value": 0.035,
    "display_baseline_value": 0.049,
    "test_baseline_value": 0.052,
    "delta_absolute": -0.014,
    "delta_percent": -0.2857,
    "direction": "below",
    "evaluation_level": "high",
    "numerator_value": 420,
    "denominator_value": 12000,
    "control_numerator_value": 2520,
    "control_denominator_value": 48000,
    "z_score": -6.02
  },
  "supporting_facts": [
    { "evidence_id": "supporting:spend", "metric_key": "spend", "value": 150000 },
    { "evidence_id": "supporting:revenue", "metric_key": "revenue", "value": 300000 }
  ],
  "allowed_categories": ["insufficient_explanatory_evidence"]
}
```

`business_context`(user_prompt由来)は**含まない**(§10)。

### trigger_fact: 選択されたEvaluation snapshot(DB行のverbatim copyではない)

Diagnosisに必要な、EvaluationFactの既に計算済みの値をselectして送る。
Evaluation値自体(`metric_value`/baseline値/`direction`/`evaluation_level`/
`z_score`等)は再計算・再解釈せず、そのままコピーする——AIに:

- recompute
- reinterpret evaluation
- challenge significance
- change direction
- change baseline

させない(Diagnosis System Instruction Rule 1、§11)。ただし`rule_version`
/`computed_at`/`created_at`/`updated_at`等、Diagnosisに不要なDB列は
そもそも送らない——「EvaluationFactの全カラムをverbatimに複製したもの」
ではなく「Diagnosisに必要な値をselectしたsnapshot」である。

`evidence_id`はEvaluationFactには存在しないフィールドで、Diagnosis専用の
参照IDとして`BuildDiagnosisEvidencePackageAction`が追加する
(`trigger:evaluation_fact:{evaluation_fact_id}`)。AIがこのFactを
`evidence_refs`で一字一句引用できるようにするためだけに存在する——
実運用で、この`evidence_id`フィールドをpayloadへ明示的に含めずAIへ
「識別子形式」だけ暗黙に期待した結果、AIが独自形式("37"等)を生成し
全応答がreject される問題が実際に発生した(§13参照)。

### supporting_facts: deterministic、derived_metrics非依存

`aggregated_metrics`から、同一entity_keyの他measure(spend/revenue/
impressions等)を**生値のまま**抽出する。Planning AIが提案する
`derived_metrics`は**一切使わない**——Phase 4-Aと同じ理由(derived_metrics
はPlanning AIの非決定的判断に依存し、同じCSVでも存在・名称・計算対象が
変わり得るため、Evidenceとして不適格)。

metricの`numerator_field`/`denominator_field`セマンティックフィールドは
supporting_factsから除外する(trigger_factに`numerator_value`/
`denominator_value`として既に含まれているため、重複を避ける)。

Supporting Factsは`config/analysis_templates.php`の`fields`宣言順を
維持し、決定的な順序で返す。

**raw supporting factsはAIに評価させない**(§9)——「補助情報」に限定
する。ROAS/CPA等の比較値をSupporting Factとして計算する場合も、
`derived_metrics`ではなくLaravel側でdeterministicに計算する必要がある
(v1では未実装——大量のEvaluatorを追加しない方針、§16参照)。

### raw sample_rows / user_prompt

いずれも渡さない(§10)。理由:

- sample_rowsはEvaluationの再判断・偶然のサンプルからの因果推論に
  つながるリスクがある。
- user_promptはユーザーの主観的な仮説(「Socialが悪い原因を調べて」)を
  含み得るため、Fact判断への混入を避ける。

## 7. Evidence Gate(Evidence-gated Category Set)

`config/diagnosis_categories.php`が持つ各Category(`categories.*`)を、
`trigger_fact`(および必要ならsupporting_facts)へ照らし合わせ、**証拠が
存在するCategoryだけ**を`allowed_categories`として構築する。

```text
Category
↓
required evidence(deterministic pattern)
↓
available evidence(trigger_fact / supporting_facts)
↓
allowed_categories
```

**証拠のないCategoryはAIへそもそも見せない** — Promptで「証拠がなければ
選ばないでください」と頼むだけではない。`AiAnalysisClient::diagnose()`の
Structured Output Schemaの`category_key`は`allowed_categories`から動的に
`enum`を構築する(§9)ため、API自体が候補外のCategoryを返せない。

## 8. Category Catalog(v1: 2つのみ)

新規config: `config/diagnosis_categories.php`

| category_key | 意味 | required evidence | 診断価値 |
|---|---|---|---|
| `measurement_consistency_risk` | 計測/トラッキングの整合性を確認する価値がある、deterministicなpatternが存在する | `trigger_fact.metric_key`が`applicable_metrics`に含まれる AND `numerator_value === 0` AND `denominator_value >= 閾値`(既定5、`diagnosis_categories.thresholds.minimum_denominator_for_zero_conversion_risk`) | クリックはあるのにコンバージョンが完全にゼロ、という「単なる閾値未達」とは質的に異なるパターンをLaravelが機械的に検出できる |
| `insufficient_explanatory_evidence` | 現在のEvidenceでは原因を区別できない(Abstention) | なし(常にallowed) | 「分からない」を正直に返す唯一の必須Category |

### 「症状」Categoryを不採用にした理由

`conversion_efficiency_issue`/`cost_efficiency_issue`/`traffic_efficiency_issue`
のようなCategoryは、当初案として検討したが**不採用**とした。これらの
required evidenceは実質「trigger_factがunfavorable評価であること」——
Diagnosis Eligibility自体の条件と同一である。つまりEligible対象になった
時点で常に成立してしまい、AIがこれを選んでも「evaluation_levelがhigh/
mediumでdirectionがunfavorableだった」という、Laravelが既に確定させた
事実を別ラベルで繰り返しているだけになる。**Phase 4-BのDiagnosisは
「Why」を扱う層であり、「What」の言い換えには診断価値がない。**

`landing_page_mismatch`/`creative_fatigue`/`audience_mismatch`/`seasonality`
は、現行`ad_performance`CSV(channel/spend/revenue/clicks/conversions/
impressions)では証拠が存在しないため不採用。`other_unclassified`も、
「証拠が薄い時の説明」を`insufficient_explanatory_evidence`へ一元化する
ため不採用(2つの"分からない"カテゴリを併存させると境界が曖昧になる)。

### performance_tradeoff: v1で不採用(Future Scope)

「CVRは低いがROASは良好」のようなtradeoffをdeterministicにEvidence化
するには、比較対象となる2つ目の評価済みMetric(baseline比較込み)が
必要——現状`config/evaluation_metrics.php`の`ad_performance`エントリは
`conversion_rate`1metricのみで、この条件を満たす手段がない。v1では
このCategoryを採用せず、Catalogを2つに絞ることを優先した。将来
`config/evaluation_metrics.php`へ2つ目のMetric(例: `return_on_ad_spend`)
を追加すれば、Phase 4-A自身の仕組みで比較済みFactが作られ、
`performance_tradeoff`のrequired evidenceとして使える見込み——ただし
これはPhase 4-A configの拡張を伴うため、Phase 4-B v1のスコープ外。

## 9. Abstention

`insufficient_explanatory_evidence`は常に`allowed_categories`へ含まれる。
これは:

- error
- refusal
- validation failure

ではなく、**正常なDiagnosis Result**である。原因を区別できるEvidenceが
なければ、AIは積極的にこのCategoryを選ぶべき(Diagnosis System
Instruction Rule 6)。

Abstention Rateの測定は§15参照。

## 10. measurement_consistency_risk semantics

このCategoryは「**確認する価値がある候補の1つ**」であることを意味する
のであって、「**確定した**」ことも「**確からしい**」ことも意味しない。
`numerator_value === 0`という結果は、計測/トラッキングの問題からでは
なく、**本当にconversionが0件だった**という真正な結果である可能性も
同程度に残る——AIはこの2つの可能性のどちらか一方を優先してはならない。

AIは以下を断定・示唆してはならない(Diagnosis System Instruction Rule 7、
`config/diagnosis_categories.php`の`description`、UIラベル(§17)いずれも
同じ方針で統一):

```text
悪い例: "Tracking is confirmed broken."
悪い例: "Measurement failure is likely."
悪い例: "This is probably a tracking issue rather than a genuine
        zero-conversion outcome."

良い例: "A deterministic pattern (zero conversions despite meaningful
        traffic) makes measurement/tracking consistency one possible
        area worth verifying. It does not establish a measurement or
        tracking problem — a genuine zero-conversion outcome remains an
        equally possible explanation."
```

## 11. Structured Output

`AiAnalysisClient::diagnose()`を追加(同一クラス内——`AiDiagnosisClient`
への分離はv1では行わない。`analyze()`/`planMetrics()`/`mapColumns()`
と同じ「1メソッド=1つの独立したHTTPリクエスト実装」という既存設計
パターンを踏襲)。

```json
{
  "primary_diagnosis": {
    "category_key": "insufficient_explanatory_evidence",
    "self_reported_confidence": 0.4,
    "rationale_summary": "...",
    "evidence_refs": ["trigger:evaluation_fact:123"],
    "missing_evidence": ["landing-page-level conversion rate"]
  }
}
```

- `alternatives`は**なし**(v1は1件のみ)。
- Priority/Actionに相当するフィールドは**schemaに存在しない**——prompt
  で禁止するより強い保証(APIがそもそも返せない)。
- `category_key`の`enum`は、この特定リクエストの`allowed_categories`
  から動的に構築される(`derivedMetricsPlanSchema()`の`group_by` enum
  と同じ設計思想 — 実在する値だけをAPIレベルで制約する)。
- `evidence_refs`は**最低1件必須**(`minItems: 1`)。Evidence-groundingの
  最低保証——`insufficient_explanatory_evidence`(Abstention)であっても、
  少なくとも`trigger_fact`の`evidence_id`は必ず引用する。「原因を特定
  できない」という結論自体も、何を評価した結果そう判断したのかが
  常にtraceできる必要があるため(§12で再検証)。standalone probeで、
  OpenAI Responses APIの strict Structured Outputが`minItems`を実際に
  尊重することを確認済み——ただし「空配列を返すな」という指示に対し、
  空文字列1件の配列(`[""]`)で数だけ満たそうとする挙動も観測されたため、
  schema制約だけでは不十分であり、Laravel post-validation(§12)が
  実際の強制力を持つ。

Diagnosis System Instructionは15 Rules(実装は
`RunDiagnosisForAnalysisJobAction::systemInstruction()`)。要旨:

```text
1.  trigger_factはimmutable。recompute/challengeしない。
2.  supplied evidenceのみ使う。
3.  category_keyはallowed_categoriesからのみ選ぶ。
4.  存在しないデータを発明しない。
5.  distinguishing evidenceなしにlanding-page/creative/audience/
    seasonality/tracking failureを推測しない。
6.  区別できなければinsufficient_explanatory_evidenceを選ぶ(正常)。
7.  measurement_consistency_riskは"確認する価値がある候補の1つ"であり、
    "確定した"でも"確からしい"でもない。distinguishing evidenceなしに
    tracking failureがconfirmed/probable/more likelyだと述べない
    (genuine zero-conversion outcomeも同程度に可能性が残る、§10)。
8.  priorityを付与しない。
9.  actionを推奨しない。
10. supporting factsを勝手に良し悪し評価しない。
11. evidence_refsはsupplied evidence identifier(trigger_fact/
    supporting_factsの`evidence_id`)のみ、かつ**最低1件必須**——
    insufficient_explanatory_evidenceでもtrigger_factのevidence_idは
    引用する。
12. missing_evidenceはdata/evidenceの種類であり、actionではない。
13. self_reported_confidenceはcategory選択への不確実性であり、
    trigger_factへの確信度ではない。trigger_factはこのDiagnosis step
    において固定であり、再評価しない("certain"という語は使わない——
    §で後述する誤解回避のため)。
14. structured outputのみ返す。
15. rationale_summaryはevidenceの説明であり、対応策の説明ではない。
```

### なぜRule 13で"certain"という語を使わないか

当初Rule 13は「trigger_factは`already certain`」という表現だったが、
以下2つの理由で「`fixed for this Diagnosis step`」(このDiagnosis step
において固定/再評価しない)へ修正した:

1. EvaluationFactはLaravelがdeterministicに算出した、このDiagnosis step
   上の固定Factではあるが、「世界について絶対に確実」という意味では
   ない——"certain"は意味が強すぎる。
2. Real API E2Eで、`insufficient_explanatory_evidence`という**原因を
   特定できない**という結論であるにも関わらず`self_reported_confidence`
   が0.96〜0.99と非常に高い値になるケースが観測された。"certain"という
   語がtrigger_factの文脈で使われることで、AIがDiagnosis自体の
   confidenceまで不要に引き上げてしまう可能性を避けるため、"certain"を
   使わない表現へ統一した。

Few-shot examplesはv1では**含めない**——Structured Output(strict enum)
による文法的制約を優先し、semantic qualityへのfew-shotの効果は今後の
Golden Eval / 本番運用の実測(Abstention Rate, Hallucinated Evidence Rate)
を経てから追加を検討する(Future Scope)。

## 12. Laravel post-validation

`App\Actions\Diagnosis\NormalizeDiagnosisResultAction`が、Structured
Output(dynamic enum)だけに依存せず、以下を独立して再検証する:

```text
category_key   ∈ allowed_categories (再チェック)
confidence     0.0 〜 1.0
rationale_summary  非空文字列
evidence_refs      非空(最低1件) ★★ かつ ⊆ supplied evidence identifiers ★
missing_evidence   文字列配列
```

★★: Evidence-groundingの最低保証。`evidence_refs === []`は、他の
フィールドがすべて有効でも単独でrejectする——「DiagnosisResultは必ず
supplied Evidenceへtrace可能」というPhase 4-Bの設計原則を、
`insufficient_explanatory_evidence`(Abstention)であっても例外なく
適用する。Schema側の`minItems: 1`(§11)は第一防衛線に過ぎず、この
Laravel検証が実際の強制力を持つ(§11で述べた`[""]`のような
やり方で数だけ満たす抜け道も、既存の★(supplied evidence identifiersへの
member判定)がそのまま防ぐ——空文字列`""`はどのentityの`evidence_id`
とも一致しないため)。

★の検証こそがHallucinated Evidence Rate(§15)を実際に0%へ抑える
唯一の機構——`evidence_refs`はJSON Schema enumで表現できない
(「このリクエストが供給した識別子の任意の部分集合」は固定enumでは
書けない)ため、Laravel側の検証が必須。

"Structured Output + Laravel post-validation + Evidence gating"の3層
防御(`docs/product/DIAGNOSIS_ENGINE.md`自身がこの設計方針を示す
最初の実装例)。

いずれかの検証に失敗した場合は`InvalidArgumentException`を投げ、
呼び出し元(`RunDiagnosisForAnalysisJobAction`)がper-entity soft-fail
として処理する(§14)。

## 13. Pipeline位置とEvidence refs形式

```text
trigger:evaluation_fact:{evaluation_fact_id}
supporting:{metric_key}
```

Pipeline順序(§2で図示): Evaluation → Final Analyze → **Diagnosis** →
markCompleted()。DiagnosisはmarkCompleted()の**前**に実行される
——「Completed表示後にDiagnosisが遅れて追加される」中間状態を避ける
ため。`ExecuteAnalysisJobAction::execute()`内、`NormalizeAnalysisResultAction`
の直後・`markCompleted()`の直前に挿入されている。

## 14. Persistence / Idempotency / Soft-fail

新規Action: `App\Actions\Diagnosis\RunDiagnosisForAnalysisJobAction`
(top-level orchestrator。`EvaluateAnalysisJobAction`と同じ形——Queue非依存の
plain synchronous Action)。

### DiagnosisResult Schema

```text
diagnosis_result_id        PK
analysis_job_id             FK -> analysis_jobs, cascadeOnDelete
evaluation_fact_id          FK -> evaluation_facts, cascadeOnDelete, UNIQUE
category_key                 string(100)
self_reported_confidence     decimal(4,3) nullable — uncalibrated
rationale_summary            text
evidence_refs_json           json
missing_evidence_json        json
supporting_facts_json        json — 監査用スナップショット(§14.3)
raw_response                  longText nullable
model                          string(100)
prompt_version                  string(50)
timestamps
```

v1: `EvaluationFact hasOne DiagnosisResult`(1 EvaluationFact = 1 Primary
Diagnosis)。将来のmultiple sampling拡張時は`hasMany`へ昇格可能——
外部キー方向は変わらない。

### Idempotency: delete-first(Evaluationの"delete + recreate"とは順序が異なる)

```php
DiagnosisResult::query()->where('analysis_job_id', $id)->delete();  // 最初に実行
// ... その後Eligibility判定 → per-entity Diagnosis
```

`EvaluateAnalysisJobAction`の"delete + recreate"は計算完了後の**最後**に
一括置換するのに対し、Diagnosisは**最初**に既存行を削除してから計算を
始める。これにより、計算のどの段階で失敗しても古い行が「今回の結果」
として誤って残ることが構造的に起こり得ない——Evaluationのような
別途`clearForAnalysisJob()`呼び出しが不要。

また`evaluation_fact_id`への`cascadeOnDelete()`により、
`EvaluateAnalysisJobAction`自身の delete+recreateが実行されるたびに
古いDiagnosisResultは自動的にcascade削除される(stale cleanupの一次
機構)。上記の明示的deleteはその上のdefensive backstop。

### Per-entity soft-fail

```php
foreach ($eligibleFacts as $fact) {
    try {
        // Evidence Package -> diagnose() -> Normalize -> 保存
    } catch (Throwable $e) {
        Log::error(...);  // 保存しない、次のfactへ継続
    }
}
```

1つのEvaluationFactのDiagnosis失敗(AI provider error、invalid
category、hallucinated evidence_ref等)が、同じAnalysisJob内の他の
eligibleなEvaluationFactのDiagnosisを妨げない。

### Queueへ伝播しない

`AiAnalysisClient::diagnose()`が投げる`RuntimeException`(timeout/429/
5xx/network/refusal/incomplete)も、`NormalizeDiagnosisResultAction`が
投げる`InvalidArgumentException`(semantic failure)も、**いずれも
Laravel Queueへは伝播しない**——`analyze()`/`planMetrics()`とは異なる
設計判断。理由: DiagnosisはFinal Analyzeより後に位置する追加機能であり、
Diagnosis 1件のtimeout/429のためだけにQueue全体を再試行すると、既に
成功しているMapping/Planning/Final AnalyzeのAI呼び出しまで再課金・
再実行されてしまう。Phase 4-A(Evaluation)の「技術的例外は全て
soft-fail」という設計原則を踏襲した。

`ExecuteAnalysisJobAction`側でも、`RunDiagnosisForAnalysisJobAction::execute()`
の呼び出しを`try/catch(Throwable)`で囲んでいる——per-entity catchで
既にほぼ全ての失敗は吸収されるが、cleanup delete自体の失敗のような
真のorchestration-level例外に対する二段目の防御。

### Semantic stronger-prompt retryはv1未実装

Structured Output(strict enum) + dynamic enum + Laravel
post-validationにより、format failure自体が大きく減ることを期待し、
v1では単純にsoft-failのみとする。実測(Golden Eval / 本番運用)を経て、
必要であれば将来「同一Job内でstronger prompt retryを1回」を検討する
(Future Scope)。

## 15. Auditability / Golden Eval / 測定指標

### 保存される監査情報

`evaluation_fact_id`(→trigger_fact復元) / `category_key` /
`evidence_refs_json` / `missing_evidence_json` / `supporting_facts_json`
(監査用スナップショット——aggregated_metrics自体は永続化されないため)/
`self_reported_confidence` / `raw_response` / `model` / `prompt_version`。

`input_snapshot`(Evidence Package全体)は保存しない——trigger_fact部分は
`evaluation_fact_id`のJOINで完全復元可能、`allowed_categories`は
config + prompt_versionから再計算可能なため、冗長な保持を避けた。

### Golden Diagnosis Eval Dataset

`tests/fixtures/diagnosis_eval/*.json` — Case A/B/Cの実データ検証結果
から構築した10ケース(favorable/insufficient_data含む)+
`measurement_consistency_risk`専用fixture1件。各fixtureは:

```json
{
  "trigger_fact": {...},
  "supporting_facts": [...],
  "expected": {
    "eligible": true,
    "allowed_categories": [...],
    "forbidden_categories": [...],
    "must_abstain": true,
    "forbidden_phrases": [...]
  }
}
```

`tests/Feature/Diagnosis/GoldenDiagnosisEvalTest.php`が、Eligibility /
Evidence Gateの**deterministicな半分**をこれらのfixtureに対して検証
する(AI呼び出しなし、CI高速実行)。`must_abstain`/`forbidden_phrases`
はReal API E2E実行時に同じfixtureを再利用して実際のAI出力を検査する
想定(§15の下記)。

Provider-neutral設計を維持するため、OpenAI Evalsではなく**repo内
fixture + custom eval script**を採用した(`AiAnalysisClient`が
OpenAI依存を1箇所に閉じ込める設計思想と整合)。

### Hallucinated Evidence Rate

`evidence_refs ⊆ supplied evidence`の検証(§12)により構造的に0%を
担保——違反したDiagnosisResultは保存されない。目標: 0%。

### Abstention Rate

`diagnosis_results.category_key = 'insufficient_explanatory_evidence'`
の割合。専用テーブルは設けず、都度クエリで算出する。高すぎても
(Diagnosisが役に立たない)低すぎても(無理に原因を作っている可能性)
注視すべき指標として、Real API E2E報告に含める。

## 16. Phase 4-B v1対象外(Future Scope)

- ~~Priority Engine~~ → **Phase 4-Cで実装済み**(docs/product/PRIORITY_ENGINE.md)。Action Catalog / Action AIは引き続き未実装。
- budget allocation / execute action
- `performance_tradeoff`カテゴリ(§8「Future Scope」参照 — config/evaluation_metrics.php拡張が前提)
- confidence calibration batch / multiple sampling / self-consistency voting
- user feedback(confirmed/rejected/corrected category)/ confusion matrix
- historical diagnosis / diagnosis trend
- `alternatives`フィールド / `other_unclassified`カテゴリ
- pipeline_run_id / Bus::batch orchestration / 専用AI queue / circuit breaker / rate limit middleware
- Temporal Analysis / Opportunity Layer(favorable anomaly診断)
- Semantic stronger-prompt retry
- `max_diagnosis_candidates_per_job`(Eligible EvaluationFact数が極端に
  多いCSVでAI call数が際限なく増えることを防ぐ上限。v1では
  `max_cardinality_per_dimension`(既定20)が実質的な上限として機能して
  いるため優先度低)

## 17. UI

`resources/views/analysis-jobs/show.blade.php`に、Evaluationセクション
の直後へ「原因の仮説」セクションを追加した(全面UI改修は行っていない):

- Diagnosis対象外(favorable/low/insufficient_data)のEntity: 何も表示しない
- 対象かつDiagnosisResultあり: category label(日本語) / rationale_summary
  / missing_evidence
- 対象だがDiagnosisResultなし(per-entity soft-fail): 「診断結果を取得
  できませんでした」
- `self_reported_confidence`: **表示しない**(uncalibrated、§11参照)

「対象かどうか」は専用DBカラムを持たず、`DetermineDiagnosisEligibilityAction`
を表示時に再実行して導出する(pure/deterministic/副作用なしのため、
再計算しても矛盾しない——Evaluation Engine自体の「Factをdeterministicに
再現可能にする」設計思想と同じ)。

Category日本語ラベル(Blade内定義):

```text
measurement_consistency_risk        → 「計測整合性の確認候補」
insufficient_explanatory_evidence   → 「十分な根拠がありません」
```

`measurement_consistency_risk`のラベルは当初「計測整合性の確認が必要な
可能性」だったが、「確認候補」へ弱めた——「必要」という語が任意選択肢
以上の重みを持って読める余地を避けるため。「計測異常」「トラッキング
異常」「計測問題」のような、Categoryを見ただけで「原因が確定した」と
受け取れる表現は採用していない(§10参照)。

見出しは英語「Diagnosis」ではなく日本語「原因の仮説」を採用——
「仮説」という語が「まだ検証されていない推測」であることを明示し、
断定を避けるため。内部Architecture名としては引き続き"Diagnosis"を使う。

`recommendations`が空配列の場合、Recommendationsセクション自体を非表示
にする(legacy/新規問わず一律)。過去AnalysisJobにrecommendationsが
ある場合は従来通り表示される(§4「Legacy recommendations」)。

## 18. AI Call Count

固定+1ではなく、**Eligible EvaluationFact数 × 1**:

```text
正常経路(Template, Decision-enabled): Mapping(1) + Planning(1) + Final Analyze(1) + Diagnosis(Eligible数)
```

Eligible数が0件のAnalysisJob(全EntityがInsufficient data/favorable/low)
では、Diagnosis AI Callは**0**——固定オーバーヘッドではない。

## 19. Product Validation (Case A/B/C, Browser E2E)

Real Browser UI 経由で3つのsynthetic CSV(Case A: 均等トラフィック,
Case B: 低サンプル, Case C: 支配的channel)を実行し、Evaluation →
Diagnosis Eligibility → Evidence Gate → Diagnosis AI の一連の挙動を
確認した(Phase 4-B完了時点の検証)。各Caseのtrigger_factは
`tests/fixtures/diagnosis_eval/case_{a,b,c}_*.json` に regression
fixture として保存されており、CIで継続的に再検証される。

### Case A — Balanced Traffic (全channel clicks=12,000)

| Entity | CVR | Baseline | Direction | Level | Diagnosis Eligibility | Diagnosis Outcome |
|---|---|---|---|---|---|---|
| Display | 4.50% | 4.90% | Below | Medium | eligible | `insufficient_explanatory_evidence` |
| Social | 3.50% | 4.90% | Below | High | eligible | `insufficient_explanatory_evidence` |
| Email | 5.50% | 4.90% | Above | High | not eligible(favorable) | Diagnosisなし |
| Paid Search | 6.00% | 4.90% | Above | High | not eligible(favorable) | Diagnosisなし |
| Organic | 5.00% | 4.90% | Above | Low | not eligible(low) | Diagnosisなし |

確認結果: **PASS**。確認できた重要ポイント:

- Final AnalyzeからRecommendations / Priority / Actionが消えた
  (Descriptive-only境界が有効)
- unfavorable High/MediumのみDiagnosisが実行される、favorable High
  はDiagnosisされない
- Evidence不足時にAIがcreative/landing-page/audience等を断定しない
  (Abstentionが正しく機能)

### Case B — Low Sample

| Entity | CVR | Direction | Level | Diagnosis Eligibility | Diagnosis Outcome |
|---|---|---|---|---|---|
| Display | 3.33% | Below | Medium | eligible | `insufficient_explanatory_evidence` |
| Email | 7.50% | Above | **Insufficient data** | not eligible | AI call 0 |
| Social | 2.50% | Below | **Insufficient data** | not eligible | AI call 0 |
| Organic | 5.50% | Below | Low | not eligible(low) | Diagnosisなし |
| Paid Search | 6.00% | Above | Low | not eligible(low) | Diagnosisなし |

確認結果: **PASS**。最重要ポイント: 「見かけ上の差が大きい」≠
「Diagnosisしてよい」という安全境界の成立。Email 7.5%(40 clicks/
3 conversions)・Social 2.5%(80 clicks/2 conversions)、どちらも
見た目のCVR差は大きいが `insufficient_data` としてDiagnosis自体が
実行されない。Phase 4-Cでもこの考え方を維持した(§8参照、Priority
Eligibilityも同一条件)。

### Case C — Dominant Channel

| Entity | CVR | Baseline | Direction | Level | Diagnosis Eligibility | Diagnosis Outcome |
|---|---|---|---|---|---|---|
| Display | 4.00% | 5.04% | Below | High | eligible | `insufficient_explanatory_evidence` |
| Email | 3.00% | 5.04% | Below | High | eligible | `insufficient_explanatory_evidence` |
| Organic | 6.00% | 5.04% | Above | High | not eligible(favorable) | Diagnosisなし |
| Social | 8.00% | 5.04% | Above | High | not eligible(favorable) | Diagnosisなし |
| Paid Search | 5.00% | 5.04% | Below | Low | not eligible(low) | Diagnosisなし |

確認結果: **PASS**。重要: SocialがAbove + Highでも(1) Diagnosisされ
ない (2) Priorityもまだ存在しない(Phase 4-B時点)(3) budget increase
等も生成されない——Phase 4-A/4-Bの責務境界が成立していることを確認。

### Product Validationから得たPriority設計上の教訓(Phase 4-C投入)

| # | 教訓 | Phase 4-Cへの適用 |
|---|---|---|
| A | High = 悪い、ではない | Priority候補は原則unfavorable側のみを対象にする |
| B | insufficient_dataはDiagnosis AIすら呼ばれない | Priorityも同じEligibility外に置く |
| C | Evaluation LevelとPriorityは別概念 | 同じHigh/Medium/Lowラベルでも Source of Truth を分ける |
| D | `insufficient_explanatory_evidence`でもEvaluation自体は強い場合がある | 「原因不明 = Priority低」ではない |

Phase 4-Cでの実装・再検証(Case A/B/C + Dominant Bad Channel / Tiny
Severe Channel synthetic case)は docs/product/PRIORITY_ENGINE.md
§24-25を参照。
