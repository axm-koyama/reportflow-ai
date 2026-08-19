<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalysisJobModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. AnalysisJobFactory で AnalysisJob を作成できる
     */
    public function test_it_can_create_an_analysis_job_via_factory(): void
    {
        $analysisJob = AnalysisJob::factory()->create();

        $this->assertNotNull($analysisJob->analysis_job_id);
        $this->assertInstanceOf(DataFile::class, $analysisJob->dataFile);
        $this->assertNotEmpty($analysisJob->title);
        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);
    }

    /**
     * 2. status が AnalysisJobStatus Enum に cast される
     */
    public function test_status_is_cast_to_analysis_job_status_enum(): void
    {
        $analysisJob = AnalysisJob::factory()->create();

        $this->assertInstanceOf(AnalysisJobStatus::class, $analysisJob->status);
        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);

        // Re-fetch from the database to confirm the cast also applies
        // to the raw persisted integer value (0), not only the in-memory object.
        $analysisJob->refresh();

        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);
    }

    /**
     * 3. DataFile -> AnalysisJobs relationship（他 DataFile の AnalysisJob が混ざらないこと）
     */
    public function test_data_file_has_many_analysis_jobs(): void
    {
        $dataFileA = DataFile::factory()->create();
        $dataFileB = DataFile::factory()->create();

        $jobA1 = AnalysisJob::factory()->for($dataFileA)->create();
        $jobA2 = AnalysisJob::factory()->for($dataFileA)->create();
        AnalysisJob::factory()->for($dataFileB)->create();

        $jobs = $dataFileA->analysisJobs;

        $this->assertCount(2, $jobs);
        $this->assertTrue($jobs->contains('analysis_job_id', $jobA1->analysis_job_id));
        $this->assertTrue($jobs->contains('analysis_job_id', $jobA2->analysis_job_id));
    }

    /**
     * 4. AnalysisJob -> DataFile belongsTo
     */
    public function test_analysis_job_belongs_to_data_file(): void
    {
        $dataFile = DataFile::factory()->create();
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create();

        $this->assertTrue($analysisJob->dataFile->is($dataFile));
    }

    /**
     * 5. AnalysisJobDetailFactory で Detail を作成できる
     */
    public function test_it_can_create_an_analysis_job_detail_via_factory(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->assertSame($analysisJob->analysis_job_id, $detail->analysis_job_id);
        $this->assertNotEmpty($detail->prompt);
        $this->assertNull($detail->raw_response);
        $this->assertNull($detail->result);
        $this->assertNull($detail->error_message);
        $this->assertNull($detail->started_at);
        $this->assertNull($detail->completed_at);
    }

    /**
     * 6. AnalysisJob -> AnalysisJobDetail 1:1 relationship
     */
    public function test_analysis_job_has_one_analysis_job_detail(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->assertTrue($analysisJob->analysisJobDetail->is($detail));
    }

    /**
     * 7. AnalysisJobDetail -> AnalysisJob belongsTo
     */
    public function test_analysis_job_detail_belongs_to_analysis_job(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->assertTrue($detail->analysisJob->is($analysisJob));
    }

    /**
     * 8. AnalysisJobDetail の result cast
     */
    public function test_analysis_job_detail_result_is_cast_to_array(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $resultData = [
            'summary' => '2026年7月の売上は前月比12.4%減少しました。',
            'highlights' => ['関東エリアの売上が18.2%減少'],
        ];

        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create([
            'result' => $resultData,
        ]);

        $detail->refresh();

        $this->assertIsArray($detail->result);
        $this->assertSame($resultData, $detail->result);
    }

    /**
     * 9. started_at / completed_at cast
     */
    public function test_started_at_and_completed_at_are_cast_to_datetime(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        // The `started_at`/`completed_at` columns are second-precision timestamps,
        // so seed with second precision to avoid a microsecond mismatch after reload.
        $startedAt = now()->subMinutes(5)->startOfSecond();
        $completedAt = now()->startOfSecond();

        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create([
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);

        $detail->refresh();

        $this->assertInstanceOf(Carbon::class, $detail->started_at);
        $this->assertInstanceOf(Carbon::class, $detail->completed_at);
        $this->assertTrue($detail->started_at->equalTo($startedAt));
        $this->assertTrue($detail->completed_at->equalTo($completedAt));
    }

    /**
     * 10. AnalysisJob SoftDelete
     */
    public function test_soft_deleting_an_analysis_job_does_not_delete_the_row_or_its_detail(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $analysisJob->delete();

        $this->assertSoftDeleted('analysis_jobs', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
        ]);

        $this->assertNull(AnalysisJob::query()->find($analysisJob->analysis_job_id));

        $this->assertDatabaseHas('analysis_job_details', [
            'analysis_job_id' => $detail->analysis_job_id,
        ]);
    }

    /**
     * 11. AnalysisJob forceDelete -> Detail cascade
     */
    public function test_force_deleting_an_analysis_job_cascades_to_its_detail(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $analysisJob->forceDelete();

        $this->assertDatabaseMissing('analysis_jobs', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
        ]);
        $this->assertDatabaseMissing('analysis_job_details', [
            'analysis_job_id' => $detail->analysis_job_id,
        ]);
    }

    /**
     * 12. DataFile forceDelete -> AnalysisJob が存在する場合 restrict
     */
    public function test_data_file_force_delete_is_restricted_when_analysis_jobs_exist(): void
    {
        $dataFile = DataFile::factory()->create();
        AnalysisJob::factory()->for($dataFile)->create();

        $this->expectException(QueryException::class);

        $dataFile->forceDelete();
    }

    /**
     * Optional: 同一 DataFile に同じ title の AnalysisJob を複数作成できる（unique制約がないことの確認）
     */
    public function test_multiple_analysis_jobs_with_the_same_title_can_be_created_for_the_same_data_file(): void
    {
        $dataFile = DataFile::factory()->create();

        AnalysisJob::factory()->for($dataFile)->count(2)->create([
            'title' => '売上傾向分析',
        ]);

        $this->assertSame(
            2,
            AnalysisJob::query()
                ->where('data_file_id', $dataFile->data_file_id)
                ->where('title', '売上傾向分析')
                ->count()
        );
    }
}
