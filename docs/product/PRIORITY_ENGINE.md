# Deterministic Priority Layer Design (Phase 4-C v1)

> **Target Architecture reference**: ReportFlow AIのPhase 4全体は
> `Facts → Evaluation → Diagnosis → Priority → Action` という5層の
> Target Architectureとして設計参考資料が存在する(docs/product/EVALUATION_ENGINE.md
> / docs/product/DIAGNOSIS_ENGINE.md 冒頭も参照)。今回実装したのは
> `Priority`層のみであった。Controlled Action Layer v1は後続のPhase 4-Dで
> 実装済み — docs/product/CONTROLLED_ACTION_LAYER.md参照。
>
> **v1.1 revision note**: commit前レビューでGap算出基準の問題
> (自己希釈、§8-1)が指摘され、`gap_raw_value`の基準を
> `display_baseline_value`から`test_baseline_value`(leave-one-out
> peer/control)へ変更した。Formula自体の形(`impact_score × gap_score`)
> やBand thresholds(0.35/0.10)は変更していない——§8-1・§12参照。

## 1. Purpose

Phase 4-A(EVALUATION_ENGINE.md)は「何が起きているか」を、Phase 4-B
(DIAGNOSIS_ENGINE.md)は「なぜ起きている可能性があるか」を確定させた。
Phase 4-Cの目的は、その上に

> どの問題を先に確認・調査すべきか

を**Laravelだけでdeterministicに決める**ことである。基本原則はPhase
4-A/4-Bと同一:

```text
Laravel = Eligibility判定, Impact計算, Gap計算, Score算出, Band判定, 永続化
AI      = 一切参加しない(新規AI call = 0)
```

## 2. Definition — Priorityとは何か

> **Priority = 「このEvaluation signalをユーザーが確認・調査する優先度」
> であり、「この施策を実行する優先度」ではない。**

```text
High Priority ≠ increase budget
High Priority  = 先に確認する価値が高い
```

Diagnosis(Phase 4-B)が「Why」を扱う層であるのに対し、Priority(Phase
4-C)は「Which first」を扱う層。Action(将来Phase)は「What to do」を
扱う層——3層は完全に独立した責務を持つ。

## 3. Final Architecture

```text
Raw Data
↓
Aggregation
↓
Derived Metrics                          [AI, Planning — Priorityは非依存]
↓
Evaluation                                [Laravel deterministic, Phase 4-A]
    EvaluationFact
↓
Priority                                  [Laravel deterministic, Phase 4-C]
    PriorityResult
↓
Final Analyze                             [AI, Decision-enabledではDescriptive only]
↓
Diagnosis Eligibility
↓
Evidence Gate
↓
Controlled Diagnosis AI                   [Phase 4-B]
↓
DiagnosisResult
↓
markCompleted()
```

Priorityは`EvaluateAnalysisJobAction`呼び出しの直後・
`BuildAnalysisContextAction`呼び出しの直前に挿入されている(§10)。
Diagnosisより**前**に実行される——Priorityの計算はAI非依存であり
Diagnosis AIの成否に一切影響されないため、先に確定させておく方が自然
(execution orderとdisplay orderは独立に決めてよい。UI表示順は
Evaluation → Priority → Diagnosisの並びを採用した、§14)。

## 4. Priority Eligibility

新規Actionは作らず、`App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction`
を**そのまま再利用**する:

```text
evaluation_level ∈ {high, medium}     (whitelist)
AND
direction == config('evaluation_metrics.{template}.metrics[].unfavorable_direction')
```

対象外: `low` / `insufficient_data` / `equal` / favorable方向 /
未知の将来`evaluation_level`値。

### なぜDiagnosis名前のActionを再利用するか(rename/refactorしない理由)

Priority EligibilityはDiagnosis Eligibilityと**意味的に完全に同一の
条件**である(Phase 4-B実装時に既にこの条件は確定している)。新しい
Priority専用のEligibility Actionを作ると、同じルールを2箇所に
duplicateすることになり、将来どちらか一方だけがズレる(Diagnosis
Eligibilityは拡張されたのにPriority Eligibilityは古いまま、等)構造的
リスクを生む。`App\Actions\Diagnosis`名前空間のクラスをPriority側から
呼ぶこと自体は不自然に見えるが、大規模なrename/refactor(例:
`App\Actions\Shared\DetermineUnfavorableAnomalyEligibilityAction`への
移動)は本Phaseのスコープ外——過剰設計を避けるため、v1ではこの再利用を
そのまま採用する。将来Priority Eligibilityの条件がDiagnosis
Eligibilityから分岐する具体的なユースケースが生じた時点で、初めて
分離を検討する。

`PrioritizeAnalysisJobAction`のコンストラクタは
`DetermineDiagnosisEligibilityAction`を直接依存として持つ(§10)。
UI側(`AnalysisJobController::show()`)も同様に、既に計算済みの
`$diagnosisEligibility`配列を`$priorityEligibility`としてそのまま
再利用する——2回目の計算をせず、意図の違いをBlade側の変数名で表現する
のみ(§15)。

> **運用上の注意**: 現在Priority EligibilityとDiagnosis Eligibility
> は条件が完全に一致するためこの再利用が成立している。**将来
> `DetermineDiagnosisEligibilityAction`の条件そのものを変更する際は、
> 必ずPriorityへの影響(Priority対象の範囲が意図せず変わらないか)を
> 確認すること。** 変更者がDiagnosis側だけを見て条件を調整すると、
> Priority側のEligibilityも暗黙に連動して変わってしまう——この結合は
> v1では意図的な設計判断だが、暗黙的でもある。

## 5. Impact — 定義

> **Impact = 「このmetric anomalyが全体Businessへ及ぼす可能性のある
> 影響範囲」**

`conversion_rate`の場合、traffic volume(clicks = denominator)が
このanomalyの直接の母数であるため、Impact Basisとして採用する
(`impact_basis = "denominator_share"`)。

## 6. Impact Score

```text
impact_score = impact_value / impact_total
```

- `impact_value` = このEvaluationFact自身の`denominator_value`
- `impact_total` = §7で定義する母集団合計

Raw share以外の正規化(sqrt/log/percentile/bucket)はv1では採用しない
——単純で再現可能・説明可能なものを優先した(§17の比較を参照)。

## 7. Impact Total母集団定義(最重要)

`impact_total`は**Priority-eligibleなEvaluationFactだけの合計ではない**。

正しくは:

```text
同一AnalysisJob
+
同一metric_key
に属する
「valid denominatorを持つ全EvaluationFact」のdenominator合計
```

"valid denominator"の条件(`PrioritizeAnalysisJobAction::impactTotalsByMetric()`):

```text
denominator_valueが non-null AND > 0
```

`evaluation_level`は問わない——favorable/low/insufficient_dataの
EvaluationFactでも、denominator自体がvalidならImpact母集団には含める。
理由: 全体traffic shareを正しく表すため。Eligibleなfactだけをtotalに
すると、「eligibleな数entityだけの世界の中でのシェア」という意味の
異なる値になってしまう(例: Case Cで、もしeligibleなDisplay+Emailの
denominator合計=5,000だけをtotalにすると、Displayの共有は
2,500/5,000=50%という誤った値になる——実際のtotalは100,000で、
正しい共有は2.5%)。

## 8. Gap — 定義

> **v1.1で変更(commit前レビューで発見・修正)**: 当初実装は
> `gap_raw_value = abs(EvaluationFact.delta_absolute)`
> (= `abs(metric_value - display_baseline_value)`)だったが、これは
> §8-1で説明する自己希釈(self-dilution)問題を持つため、
> **`test_baseline_value`基準へ変更した**。以下は変更後(最終)の定義。

```text
gap_raw_value = abs(
    EvaluationFact.metric_value
    -
    EvaluationFact.test_baseline_value
)
```

`metric_value`(このentity自身の値)と`test_baseline_value`
(leave-one-out peer/control baseline、Phase 4-Aが既にdeterministicに
算出・保存済み — EVALUATION_ENGINE.md §10参照)の差の絶対値。
どちらも**再計算しない**——保存済みの値をそのまま使う(Diagnosisの
trigger_fact参照と同じ原則)。

### 8-1. なぜ`display_baseline_value`(delta_absolute)ではないか——自己希釈問題

`display_baseline_value`は**entity自身を含む**全体の加重平均
(EVALUATION_ENGINE.md §9)。これをGap基準に使うと、traffic shareが
大きいentityほど自分自身の値がbaselineを自分側へ引き寄せてしまい、
**Priority Gapが自己希釈される**——Impactは大規模entityを重く扱いたい
一方、display baseline方式では大規模entityほどgapが縮小するため、
Priority設計の目的(「大規模entityほど確認優先度を上げたい」)と
正反対の力学が働いてしまう。

具体例(Dominant Bad Channel、§24): traffic share 70%のentityが
CVR 2%、残り30%のpeersがCVR 6%の場合:

```text
display_baseline方式: 0.02 - 0.032 = -0.012 (1.2pp、自己希釈済み)
test_baseline方式:     0.02 - 0.06  = -0.04  (4.0pp、真のpeer差)
```

`test_baseline_value`(leave-one-out、entity自身を除いたpeers/control
のみの加重平均)は、そのentity自身のshareが増えても**値が変わらない**
——自己希釈が構造的に起こり得ない。Impactが正しく大規模entityを重く
評価する一方でGapが正しく維持されるよう、Priority v1.1では
`test_baseline_value`をGap基準として統一した。

### 8-2. Known Limitation: dominant-entity self-dilution in Evaluation practical significance

Priority v1.1はGapを`test_baseline_value`(leave-one-out peer/control
baseline)基準で測定するため、**Priority Gap自体は自己希釈の影響を
受けない**(§8-1)。

しかし、**Priority Eligibility自体は引き続きPhase 4-Aの
`evaluation_level`に依存している**(§4)。Phase 4-Aは現在、
practical significance判定を`delta_absolute`
(= `metric_value - display_baseline_value`)に対して行っている
——この`display_baseline_value`はentity自身を含む値である。

dominant entityの場合、`display_baseline_value`がそのentity自身の
metric_valueへ引き寄せられ、`delta_absolute`が縮小し、
`evaluation_level`がdowngradeされる可能性がある。

例(§24 Dominant Bad Channel 90% share fixtureで実際に確認済み):

```text
entity traffic share: 90%
entity CVR:            2%
peer CVR:               6%

test-baseline gap:    約4.0pp   (Priority Gapが実際に使う値)
display-baseline gap: 約0.4pp   (Evaluationのpractical floor判定に使われる値)
```

現在の実装では、この自己希釈により、本来強いシグナルであるはずの
評価が、Priority Eligibilityへ到達する**前**に(Evaluation自身の
`evaluation_level`の時点で)`high`から`medium`へdowngradeされ得る。

**責務を明確に分けて記載する**:

- **Priority Gap(Phase 4-C)**: 自己希釈は**解消済み**(§8-1)。
- **Evaluation Eligibility upstream(Phase 4-A)**: 自己希釈の可能性が
  **残っている**。

これはPhase 4-A側の既知の制約であり、Phase 4-Cでは変更していない
(Phase 4-Aの仕様変更を伴うため、Phase 4-Cのscope外——§16「Phase 4-C
v1対象外」)。将来のEvaluation改訂で、practical significance判定に
どちらのbaselineを使うべきか再検討することを推奨する。

なお、`evaluation_level`が`medium`へdowngradeされても、Priority
Eligibilityのwhitelist(`{high, medium}`、§4)には引き続き含まれる
ため、この制約が働いてもPriority自体が計算されなくなるわけではない
——「Evaluation strengthとPriority importanceは別概念である」という
Phase 4-Cの設計思想(§2)を、このケースはむしろ具体的に裏付けている
(§24参照)。

## 9. Gap Reference / Gap Score

```text
gap_reference_value = practical_significance_floor × gap_reference_multiple
gap_score = min(gap_raw_value / gap_reference_value, 1)
```

`practical_significance_floor`は`config/evaluation_metrics.php`から
取得し、`config/priority_rules.php`へ**duplicateしない**
(`ResolvePriorityRuleAction`参照、§11)——Single Source of Truthを
Phase 4-Aのconfigに一元化する。

`gap_reference_multiple`(v1: `4`)をfloorへ乗じる理由: floorそのものを
reference値にすると、high評価のgapの多くがfloorの数倍になるため
(実データ: Case C Emailのgap_raw_value≈0.0208、floor=0.005の4倍超)、
ほぼ全てのhigh評価がgap_score=1に張り付いてしまい、Priorityの差別化
ができなくなる。floorを底上げしたreference値を使うことで、high評価
同士でもgapの大小による差別化が可能になる。

## 10. Priority Formula(最終確定)

```text
priority_score = impact_score × gap_score
```

**禁止**: weighted sum、`evaluation_level` weightの追加、
`diagnosis_confidence`の乗算。

### なぜ乗算(weighted sumではない)か

- Impactがゼロに近ければ、Gapがどれだけ大きくてもPriorityは上がらない
  という直感と一致する(加重和だとImpact=0でもGapだけでscoreが残る)。
- 2つのdeterministic 0-1 scoreの積であり説明可能性が高い。
- 将来`× diagnosis_confidence_multiplier`のような追加乗数を導入
  しやすい拡張性を持つ(§20)。

### なぜ`evaluation_level` weightを入れないか(二重カウント回避)

`evaluation_level`自体が既に「z-score(統計的乖離) + practical
significance floor」の合成結果であり、Gap Score(§9)は同じ
「metric_valueとそのbaselineとの乖離」を情報源にしている
(Evaluationはdisplay_baseline、Priorityはtest_baselineと基準こそ
異なるが、いずれも同一entityの同一乖離シグナルの表現である点は同じ)。
ここへ`evaluation_level`weightを追加すると、同一のシグナル(乖離の
大きさ)を2回評価することになる。
Eligibility判定(§4)の時点で`evaluation_level ∈ {high, medium}`は
既に情報として使用済み——Score本体の乗数には含めない。

### score range

`priority_score`は常に`[0, 1]`に収まる——`CalculatePriorityAction`の
入力検証(impact_value ≤ impact_total かつ両方 ≥ 0、gap_scoreは
`min(..., 1)`で構成)により、defensiveなclampを追加せずとも数学的に
保証される(`CalculatePriorityAction`のクラスdocblock参照)。

## 11. Diagnosis非依存(最重要の設計判断)

Phase 4-C v1では`DiagnosisResult`・`self_reported_confidence`を
**Priority計算に一切使わない**。

理由:

- `self_reported_confidence`はuncalibrated、UI非表示、正答率ではない
  (DIAGNOSIS_ENGINE.md §11)。実E2Eで`insufficient_explanatory_evidence`
  (原因不明という結論)でも0.97〜0.99という高い値が観測されている——
  「原因不明であることへの自信」が高いだけで「診断が当たっている確率」
  ではない。
- Category Catalogが2つしかなく(DIAGNOSIS_ENGINE.md §8)、Specific
  diagnosisの種類が乏しいため、Diagnosis Stateをgateとして使う設計も
  v1では見送る。

`priority_score`は`DiagnosisResult`の有無によらず完全に同一
(`ExecuteAnalysisJobPriorityTest`で検証——Priorityは`EvaluateAnalysisJobAction`
直後、Diagnosisより**前**に実行されるため、この独立性はpipeline順序
としても保証されている、§3)。

### insufficient_explanatory_evidence時のPriority

Priorityは下げない。「原因は分からないが、まず調査すべき」という状態
は自然であり、`insufficient_explanatory_evidence`でもPriority Highに
なり得る。

### Diagnosis unavailable時のPriority

PriorityはEvaluationのみに依存するため、Diagnosis unavailable
(technical failureでDiagnosisResultなし)でも通常通り算出される。

## 12. Band Thresholds

```text
high   >= 0.35
medium >= 0.10
low    <  0.10
```

境界値は`>=`で統一。`config/priority_rules.php`でconfig-driven
(§17)。

### なぜ0.35 / 0.10か(初期Golden Tuning)

実データ(Case A/B/C)+ 新規synthetic case(Dominant Bad Channel /
Dominant Bad Channel 90% / Tiny Severe Channel)から逆算した、v1
第一回のTuning結果(Gap基準を`test_baseline_value`へ修正した後の値
——§8-1参照):

| Case | priority_score | Band |
|---|---|---|
| Dominant Bad Channel(90%share) | 0.90 | High |
| Dominant Bad Channel(70%share) | 0.70 | High |
| Case A Social | 0.175 | Medium |
| Case A Display | 0.05 | Low |
| Case C Email | 0.025 | Low |
| Case C Display | ≈ 0.0131 | Low |
| Case B Display | ≈ 0.0066 | Low |
| Tiny Severe Channel | ≈ 0.0020 | Low |

この人間直感に合わせた初期v1閾値であり、実運用データが増えるに従って
`formula_version`を上げながら再Tuningされることを前提とする
(§18)。Dominant Bad Channelは、Gap基準修正前(display_baseline、
≈0.42)と比べてscoreが大きく上昇した(0.70)——自己希釈が解消された
結果であり、閾値自体は変更していない(§8-1参照)。

## 13. Diagnosis confidenceによるband cap

**v1では実装しない。** `self_reported_confidence`が未校正である現状
(§11)では、それを使ったband capはむしろ危険——低いconfidence値が
「診断の正しさ」ではなく「原因不明であることへの自信」を表している
可能性が高いため、cap方向が逆転しかねない。将来`calibrated_confidence_score`
導入後に追加を検討する(§20)。

## 14. Minimal UI

`resources/views/analysis-jobs/show.blade.php`のEvaluationテーブルへ
「確認優先度」列を追加した(別sectionは作らない——Diagnosisの「原因の
仮説」section とは異なり、Priorityは同じEvaluation行に対する付加情報
であるため列として自然)。

### 3つの表示状態

```text
1) Priority対象外(favorable/low/insufficient_data): "-"
2) 対象かつPriorityResultあり: Bandバッジ + 流量影響%・比較対照との差pp
3) 対象だがPriorityResultなし(soft-fail): 「取得できませんでした」
```

「比較対照との差」は`PriorityResult.gap_raw_value`(leave-one-out
peer/control基準、§8)であり、Evaluationテーブル本体のDifference列
(`delta_absolute`、display baseline基準)とは**別の値**。ラベルも
意図的に「基準との差」ではなく「比較対照との差」とし、Evaluation列の
Differenceと混同されないようにしている(§8-1の自己希釈問題を参照)。
Evaluationテーブル本体のDifference表示自体は変更していない
——Priorityだけが別基準を使う。

状態1と状態3を同じ表示にしない——Diagnosis section の
「診断結果を取得できませんでした」と同じ設計思想(DIAGNOSIS_ENGINE.md
§17)。「対象かどうか」は専用DBカラムを持たず、
`DetermineDiagnosisEligibilityAction`を表示時に再実行して導出する
(Diagnosisと全く同じ手法、§4)。

### UI日本語名称

「優先度」単体は「施策実行Priority」に見える可能性があるため不採用。
「確認優先度」を採用した(§2の定義と一貫)。

### priority_score生値は表示しない

`self_reported_confidence`をUI非表示にした既存方針
(DIAGNOSIS_ENGINE.md §17)と同様、`priority_score`の生値
(0.175等)はユーザーへ表示しない。Band + 構成要素(Impact/Gap)の
説明のみ表示する。

## 15. Final AnalyzeへPriorityを渡さない / DiagnosisへPriorityを渡さない

いずれも渡さない。

- **Final Analyze**: Decision-enabledの場合、既にDescriptive-only境界
  (DIAGNOSIS_ENGINE.md §3、Rule 25「priorityを付与しない」)が存在する。
  Priorityデータをcontextへ渡すと、「High priorityなので……」という
  判断文をAIが生成し始めるリスクがある。
- **Diagnosis Evidence Package**: Diagnosisは「why」、Priorityは
  「which first」——責務分離を維持する。

Priorityは専用UI列(§14)のみで表示される。

## 16. Phase 4-C v1対象外(Future Scope)

```text
Action Catalog / Action AI / budget recommendation / execute actions
confidence calibration / self_reported_confidence usage / calibrated confidence
band cap by confidence / multiple sampling / user feedback
corrected diagnosis / confusion matrix
temporal priority / historical priority trend
business target input / manual priority override
Opportunity priority(favorable anomaly優先度)/ cross-metric priority
multi-template priority / portfolio optimization
UI全面刷新
```

## 17. Priority Config

新規: `config/priority_rules.php`。`config/evaluation_metrics.php`
(何を評価するか、Phase 4-A)/`config/diagnosis_categories.php`
(何を診断できるか、Phase 4-B)とは責務が異なる第3のconfigとして分離
した。

```php
return [
    'ad_performance' => [
        'metrics' => [
            'conversion_rate' => [
                'impact_basis' => 'denominator_share',
                'gap_reference_multiple' => 4,
                'band_thresholds' => [
                    'high' => 0.35,
                    'medium' => 0.10,
                ],
                'formula_version' => 'priority_v1.1',
            ],
        ],
    ],
];
```

`practical_significance_floor`は意図的にここへ含めない(§9)。
`impact_basis`はv1では`"denominator_share"`のみ対応——値自体は
descriptiveであり、実際の計算ロジックは`CalculatePriorityAction`が
`denominator_share`前提でハードコードしている(将来`"spend_share"`等
を追加する場合は`CalculatePriorityAction`側の分岐追加も必要になる)。

## 18. formula_version

必須。現在値`priority_v1.1`。`EvaluationFact.rule_version` /
`DiagnosisResult.prompt_version`と同じ監査可能性の設計思想を踏襲する。
Formula(§10)やnormalization係数(§9)がTuningされるたびにversionを
上げ、DBに保存済みの結果がどのformulaで計算されたか常にtraceできる
ようにする。既存データは上書きしない——再実行によってのみ新
formula_versionの結果へ置き換わる。

### v1 → v1.1(commit前レビューでのversion bump)

初期実装は`priority_v1`(Gap基準が`display_baseline_value`、§8-1の
自己希釈問題を持つ)だった。commit前レビューでこの問題が指摘され、
Gap基準を`test_baseline_value`へ修正した際、**`formula_version`
文字列自体も`priority_v1.1`へ更新した**——同じEvaluationFactでも
`priority_v1`と`priority_v1.1`ではPriority Scoreの出力値が異なるため
(§8-1の例: Dominant Bad Channel 70%shareで`priority_v1`なら
`≈0.42`、`priority_v1.1`なら`0.70`)、どちらのformulaで算出された
行かをDB監査時に区別できることが必須と判断した。`priority_v1`は
Phase 4-C現行実装としては**存在しない**(config/DB/testいずれにも
残っていない)——本ドキュメント内で`priority_v1`という文字列が出て
くる箇所は、すべて「修正前はこうだった」という履歴的な説明に限る。

## 19. PriorityResult DB Schema

```text
priority_result_id        PK
analysis_job_id             FK -> analysis_jobs, cascadeOnDelete
evaluation_fact_id          FK -> evaluation_facts, cascadeOnDelete, UNIQUE

impact_basis                 string(50)
impact_value                  decimal(18,8) nullable
impact_total                   decimal(18,8) nullable
impact_score                    decimal(8,6)

gap_raw_value                    decimal(18,8) nullable
gap_reference_value                decimal(18,8) nullable
gap_score                           decimal(8,6)

priority_score                       decimal(8,6)
priority_band                         string(10)

formula_version                        string(50)

computed_at                             timestamp
timestamps
```

`DiagnosisResult`への直接FKは**持たない**——EvaluationFact経由でのみ
関連づける(§11「score計算はDiagnosis非依存」との整合)。

`1 EvaluationFact = 1 PriorityResult`(`hasOne`、`DiagnosisResult`と
同じ形)。

## 20. Action責務分割

Phase 4-A(EVALUATION_ENGINE.md §22)の3層分離パターンをそのまま踏襲:

```text
ResolvePriorityRuleAction
  純粋: template_key + metric_key + config/priority_rules.php
        + config/evaluation_metrics.php(floorのみ)
        → impact_basis / gap_reference_multiple / band_thresholds
          / formula_version / practical_significance_floor
        (ResolveEvaluationMetricDefinitionsActionと対応)

CalculatePriorityAction
  純粋数学: (impactValue, impactTotal, metricValue, testBaselineValue, priorityRule)
        → {impact_score, gap_score, priority_score, priority_band, ...}
        (TwoProportionZTestActionと対応)

PrioritizeAnalysisJobAction
  top-level orchestration: AnalysisJob を受け取り、EvaluationFactを
  自ら再取得 → Eligibility判定(DetermineDiagnosisEligibilityAction再利用)
  → impact_total算出(metric単位)→ 上記2 Actionを呼び出し
  → delete+recreateで永続化
        (EvaluateAnalysisJobActionと対応)
```

## 21. Persistence / Idempotency

**delete + recreate**(Evaluationと同じ順序——Diagnosisの
「delete-first」とは異なる)。PriorityはAI非決定性を持たないため、
「全行を計算し終えてから一括置換」で問題ない
(`PrioritizeAnalysisJobAction::persist()`)。

### Job単位all-or-nothing(partial result禁止)

全ての行を**先にメモリ上で計算し終えてから**、DBへの
delete+insertを1つのtransaction内で行う。計算ループの途中で
`CalculatePriorityAction`が真の技術的例外(`InvalidArgumentException`
——設定不整合等、通常は起こり得ない)を投げた場合、その例外は
`PrioritizeAnalysisJobAction::execute()`の外へそのまま伝播し、
delete+insertは一切実行されない——「metricの一部entityだけPriorityが
保存される」という中間状態を避ける。

一方、「この metric に Priority ruleが設定されていない」「eligible
なのにdenominatorが使えない」等の**正常な業務上のskip**は、例外を
投げずログのみでcontinueする(`EvaluateAnalysisJobAction`の
"skip with a log line"パターンと同じ)。

### Stale cleanup(2層防御)

```text
A. EvaluationFact delete時: evaluation_fact_id への FK cascadeOnDelete
B. PrioritizeAnalysisJobAction: 既存 priority_results を明示的に delete
```

## 22. Soft-fail

`ExecuteAnalysisJobAction`は`PrioritizeAnalysisJobAction::execute()`
呼び出しを`try/catch(Throwable)`で囲む——Evaluationの soft-fail
(EVALUATION_ENGINE.md §21)と全く同じ形。技術的失敗時は
`PrioritizeAnalysisJobAction::clearForAnalysisJob()`を呼び、以前の
成功したattemptが残した stale `PriorityResult`を明示的に削除してから
pipelineを継続する(Final Analyze / Diagnosisは影響を受けない)。

## 23. AI Call Count

**追加AI call = 0(必須)。** Mapping / Planning / Analyze / Diagnosis
のcall countはPhase 4-C導入前後で変わらない。Priorityは完全に
Laravel-onlyである(`ExecuteAnalysisJobPriorityTest`の各テストで
`AiAnalysisClient`のmock期待回数を固定して検証)。

## 24. Golden Priority Eval

`tests/fixtures/priority_eval/*.json`(Diagnosis Golden Eval
——DIAGNOSIS_ENGINE.md §15——と同じrepo-fixture方式。ただしPriorityは
完全にAI非依存なので、Diagnosisのように「deterministicな半分だけを
検証する」のではなく、eligibility・Impact/Gap/Score計算・Band判定の
**全て**をこのGolden Evalだけで検証できる)。

Case A/B/Cの実データ(§12の表)に加え、3件のPriority専用synthetic
caseを追加した(いずれもGap基準を`test_baseline_value`へ修正した
v1.1のformulaで再計算済み — §8-1参照):

- **Dominant Bad Channel(70% share)**(large impact + large gap):
  Social 70,000 clicks・CVR 2.0% vs peers 30,000 clicks・CVR 6.0%。
  独立計算: `test_baseline_value = 1800/30000 = 0.06`(peersのみ、
  leave-one-out)、`gap_raw_value = |0.02-0.06| = 0.04`(4.0pp)、
  `z ≈ -32.9`(閾値を大きく超える) → `evaluation_level = high`。
  期待: Priority High(`priority_score ≈ 0.70`)。
- **Dominant Bad Channel(90% share)**(commit前レビューで追加した
  自己希釈regressionケース): 同じpeer gap(CVR 2.0% vs 6.0%)のまま
  traffic shareを70%→90%へ増やした版。90,000 clicks・CVR 2.0% vs
  peers 10,000 clicks・CVR 6.0%。`test_baseline_value = 0.06`
  (peersのshareが減っても不変)、`gap_raw_value = 0.04`(70%版と
  完全に同一)。ただし`display_baseline`(2400/100000=0.024)基準の
  `delta_absolute`は`0.02-0.024=-0.004`とfloor(0.005)未満になり、
  **Evaluation自体がhigh→mediumへdowngradeされる**(これはPhase 4-A
  自身の自己希釈で、Priority修正の対象外——期待通りの挙動)。
  Priority Eligibilityはmediumもwhitelist対象のため引き続きeligible。
  期待: Priority High(`priority_score ≈ 0.90`、70%版より**上昇**——
  自己希釈が解消されているため、shareの増加が正しくscoreへ反映される)。
- **Tiny Severe Channel**(tiny impact, severe gap, だが
  statistically valid): 100 clicks / 0 conversions vs peers
  50,000 clicks・CVR 6.0%。独立計算: `test_baseline_value = 0.06`、
  normal approximation gate通過(`100×p≈5.99≥5`等)、`z ≈ -2.53`
  (閾値超) → `evaluation_level = high`(`insufficient_data`ではない)。
  期待: Priority Low(`priority_score ≈ 0.002`)——統計的には妥当な
  異常でも、トラフィックシェアが無視できるほど小さければPriorityは
  上がらない。

`GoldenPriorityEvalTest::test_raising_traffic_share_from_70_to_90_percent_increases_priority_never_decreases_it`
が、70%→90%でgap_raw_valueが不変・priority_scoreが増加することを
専用に固定している(自己希釈regressionの直接的な回帰テスト)。

### Relative ranking test

絶対Band固定だけでなく、fixtureの`expected.greater_than`で
相対順序も検証する(例: Case A Social > Case A Display、Dominant Bad
90% > Dominant Bad 70% > Case A Social > Case C Display > Case B
Display > Tiny Severe)——絶対閾値がv1の初期Tuning段階でも、順序性は
普遍的に成立すべき不変条件として独立に検証できる。

## 25. Product Validation(実装後の再検証)

`tests/Feature/AnalysisJob/ExecuteAnalysisJobPriorityTest.php`
`test_a_case_a_produces_the_expected_priority_results`が、Case A
CSV(全channel clicks=12,000, 合計60,000)をパイプライン全体
(DataProfiling → Aggregation → Mapping → Planning → Calculation →
Evaluation → **Priority** → Analyze → Diagnosis)経由で実行し:

- Display: `impact_score = 0.20`, `gap_raw_value = 0.005`(leave-one-out
  test_baseline 0.05基準), `priority_score = 0.05`, `priority_band = "low"`
- Social: `impact_score = 0.20`, `gap_raw_value = 0.0175`(test_baseline
  0.0525基準), `priority_score = 0.175`, `priority_band = "medium"`,
  `priority_score(Social) > priority_score(Display)`
- Email(favorable): `priorityResult`なし

を確認済み(Gap基準修正後の値。実データはEvaluation Engineの
leave-one-out計算そのものから取得しているため、四捨五入なしの厳密値
——`ExecuteAnalysisJobPriorityTest`が`1e-6`精度で固定している)。
Browser E2Eでの実ブラウザ確認記録はdocs/product/DIAGNOSIS_ENGINE.md
§19(Case A/B/C Product Validation)を参照——これはGap基準修正前
(display_baseline方式)の実行結果である。Gap基準修正後もCase A/B/Cの
band(Low/Medium)自体は変化しない(§12の再確認テーブル参照)ため、
記録済みのBrowser E2E結果は引き続き有効。

## 26. Product Validation — Browser実行記録(formula v1.1、Case A/B/C + Dominant Bad Channel 90%)

Phase 4-C Product Validationでは、Phase 4-A/4-B Product Validation
(docs/product/DIAGNOSIS_ENGINE.md §19)で使用したsynthetic CSVを再利用
し、Gap基準修正後のformula(`priority_v1.1`)で:

- Evaluation Level
- Priority Eligibility
- Impact
- leave-one-out Gap(`test_baseline_value`基準)
- Priority Band
- Diagnosis regression

が実際のBrowser画面で人間の業務直感と一致するかを、実Docker環境・実
OpenAI APIで確認した。

**Priorityは「施策実行優先度」ではなく「確認・調査優先度」である。**
以下の記録において「Priority High」「確認優先度High」という表現は、
すべて「先に確認・調査する価値が高い」という意味であり、「すぐに
budget増減・targeting変更・creative変更等の施策を実行すべき」という
意味では**ない**(§2参照)。

### 26-1. Case A — Balanced Traffic

CSV: `phase4a_case_a_balanced_v2.csv`(全channel clicks=12,000)。

| Entity | CVR | Evaluation | 流量影響 | 比較対照との差 | 確認優先度 |
|---|---|---|---|---|---|
| Display | 4.50% | Medium | 20.0% | 0.50pp | Low |
| Social | 3.50% | High | 20.0% | 1.75pp | Medium |
| Email | 5.50%(Above) | High | — | — | Priorityなし(favorable) |
| Organic | 5.00%(Above) | Low | — | — | Priorityなし(low) |
| Paid Search | 6.00%(Above) | High | — | — | Priorityなし(favorable) |

Diagnosis: Display / Social の2件のみ、両方`insufficient_explanatory_evidence`。

**確認結果: PASS**

重要な確認事項:

- Impactが同じ20%でも、Gapが大きいSocialの確認優先度がDisplayより高い
- `Social > Display`のrelative rankingが成立(§10 §21の不変条件と整合)
- Evaluation本体のDifference列(display baseline基準、-0.40pp/-1.40pp)
  と、確認優先度の説明に出るPriority Gap(test baseline基準、
  0.50pp/1.75pp)がUI上ではっきり分離されている(§8-1・§14参照)
- Priority追加によるDiagnosis regressionなし(件数・対象entityとも
  Priority Eligibleな2件と一致)

### 26-2. Case B — Low Sample

CSV: `phase4a_case_b_low_sample_v2.csv`。実際の各channel値
(Paid Search 10000/600, Organic 8000/440, Email 40/3, Display 120/4,
Social 80/2、total clicks = 18,240)を使用。

| Entity | CVR | Evaluation | 流量影響 | 比較対照との差 | 確認優先度 |
|---|---|---|---|---|---|
| Display | 3.33% | Medium | 0.7%(120/18,240) | 2.43pp | Low |
| Email | 7.50% | Insufficient data | — | — | Priorityなし |
| Social | 2.50% | Insufficient data | — | — | Priorityなし |
| Organic | 5.50% | Low | — | — | Priorityなし |
| Paid Search | 6.00% | Low | — | — | Priorityなし |

Diagnosis: Displayのみ、`insufficient_explanatory_evidence`。

**確認結果: PASS**

重要な確認事項:

- Gap(2.43pp)が相対的に大きくてもImpact(0.7%)が小さいため確認
  優先度はLow——「見かけ上のCVR差が大きい」だけでは確認優先度は
  上がらない
- `insufficient_data`はPriority対象外(Email/Social)
- `impact_total`はeligible entity(Displayのみ)の合計ではなく、
  valid denominatorを持つ**全**entity(Display+Email+Social+Organic+
  Paid Search)の合計——DB実測`impact_total = 18,240.00000000`を確認済み
  (§7の設計通り)

### 26-3. Case C — Dominant Channel

CSV: `phase4a_case_c_dominant_channel_v2.csv`(total clicks = 100,000、
display baseline = 5.04%)。

| Entity | CVR | Evaluation Difference(display baseline基準) | Evaluation | 流量影響 | 比較対照との差(test baseline基準) | 確認優先度 |
|---|---|---|---|---|---|---|
| Display | 4.00% | -1.04pp | High | 2.5% | 1.06pp | Low |
| Email | 3.00% | -2.04pp | High | 2.5% | 2.09pp | Low |
| Organic | 6.00%(Above) | — | High | — | — | Priorityなし(favorable) |
| Social | 8.00%(Above) | — | High | — | — | Priorityなし(favorable) |
| Paid Search | 5.00%(Below) | — | Low | — | — | Priorityなし(low) |

Diagnosis: Display / Emailのみ、両方`insufficient_explanatory_evidence`。

**確認結果: PASS**

重要な確認事項:

- **Evaluation High ≠ 確認優先度High**——Display/EmailはどちらもHigh
  評価だが、traffic shareが2.5%しかないため確認優先度はLow(Phase
  4-Cの核心的価値提案を実データで裏付ける最重要ケースの1つ)
- `Email(2.09pp) > Display(1.06pp)`のrelative rankingが成立
- favorable High(Organic/Social)はPriority対象外
- Evaluation Difference(display baseline基準、-1.04pp/-2.04pp)と
  比較対照との差(test baseline基準、1.06pp/2.09pp)は別の値であり、
  混同していない(§8-1・§14参照)

### 26-4. Dominant Bad Channel(90% traffic share)

自己希釈修正の効果を確認する最重要synthetic case。

CSV: `phase4c_dominant_bad_channel_90pct.csv`(total clicks = 100,000、
display baseline = 3.30%、Social traffic impact = 90.0%)。

Browser実行記録: Created At 2026-08-24 21:13:31 / Started At
2026-08-24 21:13:32 / Completed At 2026-08-24 21:13:43。

入力:

| Entity | clicks | conversions | CVR |
|---|---:|---:|---|
| Social | 90,000 | 2,700 | 3.00% |
| Display | 2,500 | 150 | 6.00% |
| Email | 2,500 | 150 | 6.00% |
| Organic | 2,500 | 150 | 6.00% |
| Paid Search | 2,500 | 150 | 6.00% |

Evaluation / Priority結果:

| Entity | CVR | Display baseline | Evaluation Difference | Direction | Evaluation | 流量影響 | 比較対照との差 | 確認優先度 |
|---|---|---|---|---|---|---|---|---|
| Display | 6.00% | 3.30% | +2.70pp | Above | High | — | — | Priorityなし(favorable) |
| Email | 6.00% | 3.30% | +2.70pp | Above | High | — | — | Priorityなし(favorable) |
| Organic | 6.00% | 3.30% | +2.70pp | Above | High | — | — | Priorityなし(favorable) |
| Paid Search | 6.00% | 3.30% | +2.70pp | Above | High | — | — | Priorityなし(favorable) |
| Social | 3.00% | 3.30% | -0.30pp | Below | Medium | 90.0% | 3.00pp | **High** |

Diagnosis: Socialのみ、`insufficient_explanatory_evidence`。

**確認結果: PASS**

### 26-5. Dominant Bad Channelで確認できた重要点

このケースはPhase 4-C v1.1の最重要Product Validationとして記録する。

確認できたこと: **Evaluation Medium + 確認優先度High が同時に成立し、
これは矛盾ではない。**

```text
Evaluation: 「統計的signalの強さ」
確認優先度: 「このproblemを先に確認すべきbusiness impact」
```

を別々に表すため。

Priority Gap(`test_baseline_value`基準)を使うことで、dominant
entity自身がdisplay baselineを引き寄せるself-dilution問題を、
Priority側では回避できている(§8-1)。

一方、Phase 4-A Evaluation Levelでは`display_baseline`基準の
deltaをpractical significance判定に使用しているため、90%
dominant entityではpeer gap(leave-one-out)が大きくても、
display-baseline差はそのentity自身に引き寄せられて縮小し、
Evaluationがhigh→mediumへdowngradeされる(§8-2 Known Limitation、
docs/product/EVALUATION_ENGINE.md §15クロスリファレンスに文書化
済み)。**これはPhase 4-Aの既知の制約であり、Phase 4-Cでは変更
していない。**

### 26-6. 4ケース総括

| Case | Entity | Evaluation | Impact | Peer Gap | 確認優先度 | Result |
|---|---|---|---:|---:|---|---|
| A | Display | Medium | 20.0% | 0.50pp | Low | PASS |
| A | Social | High | 20.0% | 1.75pp | Medium | PASS |
| B | Display | Medium | 0.7% | 2.43pp | Low | PASS |
| C | Display | High | 2.5% | 1.06pp | Low | PASS |
| C | Email | High | 2.5% | 2.09pp | Low | PASS |
| Dominant Bad 90% | Social | Medium | 90.0% | 3.00pp | High | PASS |

### 26-7. Product Validation結論

**Phase 4-C Priority Layer v1.1 Product Validation: PASS**

確認できたInvariant:

```text
Evaluation High ≠ Priority High
Evaluation MediumでもImpactが大きければPriority Highになり得る
insufficient_dataはPriorityなし
favorable anomalyはPriorityなし
higher Gap with same Impact → higher Priority
larger Impact with significant Gap → higher Priority
Priority AI call = 0
Diagnosis結果の有無でPriorityは変わらない
PriorityとDiagnosisは責務分離されている
```

### 26-8. formula_version

本Product Validationはすべて`formula_version = priority_v1.1`で
実施した。

```text
v1.1: gap_raw_value = abs(metric_value - test_baseline_value)
```

(§8「Gap — 定義」、§18「formula_version」参照)。

### 26-9. AI Call / Cost

Phase 4-C Priority自体による追加AI Call: **0**。Priority追加による
API cost: **$0**(§23参照)。Diagnosis AI call数はPhase 4-B仕様の
まま(Priority追加による変化なし、§26-1〜26-4の各ケースで確認済み)。
