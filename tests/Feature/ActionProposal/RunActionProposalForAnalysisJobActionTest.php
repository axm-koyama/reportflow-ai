<?php

declare(strict_types=1);

namespace Tests\Feature\ActionProposal;

use App\Actions\ActionProposal\RunActionProposalForAnalysisJobAction;
use App\AI\AiAnalysisClient;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RunActionProposalForAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_fact_creates_one_linked_versioned_proposal(): void
    {
        [$job, $fact, $diagnosis, $priority] = $this->chain();
        $client = $this->mock(AiAnalysisClient::class);
        $client->shouldReceive('proposeAction')->once()->andReturn($this->response($fact, $diagnosis, $priority));

        $this->assertSame(1, app(RunActionProposalForAnalysisJobAction::class)->execute($job));

        $proposal = ActionProposal::query()->sole();
        $this->assertSame($fact->evaluation_fact_id, $proposal->evaluation_fact_id);
        $this->assertSame($diagnosis->diagnosis_result_id, $proposal->diagnosis_result_id);
        $this->assertSame($priority->priority_result_id, $proposal->priority_result_id);
        $this->assertSame('action_prompt_v1', $proposal->prompt_version);
        $this->assertSame('action_contract_v1', $proposal->contract_version);
    }

    public function test_ineligible_fact_makes_no_ai_call(): void
    {
        [$job, $fact] = $this->chain();
        $fact->diagnosisResult->update(['missing_evidence_json' => []]);
        $this->mock(AiAnalysisClient::class)->shouldNotReceive('proposeAction');

        $this->assertSame(0, app(RunActionProposalForAnalysisJobAction::class)->execute($job));
        $this->assertDatabaseCount('action_proposals', 0);
    }

    public function test_one_candidate_failure_does_not_prevent_another_proposal(): void
    {
        [$job] = $this->chain(entity: 'Social');
        [, $second, $secondDiagnosis, $secondPriority] = $this->chain($job, 'Display');
        $client = $this->mock(AiAnalysisClient::class);
        $client->shouldReceive('proposeAction')->twice()->andReturnUsing(function (array $context): string {
            $package = $context['evidence_package'];

            if ($package['trigger_fact']['entity_key'] === 'Social') {
                throw new RuntimeException('provider failed');
            }

            return json_encode([
                'catalog_key' => 'collect_explanatory_evidence',
                'title' => 'Collect missing evidence',
                'rationale_summary' => 'More evidence is needed before optimization.',
                'selected_checks' => [],
                'evidence_refs' => $package['allowed_evidence_refs'],
                'missing_evidence' => ['landing page conversion rate'],
            ], JSON_THROW_ON_ERROR);
        });

        $this->assertSame(1, app(RunActionProposalForAnalysisJobAction::class)->execute($job));
        $this->assertSame($second->evaluation_fact_id, ActionProposal::query()->sole()->evaluation_fact_id);
    }

    public function test_rerun_deletes_stale_rows_and_does_not_duplicate(): void
    {
        [$job, $fact, $diagnosis, $priority] = $this->chain();
        ActionProposal::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
            'priority_result_id' => $priority->priority_result_id,
            'title' => 'Stale title',
        ]);
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('proposeAction')
            ->twice()
            ->andReturn($this->response($fact, $diagnosis, $priority));

        $action = app(RunActionProposalForAnalysisJobAction::class);
        $action->execute($job);
        $action->execute($job);

        $this->assertDatabaseCount('action_proposals', 1);
        $this->assertSame('Collect missing evidence', ActionProposal::query()->sole()->title);
    }

    private function chain(?AnalysisJob $job = null, string $entity = 'Social'): array
    {
        $job ??= AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        $fact = EvaluationFact::factory()->for($job)->create(['entity_key' => $entity]);
        $diagnosis = DiagnosisResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'category_key' => 'insufficient_explanatory_evidence',
            'evidence_refs_json' => ['trigger:evaluation_fact:'.$fact->evaluation_fact_id],
            'missing_evidence_json' => ['landing page conversion rate'],
        ]);
        $priority = PriorityResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        return [$job, $fact, $diagnosis, $priority];
    }

    private function response(EvaluationFact $fact, DiagnosisResult $diagnosis, PriorityResult $priority): string
    {
        return json_encode([
            'catalog_key' => 'collect_explanatory_evidence',
            'title' => 'Collect missing evidence',
            'rationale_summary' => 'More evidence is needed before optimization.',
            'selected_checks' => [],
            'evidence_refs' => [
                'evaluation_fact:'.$fact->evaluation_fact_id,
                'diagnosis_result:'.$diagnosis->diagnosis_result_id,
                'priority_result:'.$priority->priority_result_id,
            ],
            'missing_evidence' => ['landing page conversion rate'],
        ], JSON_THROW_ON_ERROR);
    }
}
