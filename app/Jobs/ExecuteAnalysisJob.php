<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Actions\AnalysisJob\UpdateAnalysisJobAction;
use App\Models\AnalysisJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue infrastructure entry point for executing an AnalysisJob.
 *
 * This job only carries the AnalysisJob identifier. It must remain a thin
 * infrastructure wrapper:
 * - a single execution attempt belongs to ExecuteAnalysisJobAction
 * - retry orchestration belongs to Laravel Queue (tries/backoff below)
 * - final (retry-exhausted) failure handling belongs to failed() below
 *
 * Phase 3-C adds a second dispatch point for the same AnalysisJob ID (the
 * Mapping confirmation resume path, see AnalysisJobController::updateMapping()),
 * doubling the surface area for an accidental duplicate dispatch (double
 * form submit, a second browser tab, a retried HTTP request). ShouldBeUnique
 * (uniqueId() below) is a *secondary* defense against that: Laravel's
 * atomic cache lock (verified safe in this environment — see
 * docs/product/MAPPING_CONTROL.md "ShouldBeUnique判断") refuses to queue a
 * second job for the same analysisJobId while one is already
 * pending/reserved. The primary defense is, and remains,
 * AnalysisJobController::updateMapping()'s own `lockForUpdate()` +
 * strict AnalysisJobStatus guard and ExecuteAnalysisJobAction's own
 * defensive no-op for a Job that is no longer in a runnable state —
 * both of those hold even if ShouldBeUnique were ever unavailable (e.g.
 * a future cache backend without atomic lock support), so this
 * interface is additive, not load-bearing.
 */
class ExecuteAnalysisJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How long (seconds) the uniqueness lock is held once a job starts
     * processing, per Laravel's ShouldBeUnique contract. Generously above
     * the observed real-world duration of a full attempt (Mapping AI +
     * Planning AI + Analyze AI have taken single-digit to low-double-digit
     * seconds combined in practice), so a slow AI response never lets a
     * duplicate dispatch slip through while this attempt is still
     * genuinely running.
     *
     * @var int
     */
    public int $uniqueFor = 300;

    /**
     * The number of times the job may be attempted.
     *
     * AnalysisJob execution can fail transiently (e.g. a temporary AI
     * provider outage), so Laravel Queue is allowed to retry a failed
     * attempt up to this many times before treating it as a final
     * failure. UpdateAnalysisJobAction::markProcessing() is retry-safe
     * (idempotent while already Processing), so intermediate attempts
     * never disturb AnalysisJob status. Only failed() below marks the
     * AnalysisJob Failed, and only once every attempt is exhausted.
     *
     * @var int
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $analysisJobId,
    ) {}

    /**
     * The lock key ShouldBeUnique uses: one AnalysisJob can never have
     * more than one queued/reserved ExecuteAnalysisJob at a time,
     * regardless of which of the two dispatch points (initial creation,
     * or Mapping confirmation resume) sent it.
     */
    public function uniqueId(): string
    {
        return (string) $this->analysisJobId;
    }

    /**
     * The number of seconds to wait before each retry attempt.
     *
     * With tries = 3, the job can be retried twice after the initial attempt,
     * using 5 seconds and 10 seconds respectively.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10];
    }

    /**
     * Execute one attempt of the job.
     *
     * Failures are logged for observability and then re-thrown unchanged so
     * Laravel Queue can apply its standard retry / final-failure lifecycle.
     *
     * @param ExecuteAnalysisJobAction $action
     * @return void
     */
    public function handle(ExecuteAnalysisJobAction $action): void
    {
        try {
            $action->execute($this->analysisJobId);
        } catch (Throwable $exception) {
            Log::warning('AnalysisJob execution attempt failed.', [
                'analysis_job_id' => $this->analysisJobId,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Handle the final job failure once every retry attempt is exhausted.
     *
     * Laravel calls failed() as a plain method call (not through the
     * container), so dependencies are not method-injected here the way
     * they are for handle(); they are resolved from the container
     * explicitly instead.
     *
     * @param Throwable|null $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        $analysisJob = AnalysisJob::query()->find($this->analysisJobId);

        if ($analysisJob === null) {
            return;
        }

        $errorMessage = $exception?->getMessage() ?? 'Analysis job failed.';

        Log::error('AnalysisJob failed after all retry attempts.', [
            'analysis_job_id' => $this->analysisJobId,
            'attempts' => $this->tries,
            'exception' => $errorMessage,
        ]);

        try {
            app(UpdateAnalysisJobAction::class)->markFailed($analysisJob, $errorMessage);
        } catch (Throwable $markFailedException) {
            Log::critical('Failed to record final AnalysisJob failure.', [
                'analysis_job_id' => $this->analysisJobId,
                'original_exception' => $errorMessage,
                'mark_failed_exception' => $markFailedException->getMessage(),
            ]);
        }
    }
}
