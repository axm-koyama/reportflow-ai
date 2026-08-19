<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\UpdateAnalysisJobAction;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UpdateAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. markProcessing(): Pending → Processing に更新できる
     */
    public function test_mark_processing_transitions_pending_to_processing(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        (new UpdateAnalysisJobAction)->markProcessing($analysisJob);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNotNull($detail->started_at);
        $this->assertNull($detail->completed_at);
    }

    /**
     * 2. markProcessing(): Processing への再呼び出しは retry-safe（idempotent）。
     *    Laravel Queue retry で同じ attempt が markProcessing() を再度呼んでも
     *    例外は発生せず、status は Processing のまま維持される。
     */
    public function test_mark_processing_is_idempotent_when_already_processing(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $action = new UpdateAnalysisJobAction;
        $action->markProcessing($analysisJob);

        $analysisJob->refresh();
        $startedAt = $detail->refresh()->started_at;

        // Re-entering markProcessing() simulates a Laravel Queue retry of a
        // failed attempt: the AnalysisJob is already Processing.
        $action->markProcessing($analysisJob);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNotNull($detail->started_at);
    }

    /**
     * 3. markProcessing(): retry時 started_at は最初の attempt の時刻のまま
     *    変更されない（started_at は分析処理全体の開始時刻を表す）。
     */
    public function test_mark_processing_does_not_overwrite_started_at_on_retry(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $action = new UpdateAnalysisJobAction;
        $action->markProcessing($analysisJob);

        $analysisJob->refresh();
        $firstStartedAt = $detail->refresh()->started_at;

        $this->travel(5)->minutes();
        $action->markProcessing($analysisJob);
        $this->travelBack();

        $detail->refresh();

        $this->assertTrue($detail->started_at->equalTo($firstStartedAt));
    }

    /**
     * 4. markProcessing(): Completed からは不可
     */
    public function test_mark_processing_throws_when_already_completed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Completed]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markProcessing($analysisJob);
    }

    /**
     * 5. markProcessing(): Failed からは不可
     */
    public function test_mark_processing_throws_when_already_failed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Failed]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markProcessing($analysisJob);
    }

    /**
     * markCompleted(): Processing → Completed に更新できる
     */
    public function test_mark_completed_transitions_processing_to_completed(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $action = new UpdateAnalysisJobAction;
        $action->markProcessing($analysisJob);
        $analysisJob->refresh();
        $startedAt = $detail->refresh()->started_at;

        $result = [
            'summary' => '売上が減少しました',
            'highlights' => [],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
        ];

        $action->markCompleted($analysisJob, 'raw ai response', $result);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame('raw ai response', $detail->raw_response);
        $this->assertSame($result, $detail->result);
        $this->assertNull($detail->error_message);
        $this->assertNotNull($detail->completed_at);
        $this->assertNotNull($detail->started_at);
        $this->assertTrue($detail->started_at->equalTo($startedAt));
    }

    /**
     * 4a. markCompleted(): Pending からは不可
     */
    public function test_mark_completed_throws_when_pending(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markCompleted($analysisJob, 'raw', ['summary' => 's']);
    }

    /**
     * 4b. markCompleted(): Completed からは不可
     */
    public function test_mark_completed_throws_when_already_completed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Completed]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markCompleted($analysisJob, 'raw', ['summary' => 's']);
    }

    /**
     * 4c. markCompleted(): Failed からは不可
     */
    public function test_mark_completed_throws_when_failed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Failed]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markCompleted($analysisJob, 'raw', ['summary' => 's']);
    }

    /**
     * 5. markFailed(): Pending → Failed に更新できる
     */
    public function test_mark_failed_transitions_pending_to_failed(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        (new UpdateAnalysisJobAction)->markFailed($analysisJob, 'AI呼び出しに失敗しました');

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Failed, $analysisJob->status);
        $this->assertSame('AI呼び出しに失敗しました', $detail->error_message);
        $this->assertNotNull($detail->completed_at);
    }

    /**
     * 6. markFailed(): Processing → Failed に更新できる
     */
    public function test_mark_failed_transitions_processing_to_failed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Processing]);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['started_at' => now()]);

        (new UpdateAnalysisJobAction)->markFailed($analysisJob, 'AI呼び出しに失敗しました');

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Failed, $analysisJob->status);
        $this->assertSame('AI呼び出しに失敗しました', $detail->error_message);
        $this->assertNotNull($detail->completed_at);
    }

    /**
     * 7. markFailed(): Completed からは不可
     */
    public function test_mark_failed_throws_when_already_completed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Completed]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markFailed($analysisJob, 'エラー');
    }

    /**
     * 8. markFailed(): Failed からは不可
     */
    public function test_mark_failed_throws_when_already_failed(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Failed]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markFailed($analysisJob, 'エラー');
    }

    /**
     * 9. Detail invariant: markProcessing() は Detail が存在しない場合 RuntimeException
     */
    public function test_mark_processing_throws_when_detail_is_missing(): void
    {
        $analysisJob = AnalysisJob::factory()->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markProcessing($analysisJob);
    }

    /**
     * 6. Transaction rollback: Detail不在によるRuntimeException発生時、
     *    AnalysisJob.status の更新は rollback され Pending のまま維持される
     *    （retry-safe化後も維持）
     */
    public function test_mark_processing_rolls_back_status_when_detail_is_missing(): void
    {
        $analysisJob = AnalysisJob::factory()->create();

        try {
            (new UpdateAnalysisJobAction)->markProcessing($analysisJob);

            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            // Expected.
        }

        $analysisJob->refresh();

        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);
    }

    /**
     * 10. Detail invariant: markCompleted() は Detail が存在しない場合 RuntimeException
     */
    public function test_mark_completed_throws_when_detail_is_missing(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Processing]);

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markCompleted($analysisJob, 'raw', ['summary' => 's']);
    }

    /**
     * 11. Detail invariant: markFailed() は Detail が存在しない場合 RuntimeException
     */
    public function test_mark_failed_throws_when_detail_is_missing(): void
    {
        $analysisJob = AnalysisJob::factory()->create();

        $this->expectException(RuntimeException::class);

        (new UpdateAnalysisJobAction)->markFailed($analysisJob, 'エラー');
    }
}
