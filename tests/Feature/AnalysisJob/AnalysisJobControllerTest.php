<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Enums\AnalysisJobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\ExecuteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisJobControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_is_displayed(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create(['original_name' => 'sales.csv']);

        $this->get(route('projects.data-files.analysis-jobs.create', [$project, $dataFile]))
            ->assertOk()
            ->assertSee($project->name)
            ->assertSee('sales.csv')
            ->assertSee('Start Analysis');
    }

    public function test_create_page_lists_the_available_analysis_templates(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        $this->get(route('projects.data-files.analysis-jobs.create', [$project, $dataFile]))
            ->assertOk()
            ->assertViewHas('analysisTemplates', config('analysis_templates'))
            ->assertSee('広告パフォーマンス分析');
    }

    public function test_create_returns_not_found_for_another_projects_data_file(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->create();

        $this->get(route('projects.data-files.analysis-jobs.create', [$project, $dataFile]))
            ->assertNotFound();
    }

    public function test_create_is_rejected_for_an_archived_project(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $dataFile = DataFile::factory()->for($project)->create();

        $this->from(route('projects.data-files.index', $project))
            ->get(route('projects.data-files.analysis-jobs.create', [$project, $dataFile]))
            ->assertRedirect(route('projects.data-files.index', $project))
            ->assertSessionHasErrors('project');
    }

    public function test_store_creates_records_dispatches_the_job_and_redirects_to_show(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        $response = $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => 'Regional sales analysis',
            'prompt' => 'Find the strongest regional trends.',
        ]);

        $analysisJob = AnalysisJob::query()->sole();
        $response->assertRedirect(route('projects.analysis-jobs.show', [$project, $analysisJob]));
        $this->assertDatabaseHas('analysis_jobs', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'title' => 'Regional sales analysis',
        ]);
        $this->assertDatabaseHas('analysis_job_details', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'prompt' => 'Find the strongest regional trends.',
        ]);
        Queue::assertPushed(
            ExecuteAnalysisJob::class,
            fn (ExecuteAnalysisJob $job): bool => $job->analysisJobId === $analysisJob->analysis_job_id,
        );
    }

    /**
     * template_key指定時: AnalysisJobへtemplate_keyが保存され、
     * promptは省略可能(自由分析のrequiredルールは適用されない)。
     */
    public function test_store_persists_the_template_key_and_allows_an_empty_prompt(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        $response = $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => '広告分析',
            'template_key' => 'ad_performance',
        ]);

        $analysisJob = AnalysisJob::query()->sole();
        $response->assertRedirect(route('projects.analysis-jobs.show', [$project, $analysisJob]));
        $this->assertSame('ad_performance', $analysisJob->template_key);
        $this->assertDatabaseHas('analysis_job_details', [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'prompt' => '',
        ]);
    }

    public function test_store_rejects_an_unknown_template_key(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => '広告分析',
            'template_key' => 'not_a_real_template',
        ])->assertSessionHasErrors('template_key');

        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    public function test_store_rejects_an_empty_prompt(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => 'Analysis',
            'prompt' => '',
        ])->assertSessionHasErrors('prompt');

        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    public function test_store_rejects_a_prompt_over_5000_characters(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();

        $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => 'Analysis',
            'prompt' => str_repeat('a', 5001),
        ])->assertSessionHasErrors('prompt');
    }

    public function test_store_returns_not_found_for_another_projects_data_file(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->create();

        $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => 'Analysis',
            'prompt' => 'Analyze this file.',
        ])->assertNotFound();
    }

    public function test_store_is_rejected_for_an_archived_project(): void
    {
        Queue::fake();
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $dataFile = DataFile::factory()->for($project)->create();

        $this->post(route('projects.data-files.analysis-jobs.store', [$project, $dataFile]), [
            'title' => 'Analysis',
            'prompt' => 'Analyze this file.',
        ])->assertSessionHasErrors('project');

        $this->assertDatabaseCount('analysis_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_pending_and_processing_pages_refresh_and_show_their_statuses(): void
    {
        foreach ([AnalysisJobStatus::Pending, AnalysisJobStatus::Processing] as $status) {
            [$project, $analysisJob] = $this->analysisJob($status);

            $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
                ->assertOk()
                ->assertSee($status->name)
                ->assertSee('<meta http-equiv="refresh" content="5">', false);
        }
    }

    public function test_completed_page_displays_all_six_result_sections_without_raw_response(): void
    {
        $result = [
            'summary' => 'Sales grew.',
            'highlights' => ['Strong growth in Tokyo'],
            'metrics' => [['label' => 'Revenue', 'value' => '120', 'unit' => 'JPY', 'change' => '+20%']],
            'tables' => [['title' => 'Regions', 'columns' => ['Region', 'Sales'], 'rows' => [['Tokyo', '120']]]],
            'insights' => [['title' => 'Growth', 'description' => 'Tokyo led growth.', 'evidence' => 'Revenue data']],
            'recommendations' => [['title' => 'Invest', 'description' => 'Increase Tokyo budget.', 'priority' => 'high']],
        ];
        [$project, $analysisJob] = $this->analysisJob(AnalysisJobStatus::Completed, [
            'result' => $result,
            'raw_response' => 'secret raw response',
            'completed_at' => now(),
        ]);

        $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertOk()
            ->assertSeeInOrder(['Summary', 'Highlights', 'Metrics', 'Tables', 'Insights', 'Recommendations'])
            ->assertSee('Sales grew.')
            ->assertSee('Tokyo led growth.')
            ->assertDontSee('secret raw response')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    /**
     * Phase 4-B: an empty "recommendations" array (the expected shape for
     * a new Decision-enabled result) hides the Recommendations section
     * entirely — see docs/product/DIAGNOSIS_ENGINE.md "Legacy
     * Recommendation UI".
     */
    public function test_completed_page_hides_the_recommendations_section_when_it_is_empty(): void
    {
        $result = [
            'summary' => 'Sales grew.',
            'highlights' => [],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
            'recommendations' => [],
        ];
        [$project, $analysisJob] = $this->analysisJob(AnalysisJobStatus::Completed, [
            'result' => $result,
            'completed_at' => now(),
        ]);

        $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertOk()
            ->assertDontSee('Recommendations');
    }

    /**
     * Phase 4-B: the "原因の仮説" section shows one entry per
     * Diagnosis-eligible EvaluationFact. An eligible fact with a
     * DiagnosisResult shows its category label / rationale / missing
     * evidence; an eligible fact without one (a per-entity soft-fail)
     * shows "診断結果を取得できませんでした"; a non-eligible fact (favorable
     * here) shows neither. self_reported_confidence is never rendered.
     * See docs/product/DIAGNOSIS_ENGINE.md "Minimal UI".
     */
    public function test_completed_page_displays_the_diagnosis_section(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create(['original_name' => 'ads.csv']);
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create([
            'status' => AnalysisJobStatus::Completed,
            'template_key' => 'ad_performance',
        ]);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'result' => ['summary' => 's', 'highlights' => [], 'metrics' => [], 'tables' => [], 'insights' => [], 'recommendations' => []],
            'completed_at' => now(),
        ]);

        $diagnosed = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Social', 'evaluation_level' => 'high', 'direction' => 'below',
        ]);
        DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $diagnosed->evaluation_fact_id,
            'category_key' => 'insufficient_explanatory_evidence',
            'self_reported_confidence' => 0.42,
            'rationale_summary' => '証拠からは特定の原因を判断できません。',
            'missing_evidence_json' => ['landing-page-level conversion rate'],
        ]);

        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Display', 'evaluation_level' => 'medium', 'direction' => 'below',
        ]);

        EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'Email', 'evaluation_level' => 'high', 'direction' => 'above',
        ]);

        $response = $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertOk()
            ->assertSee('原因の仮説')
            ->assertSee('十分な根拠がありません')
            ->assertSee('証拠からは特定の原因を判断できません。')
            ->assertSee('landing-page-level conversion rate')
            ->assertSee('診断結果を取得できませんでした');

        $response->assertDontSee('0.42');
    }

    /**
     * measurement_consistency_risk's Japanese label must read as an
     * unconfirmed verification candidate, never as a confirmed finding —
     * see docs/product/DIAGNOSIS_ENGINE.md "measurement_consistency_risk
     * semantics".
     */
    public function test_completed_page_displays_the_measurement_consistency_risk_label_without_overclaiming(): void
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create(['original_name' => 'ads.csv']);
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create([
            'status' => AnalysisJobStatus::Completed,
            'template_key' => 'ad_performance',
        ]);
        AnalysisJobDetail::factory()->for($analysisJob)->create([
            'result' => ['summary' => 's', 'highlights' => [], 'metrics' => [], 'tables' => [], 'insights' => [], 'recommendations' => []],
            'completed_at' => now(),
        ]);

        $fact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create([
            'entity_key' => 'X', 'evaluation_level' => 'high', 'direction' => 'below',
            'numerator_value' => 0, 'denominator_value' => 1000,
        ]);
        DiagnosisResult::factory()->create([
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'category_key' => 'measurement_consistency_risk',
            'rationale_summary' => '計測整合性を確認する価値がある一方、本当にコンバージョンが0件だった可能性も同程度に残ります。',
        ]);

        $response = $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertOk()
            ->assertSee('計測整合性の確認候補');

        $response->assertDontSee('計測異常');
        $response->assertDontSee('トラッキング異常');
        $response->assertDontSee('計測問題');
        $response->assertDontSee('tracking failure');
    }

    public function test_failed_page_displays_the_error_without_refreshing(): void
    {
        [$project, $analysisJob] = $this->analysisJob(AnalysisJobStatus::Failed, [
            'error_message' => 'Provider unavailable.',
            'completed_at' => now(),
        ]);

        $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertOk()
            ->assertSee('Provider unavailable.')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_show_returns_not_found_for_another_projects_analysis_job(): void
    {
        [, $analysisJob] = $this->analysisJob(AnalysisJobStatus::Pending);
        $anotherProject = Project::factory()->create();

        $this->get(route('projects.analysis-jobs.show', [$anotherProject, $analysisJob]))
            ->assertNotFound();
    }

    public function test_show_returns_not_found_when_the_data_file_was_soft_deleted(): void
    {
        [$project, $analysisJob] = $this->analysisJob(AnalysisJobStatus::Pending);
        $analysisJob->dataFile->delete();

        $this->get(route('projects.analysis-jobs.show', [$project, $analysisJob]))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $detailAttributes
     * @return array{Project, AnalysisJob}
     */
    private function analysisJob(AnalysisJobStatus $status, array $detailAttributes = []): array
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create(['original_name' => 'sales.csv']);
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['status' => $status]);
        AnalysisJobDetail::factory()->for($analysisJob)->create(array_merge([
            'prompt' => 'Analyze sales.',
            'started_at' => $status === AnalysisJobStatus::Pending ? null : now(),
        ], $detailAttributes));

        return [$project, $analysisJob];
    }
}
