<?php

declare(strict_types=1);

namespace Tests\Feature\ActionProposal;

use App\Enums\AnalysisJobStatus;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ControlledActionUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_advisory_proposal_without_execution_controls_or_raw_score(): void
    {
        [$project, $job, $fact, $diagnosis, $priority] = $this->chain();
        $priority->update(['priority_score' => 0.173829]);
        ActionProposal::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
            'priority_result_id' => $priority->priority_result_id,
            'title' => 'Collect explanatory evidence',
            'catalog_key' => 'collect_explanatory_evidence',
            'selected_checks_json' => [],
            'missing_evidence_json' => ['landing page conversion rate'],
            'evidence_refs_json' => ['evaluation_fact:'.$fact->evaluation_fact_id],
        ]);

        $response = $this->get(route('projects.analysis-jobs.show', [$project, $job]));
        $section = $this->controlledActionsSection($response);

        $response->assertOk();
        $this->assertStringContainsString('Advisory only — not executed', $section);
        $this->assertStringContainsString('Collect explanatory evidence', $section);
        $this->assertStringContainsString('確認優先度', $section);
        $this->assertStringNotContainsString('0.173829', $section);
        $this->assertStringNotContainsString('Approve', $section);
        $this->assertStringNotContainsString('Reject', $section);
        $this->assertStringNotContainsString('Edit', $section);
        $this->assertStringNotContainsString('Execute', $section);
        $this->assertStringNotContainsString('Retry', $section);
        $this->assertDoesNotMatchRegularExpression('/<(?:form|button|a)\b/i', $section);
    }

    public function test_no_proposal_explains_when_feature_is_not_applicable(): void
    {
        [$project, $job] = $this->completedJob(null);
        $section = $this->controlledActionsSection(
            $this->get(route('projects.analysis-jobs.show', [$project, $job]))->assertOk(),
        );

        $this->assertStringContainsString('No controlled action proposal was generated.', $section);
        $this->assertStringContainsString('Controlled Actions are not applicable to this analysis.', $section);
        $this->assertNonConclusiveAbsenceWording($section);
    }

    public function test_no_proposal_explains_when_feature_is_applicable_but_no_candidate_is_eligible(): void
    {
        [$project, $job] = $this->completedJob('ad_performance');
        $section = $this->controlledActionsSection(
            $this->get(route('projects.analysis-jobs.show', [$project, $job]))->assertOk(),
        );

        $this->assertStringContainsString('No controlled action proposal was generated.', $section);
        $this->assertStringContainsString('No evidence currently meets the controlled eligibility contract.', $section);
        $this->assertNonConclusiveAbsenceWording($section);
    }

    public function test_no_proposal_explains_best_effort_unavailability_when_an_eligible_candidate_exists(): void
    {
        [$project, $job] = $this->chain();
        $section = $this->controlledActionsSection(
            $this->get(route('projects.analysis-jobs.show', [$project, $job]))->assertOk(),
        );

        $this->assertStringContainsString('No controlled action proposal was generated.', $section);
        $this->assertStringContainsString('Eligible evidence existed, but proposals are best-effort output and may be unavailable.', $section);
        $this->assertNonConclusiveAbsenceWording($section);
    }

    private function chain(): array
    {
        [$project, $job] = $this->completedJob('ad_performance');
        $fact = EvaluationFact::factory()->for($job)->create();
        $diagnosis = DiagnosisResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'evidence_refs_json' => ['trigger:evaluation_fact:'.$fact->evaluation_fact_id],
            'missing_evidence_json' => ['landing page conversion rate'],
        ]);
        $priority = PriorityResult::factory()->create([
            'analysis_job_id' => $job->analysis_job_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
        ]);

        return [$project, $job, $fact, $diagnosis, $priority];
    }

    /** @return array{Project, AnalysisJob} */
    private function completedJob(?string $templateKey): array
    {
        $project = Project::factory()->create();
        $dataFile = DataFile::factory()->for($project)->create();
        $job = AnalysisJob::factory()->for($dataFile)->create([
            'template_key' => $templateKey,
            'status' => AnalysisJobStatus::Completed,
        ]);
        AnalysisJobDetail::factory()->for($job)->create([
            'result' => [
                'summary' => 'Summary',
                'highlights' => [],
                'metrics' => [],
                'tables' => [],
                'insights' => [],
                'recommendations' => [],
            ],
        ]);

        return [$project, $job];
    }

    private function controlledActionsSection(TestResponse $response): string
    {
        $content = $response->getContent();
        $this->assertIsString($content);
        $matched = preg_match(
            '/<section class="card">\s*<h2>Controlled Actions<\/h2>(.*?)<\/section>/s',
            $content,
            $matches,
        );
        $this->assertSame(1, $matched, 'Controlled Actions section was not found in the response.');

        return $matches[1];
    }

    private function assertNonConclusiveAbsenceWording(string $section): void
    {
        $this->assertStringNotContainsString('No action is needed', $section);
        $this->assertStringNotContainsString('technical failure', strtolower($section));
    }
}
