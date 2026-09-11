<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Applies AnalysisJob business status transitions.
 *
 * Each transition updates AnalysisJob.status together with the related
 * AnalysisJobDetail fields inside a single database transaction, so a
 * partial update is never left behind.
 *
 * recordColumnMapping() / recordEffectiveMapping() / recordManualMappingAttempt()
 * are the exceptions: none of them are status transitions (see each
 * method's own docblock).
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
     * This is the AI's own validated proposal — an audit record that is
     * never mutated afterwards, even once a user manually overrides it
     * (see recordEffectiveMapping()/resumeAfterMappingConfirmation() and
     * docs/product/MAPPING_CONTROL.md).
     *
     * Write-once guard: if column_mapping is already set, this is a no-op
     * (logged, not thrown). ExecuteAnalysisJobAction itself never calls
     * this a second time for the same AnalysisJob — a Queue retry reuses
     * the already-stored column_mapping instead of calling Mapping AI
     * again (see docs/product/MAPPING_CONTROL.md "Retry Recovery") — so
     * reaching this guard indicates a caller bug, not a normal outcome.
     * A no-op defends the "first AI mapping, kept forever" audit
     * guarantee rather than letting a bug silently corrupt it; throwing
     * here instead would turn that same bug into an AnalysisJob failure,
     * which is a worse outcome for an invariant violation that is itself
     * harmless to ignore.
     *
     * @param AnalysisJob $analysisJob
     * @param array<string, array{column: string|null, confidence: string, status: string}> $columnMapping
     * @return void
     */
    public function recordColumnMapping(AnalysisJob $analysisJob, array $columnMapping): void
    {
        $detail = $this->detailFor($analysisJob);

        if ($detail->column_mapping !== null) {
            Log::warning('recordColumnMapping() called with column_mapping already set — ignoring to preserve the write-once audit record.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
            ]);

            return;
        }

        $detail->update([
            'column_mapping' => $columnMapping,
        ]);
    }

    /**
     * Record the Effective Column Mapping for the current attempt (Phase
     * 3-C, see docs/product/MAPPING_CONTROL.md). Not a status transition:
     * call this once ResolveEffectiveColumnMappingAction has determined
     * there is nothing blocking the analysis from continuing —
     * immediately, on the auto-confident path (source "ai" for every
     * field), before Planning is ever called, so a later retry of the
     * same attempt never needs to (and never does) call Mapping AI again.
     *
     * @param AnalysisJob $analysisJob
     * @param array<string, array{column: string|null, status: string, source: 'ai'|'manual'}> $effectiveColumnMapping
     * @return void
     */
    public function recordEffectiveMapping(AnalysisJob $analysisJob, array $effectiveColumnMapping): void
    {
        $this->detailFor($analysisJob)->update([
            'effective_column_mapping' => $effectiveColumnMapping,
        ]);
    }

    /**
     * Record a user's attempted manual Mapping override without resolving
     * anything (Phase 3-C). Not a status transition, and deliberately
     * does not touch effective_column_mapping: used when the submitted
     * override still leaves a required field/group unresolved (see
     * docs/product/MAPPING_CONTROL.md "required不足がManual後も残る場合")
     * so the user's partial edits are preserved for re-display on the
     * Mapping Preview screen instead of being silently discarded, while
     * the AnalysisJob stays in AwaitingMappingConfirmation and no Queue
     * job is dispatched.
     *
     * @param AnalysisJob $analysisJob
     * @param array<string, array{column: string|null}> $manualColumnMapping
     * @return void
     */
    public function recordManualMappingAttempt(AnalysisJob $analysisJob, array $manualColumnMapping): void
    {
        $this->detailFor($analysisJob)->update([
            'manual_column_mapping' => $manualColumnMapping,
        ]);
    }

    /**
     * Transition the AnalysisJob from Processing to
     * AwaitingMappingConfirmation: a Template AnalysisJob whose AI-proposed
     * Column Mapping (already persisted via recordColumnMapping()) does
     * not satisfy the Template's required_fields/required_field_groups.
     * Not a failure — see docs/product/MAPPING_CONTROL.md. The Queue job
     * that calls this simply returns afterwards; nothing here dispatches
     * anything.
     *
     * @param AnalysisJob $analysisJob
     * @return void
     */
    public function markAwaitingMappingConfirmation(AnalysisJob $analysisJob): void
    {
        if ($analysisJob->status !== AnalysisJobStatus::Processing) {
            throw new RuntimeException(
                "AnalysisJob #{$analysisJob->analysis_job_id} is not processing.",
            );
        }

        $analysisJob->update([
            'status' => AnalysisJobStatus::AwaitingMappingConfirmation,
        ]);
    }

    /**
     * Transition the AnalysisJob from AwaitingMappingConfirmation back to
     * Pending, atomically persisting the confirmed manual override and
     * the resulting Effective Mapping. See docs/product/MAPPING_CONTROL.md.
     *
     * Deliberately transitions to Pending, not directly to Processing:
     * Processing is meant to mean "a Queue worker is actively running
     * this attempt right now", which is not yet true at the moment a
     * mapping is confirmed — the resumed attempt has not been picked up
     * by a worker yet. The caller is expected to dispatch
     * ExecuteAnalysisJob afterCommit() immediately after this call
     * returns; markProcessing() then transitions Pending -> Processing
     * exactly as it does for a brand-new AnalysisJob, so this reuses the
     * existing Pending -> Processing contract rather than inventing a
     * second one. This also means a DB commit that succeeds but is
     * followed by a dispatch failure leaves the AnalysisJob safely
     * Pending (inspectable, resumable) rather than stuck claiming to be
     * Processing while nothing is actually processing it.
     *
     * @param AnalysisJob $analysisJob
     * @param array<string, array{column: string|null}> $manualColumnMapping
     * @param array<string, array{column: string|null, status: string, source: 'ai'|'manual'}> $effectiveColumnMapping
     * @return void
     */
    public function resumeAfterMappingConfirmation(
        AnalysisJob $analysisJob,
        array $manualColumnMapping,
        array $effectiveColumnMapping,
    ): void {
        if ($analysisJob->status !== AnalysisJobStatus::AwaitingMappingConfirmation) {
            throw new RuntimeException(
                "AnalysisJob #{$analysisJob->analysis_job_id} is not awaiting mapping confirmation.",
            );
        }

        DB::transaction(function () use ($analysisJob, $manualColumnMapping, $effectiveColumnMapping): void {
            $analysisJob->update([
                'status' => AnalysisJobStatus::Pending,
            ]);

            $this->detailFor($analysisJob)->update([
                'manual_column_mapping' => $manualColumnMapping,
                'effective_column_mapping' => $effectiveColumnMapping,
            ]);
        });
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
