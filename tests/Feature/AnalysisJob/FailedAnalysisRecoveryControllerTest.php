<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FailedAnalysisRecoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_post_redirects_to_a_server_copied_child_and_duplicate_opens_it(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $url = route('projects.analysis-jobs.recover', [$project, $source]);

        $first = $this->post($url, [
            'title' => 'Injected title',
            'template_key' => null,
            'data_file_id' => 999999,
            'status' => AnalysisJobStatus::Completed->value,
            'prompt' => 'Injected prompt',
        ]);
        $child = AnalysisJob::query()->where('recovered_from_analysis_job_id', $source->analysis_job_id)->firstOrFail();

        $first->assertRedirect(route('projects.analysis-jobs.show', [$project, $child]))
            ->assertSessionHas('success', 'A new recovery attempt was created. This attempt may make new AI calls and incur additional cost.');
        $this->assertSame($source->title, $child->title);
        $this->assertSame($source->template_key, $child->template_key);
        $this->assertSame($source->data_file_id, $child->data_file_id);
        $this->assertSame(AnalysisJobStatus::Pending, $child->status);
        $this->assertSame($source->analysisJobDetail->prompt, $child->analysisJobDetail->prompt);

        $this->post($url)
            ->assertRedirect(route('projects.analysis-jobs.show', [$project, $child]))
            ->assertSessionHas('success', 'A recovery attempt already exists. Opening the existing attempt.');
        Queue::assertPushed(ExecuteAnalysisJob::class, 1);
    }

    public function test_wrong_project_deleted_data_file_archived_project_and_non_failed_sources_are_rejected(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $otherProject = Project::factory()->create();

        $this->post(route('projects.analysis-jobs.recover', [$otherProject, $source]))->assertNotFound();

        $source->dataFile->delete();
        $this->post(route('projects.analysis-jobs.recover', [$project, $source]))->assertNotFound();

        [$archivedProject, $archivedSource] = $this->failedSource(ProjectStatus::Archived);
        $this->from(route('projects.analysis-jobs.show', [$archivedProject, $archivedSource]))
            ->post(route('projects.analysis-jobs.recover', [$archivedProject, $archivedSource]))
            ->assertRedirect()
            ->assertSessionHasErrors('project');

        foreach ([AnalysisJobStatus::Pending, AnalysisJobStatus::Processing, AnalysisJobStatus::Completed, AnalysisJobStatus::AwaitingMappingConfirmation] as $status) {
            [$statusProject, $statusSource] = $this->failedSource(status: $status);
            $this->from(route('projects.analysis-jobs.show', [$statusProject, $statusSource]))
                ->post(route('projects.analysis-jobs.recover', [$statusProject, $statusSource]))
                ->assertRedirect()
                ->assertSessionHasErrors('analysis_job');
        }

        $this->assertSame(0, AnalysisJob::query()->whereNotNull('recovered_from_analysis_job_id')->count());
        Queue::assertNothingPushed();
    }

    public function test_wrong_project_returns_404_without_any_recovery_side_effect(): void
    {
        Queue::fake();
        [$project, $source] = $this->failedSource();
        $otherProject = Project::factory()->create();
        $sourceBefore = $source->fresh()->toArray();
        $sourceDetailBefore = $source->analysisJobDetail()->firstOrFail()->toArray();
        $jobCountBefore = AnalysisJob::query()->count();
        $detailCountBefore = AnalysisJobDetail::query()->count();

        $this->post(route('projects.analysis-jobs.recover', [$otherProject, $source]))
            ->assertNotFound();

        $this->assertSame(0, AnalysisJob::query()->where('recovered_from_analysis_job_id', $source->analysis_job_id)->count());
        $this->assertSame($jobCountBefore, AnalysisJob::query()->count());
        $this->assertSame($detailCountBefore, AnalysisJobDetail::query()->count());
        $this->assertEquals($sourceBefore, $source->fresh()->toArray());
        $this->assertEquals($sourceDetailBefore, $source->analysisJobDetail()->firstOrFail()->toArray());
        Queue::assertNothingPushed();
    }

    public function test_history_and_detail_render_lineage_and_recovery_controls_in_the_correct_row_or_section(): void
    {
        [$project, $recoverable] = $this->failedSource();
        $dataFile = $recoverable->dataFile;
        $failedWithChild = AnalysisJob::factory()->for($dataFile)->create(['title' => 'Failed With Child', 'status' => AnalysisJobStatus::Failed]);
        AnalysisJobDetail::factory()->for($failedWithChild)->create(['error_message' => 'second failure']);
        $child = AnalysisJob::factory()->for($dataFile)->create([
            'title' => 'Recovery Child',
            'status' => AnalysisJobStatus::Pending,
            'recovered_from_analysis_job_id' => $failedWithChild->analysis_job_id,
        ]);
        AnalysisJobDetail::factory()->for($child)->create();
        $completed = AnalysisJob::factory()->for($dataFile)->create(['title' => 'Completed Job', 'status' => AnalysisJobStatus::Completed]);
        AnalysisJobDetail::factory()->for($completed)->create();

        $history = $this->get(route('projects.analysis-jobs.index', $project))->assertOk();
        $recoverableRow = $this->row($history->getContent(), $recoverable->title);
        $sourceWithChildRow = $this->row($history->getContent(), $failedWithChild->title);
        $childRow = $this->row($history->getContent(), $child->title);
        $completedRow = $this->row($history->getContent(), $completed->title);

        $this->assertStringContainsString('Create Recovery Attempt', $recoverableRow);
        $this->assertStringContainsString('may make new AI calls and incur additional cost', $recoverableRow);
        $this->assertStringContainsString('method="POST"', $recoverableRow);
        $this->assertStringNotContainsString('View Recovery Attempt', $recoverableRow);
        $this->assertStringContainsString('View Recovery Attempt', $sourceWithChildRow);
        $this->assertStringContainsString(route('projects.analysis-jobs.show', [$project, $child]), $sourceWithChildRow);
        $this->assertStringNotContainsString('Create Recovery Attempt', $sourceWithChildRow);
        $this->assertStringNotContainsString('method="POST"', $sourceWithChildRow);
        $this->assertStringContainsString('Recovered from #'.$failedWithChild->analysis_job_id, $childRow);
        $this->assertStringContainsString(route('projects.analysis-jobs.show', [$project, $failedWithChild]), $childRow);
        $this->assertStringNotContainsString('Create Recovery Attempt', $childRow);
        $this->assertStringNotContainsString('method="POST"', $childRow);
        $this->assertStringNotContainsString('Create Recovery Attempt', $completedRow);
        $this->assertStringNotContainsString('View Recovery Attempt', $completedRow);
        $this->assertStringNotContainsString('method="POST"', $completedRow);

        $this->get(route('projects.analysis-jobs.show', [$project, $recoverable]))
            ->assertSee('Create Recovery Attempt')
            ->assertSee('This failed attempt remains unchanged.')
            ->assertSee('may make new AI calls and incur additional cost');
        $this->get(route('projects.analysis-jobs.show', [$project, $failedWithChild]))
            ->assertSee('View Recovery Attempt')
            ->assertDontSee('Create Recovery Attempt');
        $this->get(route('projects.analysis-jobs.show', [$project, $child]))
            ->assertSee('Recovered from AnalysisJob #'.$failedWithChild->analysis_job_id)
            ->assertSee(route('projects.analysis-jobs.show', [$project, $failedWithChild]), false);
    }

    /** @return array{Project, AnalysisJob} */
    private function failedSource(
        ProjectStatus $projectStatus = ProjectStatus::Active,
        AnalysisJobStatus $status = AnalysisJobStatus::Failed,
    ): array {
        $project = Project::factory()->create(['status' => $projectStatus]);
        $dataFile = DataFile::factory()->for($project)->create();
        $source = AnalysisJob::factory()->for($dataFile)->create([
            'title' => 'Failed Source '.uniqid(),
            'status' => $status,
            'template_key' => 'ad_performance',
        ]);
        AnalysisJobDetail::factory()->for($source)->create([
            'prompt' => 'Persisted source prompt',
            'error_message' => 'Original failure',
        ]);

        return [$project, $source];
    }

    private function row(string $html, string $title): string
    {
        $matched = preg_match_all('/<tr\b[^>]*>.*?<\/tr>/si', $html, $matches);
        $this->assertNotFalse($matched, 'Failed to parse table rows from the response HTML.');

        $escapedTitle = e($title);
        $matchingRows = array_values(array_filter(
            $matches[0],
            static fn (string $row): bool => str_contains($row, $escapedTitle),
        ));

        $this->assertCount(1, $matchingRows, "Expected exactly one table row containing title [{$title}].");

        return $matchingRows[0];
    }
}
