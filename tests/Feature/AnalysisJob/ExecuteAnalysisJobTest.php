<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Enums\AnalysisJobStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExecuteAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 3-C (AJ): ExecuteAnalysisJob implements ShouldBeUnique, keyed
     * by analysisJobId — a secondary defense (see the class docblock)
     * against a duplicate dispatch for the same AnalysisJob from either
     * of its two dispatch points (CreateAnalysisJobAction and the Mapping
     * confirmation resume). The primary defense
     * (AnalysisJobController::updateMapping()'s lockForUpdate() + status
     * guard, and ExecuteAnalysisJobAction's own no-op guard) holds
     * independently of this.
     */
    public function test_implements_should_be_unique_keyed_by_analysis_job_id(): void
    {
        $job = new ExecuteAnalysisJob(42);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());
    }

    /**
     * Dispatching the same AnalysisJob ID twice while the first dispatch
     * is still unique-locked results in only one job actually being
     * pushed onto the queue — this is Laravel's ShouldBeUnique contract,
     * exercised end-to-end against this app's actual cache configuration
     * (the "database" cache driver's cache_locks table) rather than
     * assumed.
     */
    public function test_duplicate_dispatch_for_the_same_analysis_job_id_is_only_queued_once(): void
    {
        Bus::fake([ExecuteAnalysisJob::class]);

        ExecuteAnalysisJob::dispatch(42);
        ExecuteAnalysisJob::dispatch(42);

        Bus::assertDispatchedTimes(ExecuteAnalysisJob::class, 1);
    }

    /**
     * Queue configuration: tries = 3
     */
    public function test_tries_is_three(): void
    {
        $job = new ExecuteAnalysisJob(1);

        $this->assertSame(3, $job->tries);
    }

    /**
     * Queue configuration: backoff = [5, 10]
     */
    public function test_backoff_returns_the_expected_staggered_delays(): void
    {
        $job = new ExecuteAnalysisJob(1);

        $this->assertSame([5, 10], $job->backoff());
    }

    /**
     * Queue configuration: analysisJobId が正しく保持される
     */
    public function test_job_holds_the_given_analysis_job_id(): void
    {
        $job = new ExecuteAnalysisJob(42);

        $this->assertSame(42, $job->analysisJobId);
    }

    /**
     * handle() が ExecuteAnalysisJobAction::execute($analysisJobId) を呼ぶ
     */
    public function test_handle_delegates_to_execute_analysis_job_action(): void
    {
        $analysisJob = AnalysisJob::factory()->create();

        $this->mock(ExecuteAnalysisJobAction::class)
            ->shouldReceive('execute')
            ->once()
            ->with($analysisJob->analysis_job_id);

        $job = new ExecuteAnalysisJob($analysisJob->analysis_job_id);

        // Resolve handle()'s dependencies through the container, the same
        // way Laravel's Queue worker invokes a job's handle() method.
        $this->app->call([$job, 'handle']);
    }

    /**
     * handle() は ExecuteAnalysisJobAction からの例外を握りつぶさず、
     * 同じ例外をそのまま再throwする（Laravel Queueがretryできるように）
     */
    public function test_handle_does_not_swallow_exceptions_from_the_action(): void
    {
        $this->mock(ExecuteAnalysisJobAction::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('AI呼び出しに失敗しました'));

        $job = new ExecuteAnalysisJob(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI呼び出しに失敗しました');

        $this->app->call([$job, 'handle']);
    }

    /**
     * attempt failure logging: handle() は失敗時に warning を記録してから
     * 例外を再throwする（contextの全項目までは厳密確認しない）
     */
    public function test_handle_logs_a_warning_and_still_rethrows_on_failure(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('AnalysisJob execution attempt failed.', Mockery::type('array'));

        $this->mock(ExecuteAnalysisJobAction::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('AI呼び出しに失敗しました'));

        $job = new ExecuteAnalysisJob(1);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI呼び出しに失敗しました', $e->getMessage());
        }
    }

    /**
     * failed(): Processing → Failed（最終失敗時の代表ケース）
     */
    public function test_failed_marks_a_processing_analysis_job_failed_with_error_message_and_completed_at(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Processing]);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['started_at' => now()]);

        $job = new ExecuteAnalysisJob($analysisJob->analysis_job_id);
        $job->failed(new RuntimeException('AI provider unavailable'));

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Failed, $analysisJob->status);
        $this->assertSame('AI provider unavailable', $detail->error_message);
        $this->assertNotNull($detail->completed_at);
    }

    /**
     * failed(): Pending → Failed（Queue処理開始前の final failure にも対応）
     */
    public function test_failed_marks_a_pending_analysis_job_failed(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $job = new ExecuteAnalysisJob($analysisJob->analysis_job_id);
        $job->failed(new RuntimeException('AI provider unavailable'));

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Failed, $analysisJob->status);
        $this->assertSame('AI provider unavailable', $detail->error_message);
        $this->assertNotNull($detail->completed_at);
    }

    /**
     * failed(null): exception が null の場合は fallback message を保存する
     */
    public function test_failed_uses_a_fallback_message_when_exception_is_null(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Processing]);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['started_at' => now()]);

        $job = new ExecuteAnalysisJob($analysisJob->analysis_job_id);
        $job->failed(null);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Failed, $analysisJob->status);
        $this->assertSame('Analysis job failed.', $detail->error_message);
    }

    /**
     * failed(): AnalysisJob が存在しない場合は例外にならず安全に終了する
     */
    public function test_failed_returns_safely_when_analysis_job_does_not_exist(): void
    {
        $job = new ExecuteAnalysisJob(999_999);

        $job->failed(new RuntimeException('エラー'));

        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    /**
     * final failure logging: failed() は error log を記録する
     * （contextの全項目までは厳密確認しない）
     */
    public function test_failed_logs_an_error_for_the_final_failure(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Processing]);
        AnalysisJobDetail::factory()->for($analysisJob)->create(['started_at' => now()]);

        Log::shouldReceive('error')
            ->once()
            ->with('AnalysisJob failed after all retry attempts.', Mockery::type('array'));

        $job = new ExecuteAnalysisJob($analysisJob->analysis_job_id);
        $job->failed(new RuntimeException('AI provider unavailable'));
    }

    /**
     * markFailed() 自体が失敗するケース（Detail不在）:
     * failed() は例外を外へ投げず、critical log を記録する
     */
    public function test_failed_logs_critical_and_does_not_throw_when_mark_failed_itself_fails(): void
    {
        // Processing AnalysisJob without its AnalysisJobDetail: this breaks
        // the AnalysisJob:AnalysisJobDetail invariant on purpose, so
        // UpdateAnalysisJobAction::markFailed() itself throws inside
        // failed(). No production code or schema changes are made for
        // this; the missing Detail is simply never created.
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Processing]);

        Log::shouldReceive('error')->once();
        Log::shouldReceive('critical')
            ->once()
            ->with('Failed to record final AnalysisJob failure.', Mockery::type('array'));

        $job = new ExecuteAnalysisJob($analysisJob->analysis_job_id);
        $job->failed(new RuntimeException('AI provider unavailable'));

        // No exception escaped failed(): reaching this point is the point
        // of the test. The AnalysisJob status is left as Processing since
        // markFailed()'s own transaction rolled back.
        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
    }
}
