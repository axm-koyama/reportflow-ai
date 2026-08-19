<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\CreateAnalysisJobAction;
use App\Enums\AnalysisJobStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. AnalysisJob と AnalysisJobDetail を同時作成できる
     */
    public function test_it_creates_an_analysis_job_and_its_detail_together(): void
    {
        // Fake the queue so ExecuteAnalysisJob's afterCommit() dispatch
        // isn't actually run inline by the test environment's sync queue
        // driver. This test only exercises CreateAnalysisJobAction's own
        // responsibility (creating the records), not the full analysis
        // pipeline.
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute(
            $dataFile,
            '売上傾向分析',
            'CSVを分析して傾向を要約してください。',
        );

        $this->assertDatabaseCount('analysis_jobs', 1);
        $this->assertDatabaseCount('analysis_job_details', 1);

        $this->assertDatabaseHas('analysis_job_details', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
        ]);
    }

    /**
     * 2. 初期 status が Pending（Enum比較。raw integer比較はしない）
     */
    public function test_the_created_analysis_job_starts_as_pending(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute($dataFile, 'タイトル', 'プロンプト');

        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);
    }

    /**
     * 3. title が保存される
     */
    public function test_title_is_saved_on_the_analysis_job(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute($dataFile, '地域別売上比較', 'プロンプト');

        $this->assertSame('地域別売上比較', $analysisJob->title);
        $this->assertDatabaseHas('analysis_jobs', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'title' => '地域別売上比較',
        ]);
    }

    /**
     * 4. prompt は AnalysisJobDetail に保存される（AnalysisJob 本体には持たない）
     */
    public function test_prompt_is_saved_on_the_detail_and_not_on_the_analysis_job(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute(
            $dataFile,
            'タイトル',
            '売上低下の原因を分析してください。',
        );

        $this->assertArrayNotHasKey('prompt', $analysisJob->getAttributes());

        $this->assertDatabaseHas('analysis_job_details', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'prompt' => '売上低下の原因を分析してください。',
        ]);
    }

    /**
     * 5. nullable な Detail フィールドは初期状態で null
     */
    public function test_detail_nullable_fields_start_as_null(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute($dataFile, 'タイトル', 'プロンプト');

        $detail = AnalysisJobDetail::query()->find($analysisJob->analysis_job_id);

        $this->assertNull($detail->raw_response);
        $this->assertNull($detail->result);
        $this->assertNull($detail->error_message);
        $this->assertNull($detail->started_at);
        $this->assertNull($detail->completed_at);
    }

    /**
     * 6. 同一 DataFile に複数 AnalysisJob を作成可能
     */
    public function test_multiple_analysis_jobs_can_be_created_for_the_same_data_file(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();
        $action = new CreateAnalysisJobAction;

        $action->execute($dataFile, '売上傾向分析', 'プロンプト1');
        $action->execute($dataFile, '解約リスク顧客抽出', 'プロンプト2');

        $this->assertSame(2, $dataFile->analysisJobs()->count());
    }

    /**
     * 7. 同一 title の AnalysisJob を複数作成可能（unique制約がないこと）
     */
    public function test_multiple_analysis_jobs_with_the_same_title_can_be_created(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();
        $action = new CreateAnalysisJobAction;

        $action->execute($dataFile, '売上傾向分析', 'プロンプト1');
        $action->execute($dataFile, '売上傾向分析', 'プロンプト2');

        $this->assertSame(
            2,
            AnalysisJob::query()
                ->where('data_file_id', $dataFile->data_file_id)
                ->where('title', '売上傾向分析')
                ->count()
        );
    }

    /**
     * 8. 別 DataFile にそれぞれ AnalysisJob を作成可能
     */
    public function test_analysis_jobs_can_be_created_for_different_data_files(): void
    {
        Queue::fake();

        $dataFileA = DataFile::factory()->create();
        $dataFileB = DataFile::factory()->create();
        $action = new CreateAnalysisJobAction;

        $jobA = $action->execute($dataFileA, 'A用分析', 'プロンプトA');
        $jobB = $action->execute($dataFileB, 'B用分析', 'プロンプトB');

        $this->assertSame($dataFileA->data_file_id, $jobA->data_file_id);
        $this->assertSame($dataFileB->data_file_id, $jobB->data_file_id);
        $this->assertNotSame($jobA->analysis_job_id, $jobB->analysis_job_id);
    }

    /**
     * 9. ExecuteAnalysisJob が dispatch される
     */
    public function test_it_dispatches_execute_analysis_job(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute($dataFile, 'タイトル', 'プロンプト');

        Queue::assertPushed(
            ExecuteAnalysisJob::class,
            fn (ExecuteAnalysisJob $job): bool => $job->analysisJobId === $analysisJob->analysis_job_id,
        );
    }

    /**
     * 10. Queue::fake() を利用してもAnalysisJob / AnalysisJobDetailの作成は正常
     */
    public function test_analysis_job_and_detail_are_still_created_when_queue_is_faked(): void
    {
        Queue::fake();

        $dataFile = DataFile::factory()->create();

        $analysisJob = (new CreateAnalysisJobAction)->execute($dataFile, 'タイトル', 'プロンプト');

        $this->assertDatabaseCount('analysis_jobs', 1);
        $this->assertDatabaseCount('analysis_job_details', 1);
        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);
    }
}
