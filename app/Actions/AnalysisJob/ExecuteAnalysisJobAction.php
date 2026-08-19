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
 * Coordinates the full ReportFlow AI V1 pipeline for one execution attempt:
 *
 *   markProcessing()
 *   -> DataProfilingAction        (DataFile CSV -> Data Profile)
 *   -> MetricAggregationAction    (DataFile CSV + Data Profile -> Aggregated Metrics)
 *   -> BuildAnalysisContextAction (prompt + Data Profile + Aggregated Metrics -> AI Context)
 *   -> AiAnalysisClient           (AI Context -> raw structured output)
 *   -> NormalizeAnalysisResultAction (raw output -> canonical result)
 *   -> markCompleted()
 *
 * The DataFile itself (and its CSV content) is only ever touched by
 * DataProfilingAction and MetricAggregationAction. AiAnalysisClient
 * receives the AI Context only (system_instruction / user_prompt /
 * data_profile / aggregated_metrics / output_schema) — never the
 * DataFile, its stored_path, or raw CSV content.
 *
 * This action owns exactly one attempt. It never marks the AnalysisJob
 * Failed: any exception is left to propagate to the caller so Laravel
 * Queue's retry mechanism can take over, and only ExecuteAnalysisJob's
 * failed() callback records a final failure once retries are exhausted.
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
     *                     Profiling, Metric Aggregation, AI Context
     *                     building, the AI call, normalization, or
     *                     markCompleted(), for the Laravel Queue worker to
     *                     retry or ultimately fail.
     */
    public function execute(int $analysisJobId): void
    {
        $analysisJob = AnalysisJob::query()
            ->with(['dataFile', 'analysisJobDetail'])
            ->findOrFail($analysisJobId);

        $this->updateAnalysisJobAction->markProcessing($analysisJob);

        $dataProfile = $this->dataProfilingAction->execute($analysisJob->dataFile);

        $aggregatedMetrics = $this->metricAggregationAction->execute($analysisJob->dataFile, $dataProfile);

        $context = $this->buildAnalysisContextAction->execute(
            $analysisJob->analysisJobDetail->prompt,
            $dataProfile,
            $aggregatedMetrics,
        );

        $rawResponse = $this->aiAnalysisClient->analyze($context);

        $result = $this->normalizeAnalysisResultAction->execute($rawResponse);

        $this->updateAnalysisJobAction->markCompleted($analysisJob, $rawResponse, $result);
    }
}
