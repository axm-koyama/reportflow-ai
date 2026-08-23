<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\DataProfiling\MetricAggregationAction;
use App\AI\AiAnalysisClient;
use App\Models\AnalysisJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Executes a single attempt of one AnalysisJob run.
 *
 * Coordinates the full ReportFlow AI V3 pipeline for one execution attempt:
 *
 *   markProcessing()
 *   -> DataProfilingAction          (DataFile CSV -> Data Profile)
 *   -> MetricAggregationAction      (DataFile CSV + Data Profile -> Aggregated Metrics)
 *   -> [template_key set only] ResolveAnalysisTemplateAction
 *        -> MapAnalysisTemplateColumnsAction -> AiAnalysisClient::mapColumns() [AI call]
 *        -> ValidateColumnMappingAction (deterministic; throws on unresolved required fields)
 *      (Analysis Template + Column Mapping -> analysisTemplate / columnMapping)
 *   -> PlanDerivedMetricsAction     (prompt + Aggregated Metrics [+ analysisTemplate/columnMapping] -> proposed CalculationDefinitions) [AI call]
 *   -> CalculateDerivedMetricsAction (CalculationDefinitions + Aggregated Metrics -> Derived Metrics)
 *   -> BuildAnalysisContextAction   (prompt + Data Profile + Aggregated Metrics + Derived Metrics [+ analysisTemplate/columnMapping] -> AI Context)
 *   -> AiAnalysisClient::analyze()  (AI Context -> raw structured output) [AI call]
 *   -> NormalizeAnalysisResultAction (raw output -> canonical result)
 *   -> markCompleted()
 *
 * A free-form AnalysisJob (template_key === null) makes exactly the same
 * two AI calls as Phase 2 (PlanDerivedMetricsAction, then
 * AiAnalysisClient::analyze()) — the entire ResolveAnalysisTemplateAction
 * block is skipped outright, not merely passed empty values, so free-form
 * analysis is byte-for-byte the Phase 2 code path. A Template-based
 * AnalysisJob makes one additional AI call (Column Mapping) before
 * Planning. CalculateDerivedMetricsAction and ValidateColumnMappingAction
 * never call the AI — see docs/product/DERIVED_METRICS.md and
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md for the full rationale behind
 * each of these being a separate request rather than combined or
 * tool-called.
 *
 * The DataFile itself (and its CSV content) is only ever touched by
 * DataProfilingAction and MetricAggregationAction. AiAnalysisClient
 * receives the AI Context only (system_instruction / user_prompt /
 * data_profile / aggregated_metrics / derived_metrics / analysis_template /
 * column_mapping / output_schema) — never the DataFile, its stored_path,
 * or raw CSV content.
 *
 * This action owns exactly one attempt. It never marks the AnalysisJob
 * Failed: any exception (from any AI call, from Data Profiling / Metric
 * Aggregation / Derived Metric calculation, or from
 * ResolveAnalysisTemplateAction when a required Template field could not
 * be resolved) is left to propagate to the caller so Laravel Queue's
 * retry mechanism can take over, and only ExecuteAnalysisJob's failed()
 * callback records a final failure once retries are exhausted. A retried
 * attempt redoes every AI call from scratch — no partial-attempt state
 * (e.g. a cached Metric Plan or Column Mapping) is kept between attempts.
 *
 * AI provider I/O is external network I/O and is intentionally kept outside
 * of any database transaction; only the individual status-update steps are
 * transactional (see UpdateAnalysisJobAction).
 */
class ExecuteAnalysisJobAction
{
    public function __construct(
        private readonly UpdateAnalysisJobAction $updateAnalysisJobAction,
        private readonly DataProfilingAction $dataProfilingAction,
        private readonly MetricAggregationAction $metricAggregationAction,
        private readonly ResolveAnalysisTemplateAction $resolveAnalysisTemplateAction,
        private readonly PlanDerivedMetricsAction $planDerivedMetricsAction,
        private readonly CalculateDerivedMetricsAction $calculateDerivedMetricsAction,
        private readonly BuildAnalysisContextAction $buildAnalysisContextAction,
        private readonly AiAnalysisClient $aiAnalysisClient,
        private readonly NormalizeAnalysisResultAction $normalizeAnalysisResultAction,
    ) {}

    /**
     * Run one AnalysisJob execution attempt.
     *
     * Flow:
     *   pending|processing -> processing -> completed
     *
     * markProcessing() is retry-safe, so re-entering this method for a
     * retried attempt (AnalysisJob already Processing) is safe.
     *
     * @param int $analysisJobId
     * @return void
     * @throws ModelNotFoundException if no AnalysisJob exists for the given ID.
     * @throws \Throwable propagated as-is from markProcessing(), Data
     *                     Profiling, Metric Aggregation, Analysis Template
     *                     resolution (including a required-field failure),
     *                     Metric Planning, Derived Metric calculation, AI
     *                     Context building, the final analysis AI call,
     *                     normalization, or markCompleted(), for the
     *                     Laravel Queue worker to retry or ultimately fail.
     */
    public function execute(int $analysisJobId): void
    {
        $analysisJob = AnalysisJob::query()
            ->with(['dataFile', 'analysisJobDetail'])
            ->findOrFail($analysisJobId);

        $this->updateAnalysisJobAction->markProcessing($analysisJob);

        $prompt = $analysisJob->analysisJobDetail->prompt;

        $dataProfile = $this->dataProfilingAction->execute($analysisJob->dataFile);

        $aggregatedMetrics = $this->metricAggregationAction->execute($analysisJob->dataFile, $dataProfile);

        $analysisTemplate = null;
        $columnMapping = [];

        if ($analysisJob->template_key !== null) {
            $resolved = $this->resolveAnalysisTemplateAction->execute(
                $analysisJob->template_key,
                $prompt,
                $dataProfile,
            );

            $analysisTemplate = $resolved['analysis_template'];
            $columnMapping = $resolved['column_mapping'];

            $this->updateAnalysisJobAction->recordColumnMapping(
                $analysisJob,
                $resolved['column_mapping_for_storage'],
            );
        }

        $proposedDefinitions = $this->planDerivedMetricsAction->execute(
            $prompt,
            $aggregatedMetrics,
            $analysisTemplate,
            $columnMapping,
        );

        $derivedMetrics = $this->calculateDerivedMetricsAction->execute($proposedDefinitions, $aggregatedMetrics);

        $context = $this->buildAnalysisContextAction->execute(
            $prompt,
            $dataProfile,
            $aggregatedMetrics,
            $derivedMetrics,
            $analysisTemplate,
            $columnMapping,
        );

        $rawResponse = $this->aiAnalysisClient->analyze($context);

        $result = $this->normalizeAnalysisResultAction->execute($rawResponse);

        $this->updateAnalysisJobAction->markCompleted($analysisJob, $rawResponse, $result);
    }
}
