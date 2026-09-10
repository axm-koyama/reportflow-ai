<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_relationships_versions_and_hash_are_persisted(): void
    {
        $analysisJob = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Completed]);
        $report = Report::factory()->for($analysisJob)->create();

        $this->assertTrue($report->analysisJob->is($analysisJob));
        $this->assertTrue($analysisJob->report->is($report));
        $this->assertIsArray($report->snapshot_json);
        $this->assertSame('report_schema_v1.0', $report->schema_version);
        $this->assertSame('report_renderer_v1.0', $report->renderer_version);
        $this->assertSame(hash('sha256', $report->rendered_html), $report->content_hash);
    }

    public function test_only_one_report_may_exist_for_an_analysis_job(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        Report::factory()->for($analysisJob)->create();

        $this->expectException(QueryException::class);
        Report::factory()->for($analysisJob)->create();
    }

    public function test_report_is_cascade_deleted_with_analysis_job(): void
    {
        $analysisJob = AnalysisJob::factory()->create();
        AnalysisJobDetail::factory()->for($analysisJob)->create();
        $report = Report::factory()->for($analysisJob)->create();

        $analysisJob->forceDelete();

        $this->assertDatabaseMissing('reports', ['report_id' => $report->report_id]);
    }
}
