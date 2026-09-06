<?php

declare(strict_types=1);

namespace Tests\Feature\ActionProposal;

use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_casts_and_resolves_all_relations(): void
    {
        [$job, $fact, $diagnosis, $priority] = $this->chain();

        $proposal = ActionProposal::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
            'priority_result_id' => $priority->priority_result_id,
        ]);

        $this->assertSame(['verify_tag_firing'], $proposal->selected_checks_json);
        $this->assertTrue($proposal->analysisJob->is($job));
        $this->assertTrue($proposal->evaluationFact->is($fact));
        $this->assertTrue($proposal->diagnosisResult->is($diagnosis));
        $this->assertTrue($proposal->priorityResult->is($priority));
        $this->assertTrue($job->actionProposals()->first()->is($proposal));
        $this->assertTrue($fact->actionProposal->is($proposal));
    }

    public function test_evaluation_fact_id_is_unique(): void
    {
        [$job, $fact, $diagnosis, $priority] = $this->chain();
        $attributes = [
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
            'priority_result_id' => $priority->priority_result_id,
        ];
        ActionProposal::factory()->create($attributes);

        $this->expectException(QueryException::class);
        ActionProposal::factory()->create($attributes);
    }

    private function chain(): array
    {
        $job = AnalysisJob::factory()->create(['template_key' => 'ad_performance']);
        $fact = EvaluationFact::factory()->for($job)->create();
        $diagnosis = DiagnosisResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'evidence_refs_json' => ['trigger:evaluation_fact:'.$fact->evaluation_fact_id],
        ]);
        $priority = PriorityResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        return [$job, $fact, $diagnosis, $priority];
    }
}
