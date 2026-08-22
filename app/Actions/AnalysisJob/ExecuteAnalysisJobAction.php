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
 * Coordinates the full ReportFlow AI V2 pipeline for one execution attempt:
 *
 *   markProcessing()
 *   -> DataProfilingAction          (DataFile CSV -> Data Profile)
 *   -> MetricAggregationAction      (DataFile CSV + Data Profile -> Aggregated Metrics)
 *   -> PlanDerivedMetricsAction     (prompt + Aggregated Metrics -> proposed CalculationDefinitions) [AI call #1]
 *   -> CalculateDerivedMetricsAction (CalculationDefinitions + Aggregated Metrics -> Derived Metrics)
 *   -> BuildAnalysisContextAction   (prompt + Data Profile + Aggregated Metrics + Derived Metrics -> AI Context)
 *   -> AiAnalysisClient::analyze()  (AI Context -> raw structured output) [AI call #2]
 *   -> NormalizeAnalysisResultAction (raw output -> canonical result)
 *   -> markCompleted()
 *
 * This makes exactly two AI calls per attempt: PlanDerivedMetricsAction
 * (Metric Planning — proposes *which* derived metrics to compute, never
 * shown actual numeric values) and AiAnalysisClient::analyze() (the final
 * business analysis, which is shown both Aggregated Metrics and Derived
 * Metrics). CalculateDerivedMetricsAction, which sits between them, does
 * the actual arithmetic and never calls the AI — see
 * docs/product/DERIVED_METRICS.md for the full rationale.
 *
 * The DataFile itself (and its CSV content) is only ever touched by
 * DataProfilingAction and MetricAggregationAction. AiAnalysisClient
 * receives the AI Context only (system_instruction / user_prompt /
 * data_profile / aggregated_metrics / derived_metrics / output_schema) —
 * never the DataFile, its stored_path, or raw CSV content.
 *
 * This action owns exactly one attempt. It never marks the AnalysisJob
 * Failed: any exception (from either AI call, or from Data Profiling /
 * Metric Aggregation / Derived Metric calculation) is left to propagate to
 * the caller so Laravel Queue's retry mechanism can take over, and only
 * ExecuteAnalysisJob's failed() callback records a final failure once
 * retries are exhausted. A retried attempt redoes both AI calls from
 * scratch — no partial-attempt state (e.g. a cached Metric Plan) is kept
 * between attempts.
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
     *                     Profiling, Metric Aggregation, Metric Planning,
     *                     Derived Metric calculation, AI Context building,
     *                     the final analysis AI call, normalization, or
     *                     markCompleted(), for the Laravel Queue worker to
     *                     retry or ultimately fail.
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

        $proposedDefinitions = $this->planDerivedMetricsAction->execute($prompt, $aggregatedMetrics);

        $derivedMetrics = $this->calculateDerivedMetricsAction->execute($proposedDefinitions, $aggregatedMetrics);

        $context = $this->buildAnalysisContextAction->execute(
            $prompt,
            $dataProfile,
            $aggregatedMetrics,
            $derivedMetrics,
        );

        $rawResponse = $this->aiAnalysisClient->analyze($context);

        $result = $this->normalizeAnalysisResultAction->execute($rawResponse);

        $this->updateAnalysisJobAction->markCompleted($analysisJob, $rawResponse, $result);
    }
}
