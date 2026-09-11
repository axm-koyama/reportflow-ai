<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Models\AnalysisJob;
use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_history_is_project_scoped_and_links_to_data_files(): void
    {
        $project = Project::factory()->create(['name' => 'Empty History Project']);

        $response = $this->get(route('projects.analysis-jobs.index', $project));

        $response
            ->assertOk()
            ->assertViewIs('analysis-jobs.index')
            ->assertViewHas('project', $project)
            ->assertSee('Analysis History - Empty History Project')
            ->assertSee('No analyses have been created for this project yet.')
            ->assertSee(route('projects.data-files.index', $project), false)
            ->assertDontSee('Open an analysis to view live status updates.')
            ->assertDontSee('/analysis-jobs/create', false)
            ->assertDontSee('Analyze')
            ->assertDontSee('Retry');
    }

    public function test_history_renders_identity_modes_statuses_timestamps_and_correct_actions(): void
    {
        $project = Project::factory()->create(['name' => 'History Project']);
        $dataFile = DataFile::factory()->for($project)->create(['original_name' => 'history-source.csv']);
        $otherProject = Project::factory()->create();
        $otherFile = DataFile::factory()->for($otherProject)->create();
        AnalysisJob::factory()->for($otherFile)->create(['title' => 'Other Project Secret Job']);

        $statuses = [
            AnalysisJobStatus::Pending,
            AnalysisJobStatus::Processing,
            AnalysisJobStatus::AwaitingMappingConfirmation,
            AnalysisJobStatus::Completed,
            AnalysisJobStatus::Failed,
        ];

        $jobs = [];

        foreach ($statuses as $status) {
            $jobs[] = AnalysisJob::factory()->for($dataFile)->create([
                'title' => 'Job '.$status->name,
                'template_key' => 'ad_performance',
                'status' => $status,
                'created_at' => '2026-09-07 10:11:00',
                'updated_at' => '2026-09-07 12:34:00',
            ]);
        }

        $freeJob = AnalysisJob::factory()->for($dataFile)->create([
            'title' => 'Free Job',
            'template_key' => null,
            'status' => AnalysisJobStatus::Pending,
        ]);
        $unknownModeJob = AnalysisJob::factory()->for($dataFile)->create([
            'title' => 'Unknown Mode Job',
            'template_key' => 'retired_template',
            'status' => AnalysisJobStatus::Completed,
        ]);

        $response = $this->get(route('projects.analysis-jobs.index', $project));

        $response->assertOk()
            ->assertSee('history-source.csv')
            ->assertSee('Free Analysis')
            ->assertSee(config('analysis_templates.ad_performance.name'))
            ->assertSee('retired_template')
            ->assertSee('2026-09-07 10:11')
            ->assertSee('2026-09-07 12:34')
            ->assertDontSee('Other Project Secret Job')
            ->assertDontSee('Retry');

        foreach ($jobs as $index => $job) {
            $status = $statuses[$index];
            $row = $this->analysisJobRow($response->getContent(), $job->title);

            $this->assertStringContainsString(e($job->title), $row);
            $this->assertStringContainsString('history-source.csv', $row);
            $this->assertStringContainsString(e(config('analysis_templates.ad_performance.name')), $row);
            $this->assertStringContainsString($status->label(), $row);
            $this->assertStringContainsString($status->badgeClass(), $row);
            $this->assertStringContainsString(
                route('projects.analysis-jobs.show', [$project, $job]),
                $row,
            );
        }

        $freeRow = $this->analysisJobRow($response->getContent(), $freeJob->title);
        $this->assertStringContainsString('history-source.csv', $freeRow);
        $this->assertStringContainsString('Free Analysis', $freeRow);
        $this->assertStringContainsString(AnalysisJobStatus::Pending->label(), $freeRow);
        $this->assertStringContainsString(AnalysisJobStatus::Pending->badgeClass(), $freeRow);
        $this->assertStringContainsString(route('projects.analysis-jobs.show', [$project, $freeJob]), $freeRow);

        $unknownModeRow = $this->analysisJobRow($response->getContent(), $unknownModeJob->title);
        $this->assertStringContainsString('history-source.csv', $unknownModeRow);
        $this->assertStringContainsString('retired_template', $unknownModeRow);
        $this->assertStringContainsString(AnalysisJobStatus::Completed->label(), $unknownModeRow);
        $this->assertStringContainsString(AnalysisJobStatus::Completed->badgeClass(), $unknownModeRow);
        $this->assertStringContainsString(route('projects.analysis-jobs.show', [$project, $unknownModeJob]), $unknownModeRow);

        $this->assertSame(7, substr_count($response->getContent(), 'View Details'));
        $this->assertSame(1, substr_count($response->getContent(), 'Review Mapping'));
        $this->assertSame(1, substr_count($response->getContent(), '<form'));
    }

    public function test_review_mapping_link_appears_only_for_the_waiting_job(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $waiting = AnalysisJob::factory()->for($dataFile)->create([
            'status' => AnalysisJobStatus::AwaitingMappingConfirmation,
            'template_key' => 'ad_performance',
        ]);
        $failed = AnalysisJob::factory()->for($dataFile)->create([
            'status' => AnalysisJobStatus::Failed,
            'template_key' => 'ad_performance',
        ]);

        $response = $this->get(route('projects.analysis-jobs.index', $project));

        $response->assertSee(route('projects.analysis-jobs.mapping.edit', [$project, $waiting]), false)
            ->assertDontSee(route('projects.analysis-jobs.mapping.edit', [$project, $failed]), false);
    }

    public function test_history_renders_accessible_custom_pagination_for_twenty_five_jobs(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        AnalysisJob::factory()->count(25)->for($dataFile)->create();

        $firstPage = $this->get(route('projects.analysis-jobs.index', $project));
        $secondPage = $this->get(route('projects.analysis-jobs.index', $project).'?page=2');
        $firstPagination = $this->paginationNavigation($firstPage->getContent());
        $secondPagination = $this->paginationNavigation($secondPage->getContent());

        $firstPage->assertOk()
            ->assertSee('aria-label="Analysis History pagination"', false)
            ->assertSee('Previous')
            ->assertSee('Next')
            ->assertSee('?page=2', false)
            ->assertDontSee('<svg', false);
        $secondPage->assertOk()
            ->assertSee('?page=1', false)
            ->assertSee('Previous')
            ->assertSee('Next')
            ->assertDontSee('<svg', false);
        $this->assertSame(1, substr_count($firstPagination, 'aria-current="page"'));
        $this->assertSame(1, substr_count($secondPagination, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page">1<\/span>/', $firstPagination);
        $this->assertMatchesRegularExpression('/aria-current="page">2<\/span>/', $secondPagination);
        $this->assertMatchesRegularExpression('/aria-disabled="true">Previous<\/span>/', $firstPagination);
        $this->assertMatchesRegularExpression('/aria-disabled="true">Next<\/span>/', $secondPagination);
    }

    public function test_history_omits_pagination_navigation_for_twenty_or_fewer_jobs(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        AnalysisJob::factory()->count(20)->for($dataFile)->create();

        $this->get(route('projects.analysis-jobs.index', $project))
            ->assertOk()
            ->assertDontSee('aria-label="Analysis History pagination"', false);
    }

    public function test_archived_project_history_remains_readable(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $dataFile = DataFile::factory()->for($project)->create();
        AnalysisJob::factory()->for($dataFile)->create(['title' => 'Archived Project History']);

        $this->get(route('projects.analysis-jobs.index', $project))
            ->assertOk()
            ->assertSee('Archived Project History');
    }

    public function test_history_get_has_no_queue_or_database_side_effects(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $job = AnalysisJob::factory()->for($dataFile)->create(['status' => AnalysisJobStatus::Processing]);
        $beforeCounts = [
            'projects' => Project::query()->count(),
            'data_files' => DataFile::query()->count(),
            'analysis_jobs' => AnalysisJob::query()->count(),
        ];

        $this->get(route('projects.analysis-jobs.index', $project))->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame($beforeCounts, [
            'projects' => Project::query()->count(),
            'data_files' => DataFile::query()->count(),
            'analysis_jobs' => AnalysisJob::query()->count(),
        ]);
        $this->assertSame(AnalysisJobStatus::Processing, $job->fresh()->status);
    }

    public function test_existing_pages_link_to_history_and_detail_links_to_both_return_paths(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $job = AnalysisJob::factory()->for($dataFile)->create();

        $this->get(route('projects.index'))
            ->assertSee(route('projects.analysis-jobs.index', $project), false);
        $this->get(route('projects.data-files.index', $project))
            ->assertSee(route('projects.analysis-jobs.index', $project), false);
        $this->get(route('projects.analysis-jobs.show', [$project, $job]))
            ->assertSee(route('projects.analysis-jobs.index', $project), false)
            ->assertSee(route('projects.data-files.index', $project), false);
    }

    private function analysisJobRow(string $html, string $title): string
    {
        $matched = preg_match(
            '/<tr>.*?'.preg_quote(e($title), '/').'.*?<\/tr>/s',
            $html,
            $matches,
        );

        $this->assertSame(1, $matched, "Could not find the table row for AnalysisJob [{$title}].");

        return $matches[0];
    }

    private function paginationNavigation(string $html): string
    {
        $matched = preg_match(
            '/<nav class="pagination"[^>]*aria-label="Analysis History pagination".*?<\/nav>/s',
            $html,
            $matches,
        );

        $this->assertSame(1, $matched, 'Could not find the Analysis History pagination navigation.');

        return $matches[0];
    }
}
