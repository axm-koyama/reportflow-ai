<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the Mapping Preview / manual override Controller flow (Phase
 * 3-C), see docs/product/MAPPING_CONTROL.md. ExecuteAnalysisJobActionTest
 * covers the pipeline-level (auto-confident / AwaitingMappingConfirmation
 * transition) behavior; this file covers the HTTP-facing confirmation
 * flow: guards, idempotency, security, and that neither route ever calls
 * the AI.
 */
class AnalysisJobMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an ad_performance AnalysisJob already sitting in
     * AwaitingMappingConfirmation, with a real backing CSV
     * (channel,spend,revenue — no "channel"-mappable column, so "channel"
     * is unmapped) and the AI's (partial) column_mapping already
     * persisted, exactly as ExecuteAnalysisJobAction would have left it.
     *
     * @return array{0: Project, 1: AnalysisJob, 2: AnalysisJobDetail}
     */
    private function createAwaitingAdPerformanceJob(): array
    {
        Storage::fake('local');

        $project = Project::factory()->create();
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put(
            $storedPath,
            "media,spend,revenue\nEmail,10000,50000\nSocial,20000,30000\n",
        );

        $dataFile = DataFile::factory()->for($project)->create([
            'stored_path' => $storedPath,
            'original_name' => 'ads.csv',
        ]);

        $analysisJob = AnalysisJob::factory()->for($dataFile)->create([
            'template_key' => 'ad_performance',
            'status' => AnalysisJobStatus::AwaitingMappingConfirmation,
        ]);

        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create([
            'prompt' => '',
            'started_at' => now(),
            'column_mapping' => [
                'channel' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
                'campaign' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
                'spend' => ['column' => 'spend', 'confidence' => 'high', 'status' => 'mapped'],
                'revenue' => ['column' => 'revenue', 'confidence' => 'high', 'status' => 'mapped'],
                'conversions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
                'clicks' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
                'impressions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
                'date' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            ],
        ]);

        return [$project, $analysisJob, $detail];
    }

    /**
     * Mock AiAnalysisClient to fail the test loudly if any of its methods
     * are ever invoked — used to prove the Mapping Preview / confirm flow
     * never calls the AI (docs/product/MAPPING_CONTROL.md "AI呼び出し回数").
     */
    private function forbidAiCalls(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('mapColumns')
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');
    }

    // --- editMapping (GET) ------------------------------------------------

    public function test_edit_mapping_shows_candidates_and_ai_proposal_without_calling_the_ai(): void
    {
        $this->forbidAiCalls();
        [$project, $analysisJob] = $this->createAwaitingAdPerformanceJob();

        $this->get(route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob]))
            ->assertOk()
            ->assertSee('広告パフォーマンス分析')
            ->assertSee('media') // real candidate column, rebuilt from a fresh DataProfilingAction run
            ->assertSee('spend')
            ->assertSee('revenue');
    }

    public function test_edit_mapping_redirects_when_the_job_is_not_awaiting_confirmation(): void
    {
        $this->forbidAiCalls();
        [$project, $analysisJob] = $this->createAwaitingAdPerformanceJob();
        $analysisJob->update(['status' => AnalysisJobStatus::Completed]);

        $this->get(route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob]))
            ->assertRedirect(route('projects.analysis-jobs.show', [$project, $analysisJob]));
    }

    public function test_edit_mapping_404s_for_free_analysis(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => null]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->get(route('projects.analysis-jobs.mapping.edit', [$project, $analysisJob]))
            ->assertNotFound();
    }

    public function test_edit_mapping_404s_for_another_projects_analysis_job(): void
    {
        [, $analysisJob] = $this->createAwaitingAdPerformanceJob();
        $anotherProject = Project::factory()->create();

        $this->get(route('projects.analysis-jobs.mapping.edit', [$anotherProject, $analysisJob]))
            ->assertNotFound();
    }

    // --- updateMapping (PATCH): N/M — resume vs. still-missing -----------

    public function test_valid_manual_override_confirms_and_dispatches_resume(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob, $detail] = $this->createAwaitingAdPerformanceJob();

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['channel' => ['column' => 'media']],
        ])->assertRedirect(route('projects.analysis-jobs.show', [$project, $analysisJob]));

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);
        $this->assertSame('media', $detail->manual_column_mapping['channel']['column']);
        $this->assertSame('media', $detail->effective_column_mapping['channel']['column']);
        $this->assertSame('manual', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['spend']['source']);

        Queue::assertPushed(
            ExecuteAnalysisJob::class,
            fn (ExecuteAnalysisJob $job): bool => $job->analysisJobId === $analysisJob->analysis_job_id,
        );
    }

    /**
     * Regression found via real Browser E2E: the Mapping Preview form
     * (a plain HTML <select> per field) submits *every* field's current
     * value on confirm, not just the one(s) the user actually changed —
     * there is no browser-native way to submit only a diff. Naively
     * treating "present in the submitted mapping" as "the user touched
     * this" would tag every field "source: manual" on every confirm,
     * even fields whose value is identical to what the AI already
     * proposed. UpdateAnalysisJobMappingRequest::manualOverrides() must
     * diff each submitted value against the AI's own column_mapping and
     * only treat a field as a manual override when it actually differs.
     */
    public function test_submitting_the_full_form_with_unchanged_fields_does_not_tag_them_as_manual(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob, $detail] = $this->createAwaitingAdPerformanceJob();

        // Simulates the real <select> form: every field is submitted,
        // most at their AI-proposed default; only "channel" actually changes.
        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => [
                'channel' => ['column' => 'media'], // the only real change
                'campaign' => ['column' => ''],       // AI: unmapped -> unchanged
                'spend' => ['column' => 'spend'],     // AI: mapped to "spend" -> unchanged
                'revenue' => ['column' => 'revenue'], // AI: mapped to "revenue" -> unchanged
                'conversions' => ['column' => ''],
                'clicks' => ['column' => ''],
                'impressions' => ['column' => ''],
                'date' => ['column' => ''],
            ],
        ]);

        $detail->refresh();

        $this->assertSame(['channel'], array_keys($detail->manual_column_mapping));
        $this->assertSame('manual', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['spend']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['revenue']['source']);
    }

    public function test_manual_override_still_missing_required_stays_awaiting_and_does_not_dispatch(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob, $detail] = $this->createAwaitingAdPerformanceJob();

        // "channel" is required and left unset.
        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => [],
        ])->assertSessionHasErrors('mapping');

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
        $this->assertNull($detail->effective_column_mapping);
        Queue::assertNothingPushed();
    }

    /**
     * Manual override preserves partial edits (recordManualMappingAttempt)
     * even when required is still unmet, so the user does not lose their
     * work when the form is redisplayed with a validation error.
     */
    public function test_manual_override_still_missing_required_preserves_the_attempted_edits(): void
    {
        $this->forbidAiCalls();
        [$project, $analysisJob, $detail] = $this->createAwaitingAdPerformanceJob();

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['campaign' => ['column' => 'media']],
        ]);

        $detail->refresh();

        $this->assertSame('media', $detail->manual_column_mapping['campaign']['column']);
        $this->assertNull($detail->effective_column_mapping);
    }

    // --- O/P: double confirm / repeated PATCH -----------------------------

    public function test_double_confirm_only_dispatches_once(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob] = $this->createAwaitingAdPerformanceJob();

        $payload = ['mapping' => ['channel' => ['column' => 'media']]];

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), $payload)
            ->assertRedirect(route('projects.analysis-jobs.show', [$project, $analysisJob]));

        // Second submit (double click / second tab / refreshed form):
        // status is no longer AwaitingMappingConfirmation, so this must
        // be treated as already handled, not re-processed.
        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), $payload)
            ->assertRedirect(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertSessionHas('success');

        Queue::assertPushed(ExecuteAnalysisJob::class, 1);
    }

    // --- Q/R/S: already Processing/Completed/Failed -----------------------

    public function test_update_mapping_is_rejected_when_already_processing(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob, $detail] = $this->createAwaitingAdPerformanceJob();
        $analysisJob->update(['status' => AnalysisJobStatus::Processing]);

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['channel' => ['column' => 'media']],
        ]);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNull($detail->effective_column_mapping);
        Queue::assertNothingPushed();
    }

    public function test_update_mapping_is_rejected_when_already_completed(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob] = $this->createAwaitingAdPerformanceJob();
        $analysisJob->update(['status' => AnalysisJobStatus::Completed]);

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['channel' => ['column' => 'media']],
        ]);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        Queue::assertNothingPushed();
    }

    public function test_update_mapping_is_rejected_when_already_failed(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob] = $this->createAwaitingAdPerformanceJob();
        $analysisJob->update(['status' => AnalysisJobStatus::Failed]);

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['channel' => ['column' => 'media']],
        ]);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Failed, $analysisJob->status);
        Queue::assertNothingPushed();
    }

    // --- Security ----------------------------------------------------------

    public function test_update_mapping_rejects_an_unknown_semantic_field(): void
    {
        $this->forbidAiCalls();
        [$project, $analysisJob] = $this->createAwaitingAdPerformanceJob();

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['not_a_real_field' => ['column' => 'media']],
        ])->assertSessionHasErrors('mapping');

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
    }

    /**
     * UpdateAnalysisJobMappingRequest::authorize() rejects a cross-project
     * AnalysisJob before the request body is even validated — a 403, not
     * a 404 (editMapping's body-level guard produces a 404 instead; both
     * are "an appropriate response" for an access this app has no login
     * system to attribute to a specific user in the first place).
     */
    public function test_update_mapping_is_forbidden_for_another_projects_analysis_job(): void
    {
        [, $analysisJob] = $this->createAwaitingAdPerformanceJob();
        $anotherProject = Project::factory()->create();

        $this->patch(route('projects.analysis-jobs.mapping.update', [$anotherProject, $analysisJob]), [
            'mapping' => ['channel' => ['column' => 'media']],
        ])->assertForbidden();
    }

    public function test_update_mapping_is_forbidden_for_free_analysis(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => null]);
        AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => [],
        ])->assertForbidden();
    }

    /**
     * A column name that does not exist in this AnalysisJob's own
     * candidate list (e.g. it belongs to a different DataFile entirely,
     * or is simply fabricated) is forced unmapped server-side —
     * ValidateColumnMappingAction never trusts a client-submitted column
     * name at face value.
     */
    public function test_update_mapping_forces_an_unknown_column_name_to_unmapped(): void
    {
        $this->forbidAiCalls();
        Queue::fake();
        [$project, $analysisJob, $detail] = $this->createAwaitingAdPerformanceJob();

        $this->patch(route('projects.analysis-jobs.mapping.update', [$project, $analysisJob]), [
            'mapping' => ['channel' => ['column' => 'media'], 'campaign' => ['column' => 'some_other_datafiles_column']],
        ]);

        $detail->refresh();

        $this->assertSame('unmapped', $detail->effective_column_mapping['campaign']['status']);
    }
}
