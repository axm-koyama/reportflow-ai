<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HtmlReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_ignores_injected_fields_redirects_and_duplicate_opens_existing(): void
    {
        [$project, $job] = $this->completedSource();
        $url = route('projects.analysis-jobs.reports.store', [$project, $job]);

        $first = $this->post($url, [
            'title' => 'Injected', 'rendered_html' => '<script>bad</script>', 'snapshot_json' => ['bad' => true], 'status' => 0,
        ]);
        $report = Report::query()->sole();
        $first->assertRedirect(route('projects.reports.show', [$project, $report]))->assertSessionHas('success', 'Report generated.');
        $this->assertSame($job->title, $report->title);
        $this->assertStringNotContainsString('<script>bad</script>', $report->rendered_html);

        $this->post($url)
            ->assertRedirect(route('projects.reports.show', [$project, $report]))
            ->assertSessionHas('success', 'Opening the existing report.');
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_show_uses_persisted_html_after_source_mutation_and_checks_project_scope(): void
    {
        [$project, $job] = $this->completedSource();
        $this->post(route('projects.analysis-jobs.reports.store', [$project, $job]));
        $report = Report::query()->sole();
        $storedHtml = $report->rendered_html;
        $job->update(['title' => 'Mutated source']);
        $job->analysisJobDetail()->update(['result' => ['summary' => 'Mutated summary']]);

        $response = $this->get(route('projects.reports.show', [$project, $report]))->assertOk();
        $this->assertStringContainsString($storedHtml, $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), '<h1>'));
        $this->assertMatchesRegularExpression('/@media print\s*\{.*\.app-nav, \.page-actions, \.breadcrumb/s', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/@media print\s*\{[^}]*\.page-header[^}]*display:\s*none/s', $response->getContent());
        $response->assertSee($report->generated_at->format('Y-m-d H:i:s'));
        $response->assertDontSee('Mutated summary')->assertDontSee('PDF')->assertDontSee('<form', false);
        $this->get(route('projects.reports.show', [Project::factory()->create(), $report]))->assertNotFound();
    }

    public function test_show_preserves_legacy_bare_table_report_and_scopes_minimum_width_to_new_table_wrappers(): void
    {
        [$project, $job] = $this->completedSource();
        $legacyHtml = '<article class="html-report"><h1>Legacy report</h1><table><tr><td>Legacy cell</td></tr></table></article>';
        $report = Report::factory()->for($job)->create([
            'rendered_html' => $legacyHtml,
            'content_hash' => hash('sha256', $legacyHtml),
            'renderer_version' => 'report_renderer_v1.0',
        ]);
        $freshReport = $report->fresh();
        $before = $freshReport->only(['rendered_html', 'content_hash', 'renderer_version']);
        $updatedAt = $freshReport->updated_at->toISOString();

        $content = $this->get(route('projects.reports.show', [$project, $report]))
            ->assertOk()
            ->assertSee($legacyHtml, false)
            ->getContent();

        $this->assertMatchesRegularExpression('/\.table-scroll\s+table\s*\{[^}]*min-width\s*:\s*640px/s', $content);
        $this->assertDoesNotMatchRegularExpression('/(?:^|})\s*table\s*\{[^}]*min-width\s*:\s*640px/s', $content);
        $this->assertStringContainsString('<div class="report-content">', $content);
        $this->assertMatchesRegularExpression('/\.report-content\s*\{[^}]*max-width\s*:\s*100%[^}]*overflow-x\s*:\s*auto/s', $content);
        $this->assertDoesNotMatchRegularExpression('/body\s*\{[^}]*overflow-x\s*:/s', $content);
        $this->assertDoesNotMatchRegularExpression('/@media print\s*\{[^}]*\.(?:report-content|html-report)[^}]*display\s*:\s*none/s', $content);
        $freshReport = $report->fresh();
        $this->assertSame($before, $freshReport->only(['rendered_html', 'content_hash', 'renderer_version']));
        $this->assertSame($updatedAt, $freshReport->updated_at->toISOString());
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_show_returns_404_for_soft_deleted_source_and_uses_same_report_after_restore(): void
    {
        [$project, $job] = $this->completedSource();
        $this->post(route('projects.analysis-jobs.reports.store', [$project, $job]));
        $report = Report::query()->sole();
        $storedHtml = $report->rendered_html;
        $job->delete();

        $this->get(route('projects.reports.show', [$project, $report]))->assertNotFound();
        $this->assertDatabaseHas('reports', ['report_id' => $report->report_id]);

        $job->restore();
        $this->get(route('projects.reports.show', [$project, $report]))
            ->assertOk()
            ->assertSee($storedHtml, false);
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_completed_detail_shows_generate_then_view_and_other_statuses_show_neither(): void
    {
        [$project, $completed] = $this->completedSource();
        $this->get(route('projects.analysis-jobs.show', [$project, $completed]))
            ->assertSee('Generate HTML Report')->assertDontSee('View HTML Report');
        $this->post(route('projects.analysis-jobs.reports.store', [$project, $completed]));
        $this->get(route('projects.analysis-jobs.show', [$project, $completed]))
            ->assertSee('View HTML Report')->assertDontSee('Generate HTML Report');

        foreach ([AnalysisJobStatus::Pending, AnalysisJobStatus::Processing, AnalysisJobStatus::AwaitingMappingConfirmation, AnalysisJobStatus::Failed] as $status) {
            [$statusProject, $job] = $this->completedSource($status);
            $this->get(route('projects.analysis-jobs.show', [$statusProject, $job]))
                ->assertDontSee('Generate HTML Report')->assertDontSee('View HTML Report');
        }
    }

    public function test_analysis_detail_report_relation_selects_only_identity_columns(): void
    {
        [$project, $job] = $this->completedSource();
        Report::factory()->for($job)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('projects.analysis-jobs.show', [$project, $job]))
            ->assertOk()
            ->assertSee('View HTML Report');

        $reportRelationQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(function (string $sql): bool {
                $normalizedSql = str_replace(['"', '`'], '', strtolower($sql));

                return str_contains($normalizedSql, 'from reports')
                    && str_contains($normalizedSql, 'analysis_job_id in');
            })
            ->values();
        $this->assertCount(1, $reportRelationQueries);
        $normalizedSql = str_replace(['"', '`'], '', strtolower($reportRelationQueries->first()));
        $this->assertStringContainsString('report_id', $normalizedSql);
        $this->assertStringContainsString('analysis_job_id', $normalizedSql);
        $this->assertStringNotContainsString('rendered_html', $normalizedSql);
        $this->assertStringNotContainsString('snapshot_json', $normalizedSql);
    }

    public function test_archived_project_cannot_generate_but_existing_report_remains_viewable(): void
    {
        [$project, $job] = $this->completedSource();
        $this->post(route('projects.analysis-jobs.reports.store', [$project, $job]));
        $report = Report::query()->sole();
        $project->update(['status' => ProjectStatus::Archived]);

        $this->get(route('projects.reports.show', [$project, $report]))->assertOk();

        [$otherProject, $otherJob] = $this->completedSource(projectStatus: ProjectStatus::Archived);
        $this->get(route('projects.analysis-jobs.show', [$otherProject, $otherJob]))
            ->assertSee('HTML Reports can only be generated while the project is active.')
            ->assertDontSee('Generate HTML Report');
    }

    public function test_archived_duplicate_post_returns_existing_report_without_mutation_or_side_effects(): void
    {
        Queue::fake();
        Http::fake();
        [$project, $job] = $this->completedSource();
        $this->post(route('projects.analysis-jobs.reports.store', [$project, $job]));
        $report = Report::query()->sole();
        $before = $report->only(['snapshot_json', 'rendered_html', 'content_hash', 'updated_at']);
        $project->update(['status' => ProjectStatus::Archived]);

        $this->post(route('projects.analysis-jobs.reports.store', [$project, $job]))
            ->assertRedirect(route('projects.reports.show', [$project, $report]))
            ->assertSessionHas('success', 'Opening the existing report.');

        $this->assertDatabaseCount('reports', 1);
        $this->assertEquals($before, $report->fresh()->only(['snapshot_json', 'rendered_html', 'content_hash', 'updated_at']));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** @return array{Project, AnalysisJob} */
    private function completedSource(AnalysisJobStatus $status = AnalysisJobStatus::Completed, ProjectStatus $projectStatus = ProjectStatus::Active): array
    {
        $project = Project::factory()->create(['status' => $projectStatus]);
        $dataFile = DataFile::factory()->for($project)->create(['original_name' => 'source.csv']);
        $job = AnalysisJob::factory()->for($dataFile)->create(['status' => $status, 'title' => 'Report source']);
        AnalysisJobDetail::factory()->for($job)->create(['completed_at' => now(), 'result' => [
            'summary' => 'Persisted summary', 'highlights' => [], 'metrics' => [], 'tables' => [], 'insights' => [], 'recommendations' => [],
        ]]);

        return [$project, $job];
    }
}
