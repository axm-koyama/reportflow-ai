<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Report;

use App\Actions\Report\BuildReportSnapshotAction;
use App\Enums\AnalysisJobStatus;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildReportSnapshotActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_ordered_display_safe_snapshot_with_available_downstream_data(): void
    {
        $job = $this->completedJob('ad_performance');
        $later = EvaluationFact::factory()->for($job, 'analysisJob')->create(['entity_key' => 'Later']);
        $first = EvaluationFact::factory()->for($job, 'analysisJob')->create(['entity_key' => 'First']);
        $diagnosis = DiagnosisResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $first->evaluation_fact_id,
            'evidence_refs_json' => ['evaluation_fact:'.$first->evaluation_fact_id],
            'raw_response' => 'diagnosis secret',
            'self_reported_confidence' => 0.98765,
        ]);
        $priority = PriorityResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $first->evaluation_fact_id,
            'priority_score' => 0.876543,
        ]);
        $proposal = ActionProposal::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $first->evaluation_fact_id,
            'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
            'priority_result_id' => $priority->priority_result_id,
            'evidence_refs_json' => ['evaluation_fact:'.$first->evaluation_fact_id],
            'raw_response' => 'action secret',
        ]);
        $job->load(['dataFile', 'analysisJobDetail', 'evaluationFacts.diagnosisResult', 'evaluationFacts.priorityResult', 'actionProposals.evaluationFact', 'actionProposals.priorityResult']);

        $snapshot = app(BuildReportSnapshotAction::class)->execute($job);

        $this->assertSame('report_schema_v1.0', $snapshot['schema_version']);
        $this->assertSame('広告パフォーマンス分析', $snapshot['source']['display_mode']);
        $this->assertSame([$later->evaluation_fact_id, $first->evaluation_fact_id], array_column($snapshot['evaluation']['rows'], 'evaluation_fact_id'));
        $this->assertSame(0.04, $snapshot['evaluation']['rows'][0]['metric_value']);
        $this->assertSame('available', collect($snapshot['diagnosis']['rows'])->firstWhere('evaluation_fact_id', $first->evaluation_fact_id)['status']);
        $this->assertSame('available', collect($snapshot['priority']['rows'])->firstWhere('evaluation_fact_id', $first->evaluation_fact_id)['status']);
        $this->assertSame($proposal->action_proposal_id, $snapshot['controlled_actions']['proposals'][0]['action_proposal_id']);
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['secret raw response', 'diagnosis secret', 'action secret', 'private prompt', '0.98765', '0.876543'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->assertArrayNotHasKey('z_score', $snapshot['evaluation']['rows'][0]);
    }

    public function test_modes_recommendations_and_downstream_absence_states_are_preserved(): void
    {
        $free = $this->completedJob(null, recommendations: [['title' => 'Legacy', 'description' => 'Keep', 'priority' => 'low']]);
        $free->load(['dataFile', 'analysisJobDetail', 'evaluationFacts', 'actionProposals']);
        $freeSnapshot = app(BuildReportSnapshotAction::class)->execute($free);
        $this->assertSame('Free Analysis', $freeSnapshot['source']['display_mode']);
        $this->assertSame('Legacy', $freeSnapshot['analysis']['recommendations'][0]['title']);
        $this->assertFalse($freeSnapshot['controlled_actions']['applicable']);

        $unknown = $this->completedJob('unknown_key');
        $unknown->load(['dataFile', 'analysisJobDetail', 'evaluationFacts', 'actionProposals']);
        $this->assertSame('Unknown template (unknown_key)', app(BuildReportSnapshotAction::class)->execute($unknown)['source']['display_mode']);

        $decision = $this->completedJob('ad_performance', recommendations: []);
        $ineligible = EvaluationFact::factory()->for($decision, 'analysisJob')->create(['evaluation_level' => 'low']);
        $eligible = EvaluationFact::factory()->for($decision, 'analysisJob')->create(['entity_key' => 'Eligible']);
        $decision->load(['dataFile', 'analysisJobDetail', 'evaluationFacts.diagnosisResult', 'evaluationFacts.priorityResult', 'actionProposals']);
        $snapshot = app(BuildReportSnapshotAction::class)->execute($decision);
        $this->assertSame([], $snapshot['analysis']['recommendations']);
        $this->assertSame([['evaluation_fact_id' => $eligible->evaluation_fact_id, 'entity_key' => $eligible->entity_key, 'status' => 'unavailable']], $snapshot['diagnosis']['rows']);
        $this->assertSame('not_eligible', collect($snapshot['priority']['rows'])->firstWhere('evaluation_fact_id', $ineligible->evaluation_fact_id)['status']);
        $this->assertSame('unavailable', collect($snapshot['priority']['rows'])->firstWhere('evaluation_fact_id', $eligible->evaluation_fact_id)['status']);
        $this->assertTrue($snapshot['controlled_actions']['applicable']);
        $this->assertSame(0, $snapshot['controlled_actions']['eligible_count']);
    }

    public function test_eligible_candidate_without_proposal_is_distinguished(): void
    {
        $job = $this->completedJob('ad_performance');
        $fact = EvaluationFact::factory()->for($job, 'analysisJob')->create();
        DiagnosisResult::factory()->create(['analysis_job_id' => $job->analysis_job_id, 'evaluation_fact_id' => $fact->evaluation_fact_id, 'evidence_refs_json' => ['fact:1']]);
        PriorityResult::factory()->create(['analysis_job_id' => $job->analysis_job_id, 'evaluation_fact_id' => $fact->evaluation_fact_id]);
        $job->load(['dataFile', 'analysisJobDetail', 'evaluationFacts.diagnosisResult', 'evaluationFacts.priorityResult', 'actionProposals']);

        $snapshot = app(BuildReportSnapshotAction::class)->execute($job);

        $this->assertSame(1, $snapshot['controlled_actions']['eligible_count']);
        $this->assertSame([], $snapshot['controlled_actions']['proposals']);
    }

    private function completedJob(?string $templateKey = null, array $recommendations = []): AnalysisJob
    {
        $job = AnalysisJob::factory()->create(['status' => AnalysisJobStatus::Completed, 'template_key' => $templateKey, 'title' => 'Snapshot title']);
        AnalysisJobDetail::factory()->for($job)->create([
            'prompt' => 'private prompt',
            'raw_response' => 'secret raw response',
            'completed_at' => now(),
            'result' => [
                'summary' => 'Stable summary', 'highlights' => [], 'metrics' => [], 'tables' => [], 'insights' => [], 'recommendations' => $recommendations,
            ],
        ]);

        return $job;
    }
}
