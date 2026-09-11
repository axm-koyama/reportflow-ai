<?php

declare(strict_types=1);

namespace App\Actions\Priority;

use App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction;
use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Top-level orchestrator for Phase 4-C's Deterministic Priority Layer:
 * turns one AnalysisJob's already-persisted EvaluationFact rows into
 * persisted PriorityResult rows. See docs/product/PRIORITY_ENGINE.md.
 *
 * Zero AI I/O — Priority is computed entirely from EvaluationFact (already
 * persisted by EvaluateAnalysisJobAction earlier in the same pipeline
 * attempt) plus config/priority_rules.php / config/evaluation_metrics.php.
 * This action never reads aggregated_metrics, derived_metrics, or any
 * DiagnosisResult — see docs/product/PRIORITY_ENGINE.md "Diagnosis非依存".
 *
 * Priority Eligibility reuses DetermineDiagnosisEligibilityAction as-is
 * (direction == unfavorable_direction AND evaluation_level ∈ {high,
 * medium} — the exact same condition Phase 4-B already defined) rather
 * than duplicating that logic under a Priority-specific name. See
 * docs/product/PRIORITY_ENGINE.md "Priority Eligibility" for why this
 * reuse, despite the class living under the Diagnosis namespace, is
 * preferred over a rename/refactor of that class or a second
 * implementation of the same rule.
 *
 * A Free Analysis AnalysisJob (template_key === null) or a Template
 * without any config/evaluation_metrics.php entry (e.g. sales_analysis
 * today) is a pure no-op, mirroring EvaluateAnalysisJobAction /
 * RunDiagnosisForAnalysisJobAction's own guard — such a Job never has any
 * EvaluationFact rows to begin with.
 *
 * Job-level all-or-nothing (see docs/product/PRIORITY_ENGINE.md "partial
 * result禁止"): every row is computed in memory first; only once every
 * eligible EvaluationFact has been either scored or defensively skipped
 * (never a technical exception) does persistence begin. A genuine
 * technical failure anywhere in the compute loop propagates out of
 * execute() entirely — no PriorityResult row for this AnalysisJob is ever
 * partially written. This mirrors EvaluateAnalysisJobAction's own shape
 * exactly (compute a plain array of rows, then delete+recreate inside one
 * transaction) rather than DiagnosisResult's per-entity soft-fail (Phase
 * 4-C has no AI call whose failure is expected/routine per entity).
 *
 * Idempotency: "delete + recreate", identical in shape to
 * EvaluateAnalysisJobAction (not DiagnosisResult's "delete-first") — see
 * that class's docblock. Priority has no AI non-determinism to guard
 * against, so replacing old rows only once new rows are fully computed is
 * safe and simpler. The unique index on evaluation_fact_id is a defensive
 * backstop, not the primary mechanism — as is this table's
 * evaluation_fact_id foreign key being cascadeOnDelete, which transitively
 * removes any row whose EvaluationFact was itself replaced by Phase 4-A's
 * own delete+recreate.
 */
class PrioritizeAnalysisJobAction
{
    public function __construct(
        private readonly DetermineDiagnosisEligibilityAction $determineDiagnosisEligibilityAction,
        private readonly ResolvePriorityRuleAction $resolvePriorityRuleAction,
        private readonly CalculatePriorityAction $calculatePriorityAction,
    ) {}

    /**
     * @return int number of PriorityResult rows persisted
     */
    public function execute(AnalysisJob $analysisJob): int
    {
        if ($analysisJob->template_key === null) {
            return 0;
        }

        $facts = EvaluationFact::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->get();

        if ($facts->isEmpty()) {
            // Nothing to score, but still fall through to delete+recreate
            // below — a previous attempt's stale PriorityResults (from a
            // rule_version this attempt no longer produces, or a template
            // change) must not survive an attempt with zero facts either.
            $this->persist($analysisJob, []);

            return 0;
        }

        $impactTotals = $this->impactTotalsByMetric($facts);

        $rows = [];

        foreach ($facts as $fact) {
            if (! $this->determineDiagnosisEligibilityAction->execute($fact, $analysisJob->template_key)) {
                continue;
            }

            $row = $this->priorityRowFor($analysisJob, $fact, $impactTotals);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $this->persist($analysisJob, $rows);

        return count($rows);
    }

    /**
     * Delete every persisted PriorityResult row for this AnalysisJob,
     * without computing or inserting anything new.
     *
     * This exists for exactly one caller: ExecuteAnalysisJobAction's
     * soft-fail catch block, after a technical exception from execute()
     * above — mirroring EvaluateAnalysisJobAction::clearForAnalysisJob()
     * exactly (see that method's docblock and
     * docs/product/PRIORITY_ENGINE.md "Soft-fail"). Priority persistence
     * stays owned by this class — the caller only decides *when* to clear,
     * never touches the PriorityResult model directly.
     */
    public function clearForAnalysisJob(AnalysisJob $analysisJob): void
    {
        PriorityResult::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->delete();
    }

    /**
     * Impact Total's population, per metric_key: the sum of
     * denominator_value across *every* EvaluationFact in this AnalysisJob
     * sharing that metric_key with a valid (non-null, > 0) denominator —
     * regardless of evaluation_level (favorable / low / insufficient_data
     * entities are included; only Priority *eligibility*, decided
     * separately above, excludes them from getting their own
     * PriorityResult row). See docs/product/PRIORITY_ENGINE.md "Impact
     * Total母集団定義": this must never be narrowed to eligible entities
     * only, or Impact Score would no longer represent a genuine
     * traffic/volume share of the whole AnalysisJob.
     *
     * @param  Collection<int, EvaluationFact>  $facts
     * @return array<string, float> metric_key => total
     */
    private function impactTotalsByMetric(Collection $facts): array
    {
        $totals = [];

        foreach ($facts as $fact) {
            if ($fact->denominator_value === null || $fact->denominator_value <= 0) {
                continue;
            }

            $totals[$fact->metric_key] = ($totals[$fact->metric_key] ?? 0.0) + (float) $fact->denominator_value;
        }

        return $totals;
    }

    /**
     * @param  array<string, float>  $impactTotals
     * @return array<string, mixed>|null null on any normal, expected skip
     *                                   (no Priority rule configured for
     *                                   this metric, or this entity's own
     *                                   inputs are unusable) — logged, but
     *                                   never thrown, since these are
     *                                   routine business outcomes, not
     *                                   technical failures (mirrors
     *                                   EvaluateAnalysisJobAction's own
     *                                   "skip with a log line" pattern for
     *                                   unmapped/unusable metrics).
     */
    private function priorityRowFor(AnalysisJob $analysisJob, EvaluationFact $fact, array $impactTotals): ?array
    {
        $rule = $this->resolvePriorityRuleAction->execute((string) $analysisJob->template_key, $fact->metric_key);

        if ($rule === null) {
            Log::info('PrioritizeAnalysisJobAction: skipping — no Priority rule configured for this metric.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'metric_key' => $fact->metric_key,
            ]);

            return null;
        }

        $impactTotal = $impactTotals[$fact->metric_key] ?? 0.0;

        if (
            $fact->denominator_value === null
            || $fact->denominator_value <= 0
            || $impactTotal <= 0.0
            || $fact->metric_value === null
            || $fact->test_baseline_value === null
        ) {
            // Should not happen for an eligible (high/medium) fact — count
            // validity and a non-null z-test result are prerequisites for
            // ever reaching high/medium in the first place (see
            // EvaluateRateMetricAction), and test_baseline_value is always
            // populated alongside metric_value/z_score by that same code
            // path. Defensive rather than assumed: never let an unexpected
            // aggregated_metrics/EvaluationFact shape silently corrupt a
            // Priority calculation.
            Log::warning('PrioritizeAnalysisJobAction: skipping — eligible EvaluationFact has unusable impact/gap inputs.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'metric_key' => $fact->metric_key,
            ]);

            return null;
        }

        $calculated = $this->calculatePriorityAction->execute(
            (float) $fact->denominator_value,
            $impactTotal,
            (float) $fact->metric_value,
            (float) $fact->test_baseline_value,
            $rule,
        );

        return [
            ...$calculated,
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(AnalysisJob $analysisJob, array $rows): void
    {
        $computedAt = now();

        DB::transaction(function () use ($analysisJob, $rows, $computedAt): void {
            PriorityResult::query()
                ->where('analysis_job_id', $analysisJob->analysis_job_id)
                ->delete();

            foreach ($rows as $row) {
                PriorityResult::query()->create([
                    ...$row,
                    'computed_at' => $computedAt,
                ]);
            }
        });
    }
}
