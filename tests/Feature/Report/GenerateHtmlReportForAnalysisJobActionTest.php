<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Actions\Report\GenerateHtmlReportForAnalysisJobAction;
use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\EvaluationFact;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GenerateHtmlReportForAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_source_creates_one_hashed_snapshot_report_without_side_effects(): void
    {
        Queue::fake();
        Http::fake();
        [$project, $job] = $this->completedSource();
        $fact = EvaluationFact::factory()->for($job, 'analysisJob')->create();
        $sourceBefore = $job->fresh()->toArray();
        $detailBefore = $job->analysisJobDetail()->firstOrFail()->toArray();
        $factBefore = $fact->fresh()->toArray();

        $result = app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
        $report = $result['report'];
        $freshReport = Report::query()->findOrFail($report->report_id);

        $this->assertTrue($result['created']);
        $this->assertSame(hash('sha256', $freshReport->rendered_html), $freshReport->content_hash);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $freshReport->content_hash);
        $this->assertSame('report_schema_v1.0', $freshReport->schema_version);
        $this->assertSame('report_schema_v1.0', $freshReport->snapshot_json['schema_version']);
        $this->assertSame('report_renderer_v1.1', $freshReport->renderer_version);
        $this->assertArrayHasKey('source', $freshReport->snapshot_json);
        $this->assertArrayHasKey('analysis', $freshReport->snapshot_json);
        $this->assertArrayHasKey('controlled_actions', $freshReport->snapshot_json);
        $this->assertEquals($sourceBefore, $job->fresh()->toArray());
        $this->assertEquals($detailBefore, $job->analysisJobDetail()->firstOrFail()->toArray());
        $this->assertEquals($factBefore, $fact->fresh()->toArray());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_duplicate_generation_returns_same_immutable_report(): void
    {
        [$project, $job] = $this->completedSource();
        $action = app(GenerateHtmlReportForAnalysisJobAction::class);
        $first = $action->execute($project, $job);
        $storedHtml = $first['report']->rendered_html;
        $job->analysisJobDetail()->update(['result' => ['summary' => 'Later mutation']]);
        $second = $action->execute($project, $job);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['report']->report_id, $second['report']->report_id);
        $this->assertSame($storedHtml, $second['report']->rendered_html);
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_simulated_unique_race_returns_competing_report(): void
    {
        Queue::fake();
        [$project, $job] = $this->completedSource();
        $competingId = null;

        Report::creating(function (Report $creating) use (&$competingId): void {
            if ($competingId !== null) {
                return;
            }

            $now = now();
            $html = '<article>Competing immutable report</article>';
            $competingId = DB::table('reports')->insertGetId([
                'analysis_job_id' => $creating->analysis_job_id,
                'title' => 'Competing report',
                'snapshot_json' => json_encode(['schema_version' => 'report_schema_v1.0'], JSON_THROW_ON_ERROR),
                'rendered_html' => $html,
                'schema_version' => 'report_schema_v1.0',
                'renderer_version' => 'report_renderer_v1.0',
                'content_hash' => hash('sha256', $html),
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'report_id');
        });

        try {
            $result = app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
        } finally {
            Report::flushEventListeners();
            Report::clearBootedModels();
        }

        $this->assertFalse($result['created']);
        $this->assertSame($competingId, $result['report']->report_id);
        $this->assertDatabaseCount('reports', 1);
        Queue::assertNothingPushed();
    }

    public function test_unrelated_database_exception_is_rethrown(): void
    {
        [$project, $job] = $this->completedSource();
        Report::creating(function (): void {
            $previous = new PDOException("Duplicate entry '1' for key 'reports_other_uniq'");
            $previous->errorInfo = ['23000', 1062, $previous->getMessage()];

            throw new QueryException('testing', 'insert into reports', [], $previous);
        });

        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
            $this->fail('Expected unrelated QueryException to be rethrown.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('reports_other_uniq', $exception->getMessage());
            $this->assertDatabaseCount('reports', 0);
        } finally {
            Report::flushEventListeners();
            Report::clearBootedModels();
        }
    }

    public function test_non_completed_archived_cross_project_and_missing_result_are_rejected(): void
    {
        foreach ([AnalysisJobStatus::Pending, AnalysisJobStatus::Processing, AnalysisJobStatus::AwaitingMappingConfirmation, AnalysisJobStatus::Failed] as $status) {
            [$project, $job] = $this->completedSource($status);
            try {
                app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
                $this->fail("Expected {$status->name} to be rejected.");
            } catch (ValidationException) {
                $this->assertDatabaseMissing('reports', ['analysis_job_id' => $job->analysis_job_id]);
            }
        }

        [$archived, $archivedJob] = $this->completedSource(projectStatus: ProjectStatus::Archived);
        $this->expectException(ValidationException::class);
        app(GenerateHtmlReportForAnalysisJobAction::class)->execute($archived, $archivedJob);
    }

    public function test_cross_project_and_missing_result_create_no_report(): void
    {
        [$project, $job] = $this->completedSource();
        $otherProject = Project::factory()->create();
        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($otherProject, $job);
            $this->fail('Expected cross-project rejection.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseCount('reports', 0);
        }

        $job->analysisJobDetail()->update(['result' => null]);
        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
            $this->fail('Expected missing result rejection.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('reports', 0);
        }

        [$missingDetailProject, $missingDetailJob] = $this->completedSource();
        $missingDetailJob->analysisJobDetail()->delete();
        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($missingDetailProject, $missingDetailJob);
            $this->fail('Expected missing detail rejection.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('reports', 0);
        }
    }

    public function test_soft_deleted_source_or_data_file_is_rejected(): void
    {
        [$project, $job] = $this->completedSource();
        $job->delete();
        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
            $this->fail('Expected deleted source rejection.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('reports', 0);
        }

        [$secondProject, $secondJob] = $this->completedSource();
        $secondJob->dataFile->delete();
        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($secondProject, $secondJob);
            $this->fail('Expected deleted DataFile rejection.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseCount('reports', 0);
        }
    }

    #[DataProvider('malformedResultProvider')]
    public function test_malformed_result_is_rejected_without_report_ai_or_queue(
        string $case,
        mixed $malformedResult,
    ): void {
        Queue::fake();
        Http::fake();
        [$project, $job] = $this->completedSource();
        $job->analysisJobDetail()->update(['result' => $malformedResult]);

        try {
            app(GenerateHtmlReportForAnalysisJobAction::class)->execute($project, $job);
            $this->fail("Expected malformed result case [{$case}] to be rejected.");
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('reports', ['analysis_job_id' => $job->analysis_job_id]);
            $this->assertDatabaseCount('reports', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function malformedResultProvider(): iterable
    {
        $valid = [
            'summary' => 'Valid summary',
            'highlights' => [],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
            'recommendations' => [],
        ];

        yield 'result is not an array' => ['result is not an array', 'not-an-array'];
        yield 'summary is empty' => ['summary is empty', [...$valid, 'summary' => '']];
        yield 'list section is associative' => ['list section is associative', [...$valid, 'highlights' => ['first' => 'value']]];
        yield 'metric required field is missing' => ['metric required field is missing', [...$valid, 'metrics' => [['value' => '10']]]];
        yield 'table required field has wrong type' => ['table required field has wrong type', [...$valid, 'tables' => [['title' => 'Table', 'columns' => 'A', 'rows' => []]]]];
        yield 'table row width differs from columns' => [
            'table row width differs from columns',
            [...$valid, 'tables' => [['title' => 'Table', 'columns' => ['A'], 'rows' => [['one', 'two']]]]],
        ];
        yield 'insight required field is missing' => ['insight required field is missing', [...$valid, 'insights' => [['title' => 'Insight']]]];
        yield 'recommendation required field has wrong type' => ['recommendation required field has wrong type', [...$valid, 'recommendations' => [['title' => 'Recommendation', 'description' => 10]]]];
        yield 'table cell is not a string' => ['table cell is not a string', [...$valid, 'tables' => [['title' => 'Table', 'columns' => ['A'], 'rows' => [[10]]]]]];
    }

    /** @return array{Project, AnalysisJob} */
    private function completedSource(AnalysisJobStatus $status = AnalysisJobStatus::Completed, ProjectStatus $projectStatus = ProjectStatus::Active): array
    {
        $project = Project::factory()->create(['status' => $projectStatus]);
        $job = AnalysisJob::factory()->for($project->dataFiles()->create([
            'original_name' => 'source.csv', 'stored_path' => 'source.csv', 'mime_type' => 'text/csv', 'size' => 10,
        ]))->create(['status' => $status]);
        AnalysisJobDetail::factory()->for($job)->create(['completed_at' => now(), 'result' => [
            'summary' => 'Stable', 'highlights' => [], 'metrics' => [], 'tables' => [], 'insights' => [], 'recommendations' => [],
        ]]);

        return [$project, $job];
    }
}
