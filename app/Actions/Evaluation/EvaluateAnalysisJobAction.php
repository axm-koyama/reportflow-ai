<?php

declare(strict_types=1);

namespace App\Actions\Evaluation;

use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Top-level orchestrator for Phase 4-A's Deterministic Evaluation Engine:
 * turns one AnalysisJob's already-computed aggregated_metrics into
 * persisted EvaluationFact rows. See docs/product/EVALUATION_ENGINE.md.
 *
 * Zero AI I/O — this only reads effective_column_mapping (an existing
 * Fact, never re-resolved) and aggregated_metrics (passed in by the
 * caller, already computed once by MetricAggregationAction earlier in
 * the same pipeline attempt — this action never re-streams the CSV).
 * Zero Queue knowledge — this is a plain synchronous Action; see
 * ExecuteAnalysisJobAction's integration and
 * docs/product/EVALUATION_ENGINE.md "Queue".
 *
 * A Free Analysis AnalysisJob (template_key === null) or one whose
 * Effective Mapping is not yet confirmed is a pure no-op: no
 * ResolveEvaluationMetricDefinitionsAction call, no EvaluationFact I/O
 * at all (there is never a prior EvaluationFact to clean up for such a
 * Job either, since template_key never changes after creation).
 *
 * Idempotency: every existing EvaluationFact row for this
 * analysis_job_id is deleted, then this attempt's rows are inserted, all
 * inside one transaction — "delete + recreate", matching
 * docs/product/EVALUATION_ENGINE.md "Idempotency". A Queue retry (or any
 * other re-execution of the same AnalysisJob) always ends up with
 * exactly the current attempt's facts, never an accumulation of stale
 * ones. The unique index on (analysis_job_id, entity_type, entity_key,
 * metric_key, rule_version) is a defensive backstop, not the primary
 * mechanism.
 *
 * "delete + recreate" only replaces old facts with new ones when this
 * attempt actually *succeeds* in computing them. If execute() throws
 * before reaching that point (a technical failure — see
 * ExecuteAnalysisJobAction's soft-fail try/catch), any EvaluationFact
 * rows left over from a *previous, successful* attempt are untouched by
 * that exception and would otherwise keep describing this AnalysisJob as
 * if the current attempt had evaluated it too. clearForAnalysisJob()
 * exists specifically for the caller to remove that stale data once it
 * has decided to soft-fail — see that method's docblock and
 * docs/product/EVALUATION_ENGINE.md "Soft-fail".
 */
class EvaluateAnalysisJobAction
{
    public function __construct(
        private readonly ResolveEvaluationMetricDefinitionsAction $resolveEvaluationMetricDefinitionsAction,
        private readonly EvaluateRateMetricAction $evaluateRateMetricAction,
    ) {}

    /**
     * @param AnalysisJob $analysisJob
     * @param array<string, mixed> $aggregatedMetrics the MetricAggregationAction output already computed for this attempt
     * @return int number of EvaluationFact rows persisted
     */
    public function execute(AnalysisJob $analysisJob, array $aggregatedMetrics): int
    {
        if ($analysisJob->template_key === null) {
            return 0;
        }

        $effectiveColumnMapping = $analysisJob->analysisJobDetail?->effective_column_mapping;

        if ($effectiveColumnMapping === null) {
            return 0;
        }

        $definitions = $this->resolveEvaluationMetricDefinitionsAction->execute(
            $analysisJob->template_key,
            $effectiveColumnMapping,
        );

        foreach ($definitions['skipped'] as $skipped) {
            Log::info('EvaluateAnalysisJobAction: skipping evaluation metric — entity cannot be enumerated.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'template_key' => $analysisJob->template_key,
                'metric_key' => $skipped['metric_key'],
                'reason' => $skipped['reason'],
            ]);
        }

        $rows = [];

        foreach ($definitions['resolved'] as $definition) {
            if ($definition['metric_type'] !== 'rate') {
                // No non-rate metric_type exists in
                // config/evaluation_metrics.php as of Phase 4-A v1 (see
                // docs/product/EVALUATION_ENGINE.md "Phase 4-A対象外" —
                // absolute metric evaluator is future scope). Skip rather
                // than guess at a calculation this Action was never
                // built to perform.
                Log::warning('EvaluateAnalysisJobAction: skipping evaluation metric — unsupported metric_type.', [
                    'analysis_job_id' => $analysisJob->analysis_job_id,
                    'template_key' => $analysisJob->template_key,
                    'metric_key' => $definition['metric_key'],
                    'metric_type' => $definition['metric_type'],
                ]);

                continue;
            }

            $facts = $this->evaluateRateMetricAction->execute($definition, $aggregatedMetrics);

            if ($facts === []) {
                // The entity dimension itself was not present in
                // aggregated_metrics (e.g. dropped by
                // MetricAggregationAction's max_dimensions /
                // max_cardinality_per_dimension despite being mapped) —
                // see docs/product/EVALUATION_ENGINE.md
                // "aggregated_metrics前提確認". Never assume completeness
                // silently.
                Log::warning('EvaluateAnalysisJobAction: evaluation metric produced no facts — entity dimension not present in aggregated_metrics.', [
                    'analysis_job_id' => $analysisJob->analysis_job_id,
                    'template_key' => $analysisJob->template_key,
                    'metric_key' => $definition['metric_key'],
                    'entity_type' => $definition['entity_type'],
                ]);

                continue;
            }

            array_push($rows, ...$facts);
        }

        $computedAt = now();

        DB::transaction(function () use ($analysisJob, $rows, $computedAt): void {
            EvaluationFact::query()
                ->where('analysis_job_id', $analysisJob->analysis_job_id)
                ->delete();

            foreach ($rows as $row) {
                EvaluationFact::query()->create([
                    ...$row,
                    'analysis_job_id' => $analysisJob->analysis_job_id,
                    'computed_at' => $computedAt,
                ]);
            }
        });

        return count($rows);
    }

    /**
     * Delete every persisted EvaluationFact row for this AnalysisJob,
     * without computing or inserting anything new.
     *
     * This exists for exactly one caller: ExecuteAnalysisJobAction's
     * soft-fail catch block, after a technical exception from execute()
     * above. Evaluation persistence stays owned by this class — the
     * caller only decides *when* to clear, never touches the
     * EvaluationFact model directly (see
     * docs/product/EVALUATION_ENGINE.md "Soft-fail").
     *
     * Idempotent and safe to call for an AnalysisJob with zero existing
     * EvaluationFact rows (e.g. its first-ever attempt failed before
     * evaluating anything) — this is just a delete, same as the first
     * half of execute()'s own "delete + recreate".
     *
     * @param AnalysisJob $analysisJob
     * @return void
     */
    public function clearForAnalysisJob(AnalysisJob $analysisJob): void
    {
        EvaluationFact::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->delete();
    }
}
