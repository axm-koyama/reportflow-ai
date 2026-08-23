<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies AnalysisJob business status transitions.
 *
 * Each transition updates AnalysisJob.status together with the related
 * AnalysisJobDetail fields inside a single database transaction, so a
 * partial update is never left behind.
 *
 * recordColumnMapping() is the one exception: it is not a status
 * transition (see its own docblock).
 */
class UpdateAnalysisJobAction
{
    /**
     * Transition the AnalysisJob to Processing and record the start time.
     *
     * Retry-safe: if the AnalysisJob is already Processing (a Laravel Queue
     * retry of a failed attempt), this is a no-op so started_at keeps
     * reflecting when the overall analysis first began, not when the
     * current attempt started.
     *
     * @param AnalysisJob $analysisJob
     * @return void
     */
    public function markProcessing(AnalysisJob $analysisJob): void
    {
        if ($analysisJob->status === AnalysisJobStatus::Processing) {
            return;
        }

        if ($analysisJob->status !== AnalysisJobStatus::Pending) {
            throw new RuntimeException(
                "AnalysisJob #{$analysisJob->analysis_job_id} is not pending.",
            );
        }

        DB::transaction(function () use ($analysisJob): void {
            $analysisJob->update([
                'status' => AnalysisJobStatus::Processing,
            ]);

            $this->detailFor($analysisJob)->update([
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Transition the AnalysisJob to Completed and store the AI result.
     *
     * started_at is expected to already be set by markProcessing() and is
     * left untouched here.
     *
     * @param AnalysisJob $analysisJob
     * @param string $rawResponse
     * @param array<string, mixed> $result
     * @return void
     */
    public function markCompleted(AnalysisJob $analysisJob, string $rawResponse, array $result): void
    {
        if ($analysisJob->status !== AnalysisJobStatus::Processing) {
            throw new RuntimeException(
                "AnalysisJob #{$analysisJob->analysis_job_id} is not processing.",
            );
        }

        DB::transaction(function () use ($analysisJob, $rawResponse, $result): void {
            $analysisJob->update([
                'status' => AnalysisJobStatus::Completed,
            ]);

            $this->detailFor($analysisJob)->update([
                'raw_response' => $rawResponse,
                'result' => $result,
                'error_message' => null,
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * Transition the AnalysisJob to Failed and record the failure reason.
     *
     * raw_response / result are left untouched: partial data saved before
     * the failure may still have diagnostic value.
     *
     * @param AnalysisJob $analysisJob
     * @param string $errorMessage
     * @return void
     */
    public function markFailed(AnalysisJob $analysisJob, string $errorMessage): void
    {
        if (
            $analysisJob->status !== AnalysisJobStatus::Processing
            && $analysisJob->status !== AnalysisJobStatus::Pending
        ) {
            throw new RuntimeException(
                "AnalysisJob #{$analysisJob->analysis_job_id} is not processing or pending.",
            );
        }

        DB::transaction(function () use ($analysisJob, $errorMessage): void {
            $analysisJob->update([
                'status' => AnalysisJobStatus::Failed,
            ]);

            $this->detailFor($analysisJob)->update([
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * Record the resolved Analysis Template column mapping for the
     * current attempt (see ResolveAnalysisTemplateAction /
     * docs/product/ANALYSIS_TEMPLATE_MODULE.md). Not a status transition:
     * call this between markProcessing() and markCompleted()/markFailed()
     * when an Analysis Template was used. For free-form analysis
     * (template_key null), this is never called, and column_mapping
     * simply stays null.
     *
     * @param AnalysisJob $analysisJob
     * @param array<string, array{column: string|null, confidence: string, status: string}> $columnMapping
     * @return void
     */
    public function recordColumnMapping(AnalysisJob $analysisJob, array $columnMapping): void
    {
        $this->detailFor($analysisJob)->update([
            'column_mapping' => $columnMapping,
        ]);
    }

    /**
     * Resolve the AnalysisJobDetail owned by the given AnalysisJob.
     *
     * AnalysisJob and AnalysisJobDetail are always created together as a
     * strict 1:1 pair, so a missing Detail indicates a broken invariant
     * rather than an expected business case.
     *
     * @param AnalysisJob $analysisJob
     * @return AnalysisJobDetail
     */
    private function detailFor(AnalysisJob $analysisJob): AnalysisJobDetail
    {
        $detail = $analysisJob->analysisJobDetail;

        if ($detail === null) {
            throw new RuntimeException(
                "AnalysisJobDetail not found for AnalysisJob #{$analysisJob->analysis_job_id}.",
            );
        }

        return $detail;
    }
}
