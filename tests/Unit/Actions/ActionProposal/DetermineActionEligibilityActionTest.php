<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\ActionProposal;

use App\Actions\ActionProposal\DetermineActionEligibilityAction;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Tests\TestCase;

class DetermineActionEligibilityActionTest extends TestCase
{
    private function candidate(string $category = 'measurement_consistency_risk'): array
    {
        $job = new AnalysisJob(['template_key' => 'ad_performance']);
        $job->analysis_job_id = 1;
        $fact = new EvaluationFact(['analysis_job_id' => 1]);
        $fact->evaluation_fact_id = 10;
        $diagnosis = new DiagnosisResult([
            'analysis_job_id' => 1,
            'evaluation_fact_id' => 10,
            'category_key' => $category,
            'rationale_summary' => 'Evidence-grounded rationale.',
            'evidence_refs_json' => ['trigger:evaluation_fact:10'],
            'missing_evidence_json' => ['landing page conversion rate'],
        ]);
        $diagnosis->diagnosis_result_id = 20;
        $priority = new PriorityResult(['analysis_job_id' => 1, 'evaluation_fact_id' => 10]);
        $priority->priority_result_id = 30;
        $fact->setRelation('diagnosisResult', $diagnosis);
        $fact->setRelation('priorityResult', $priority);

        return [$job, $fact, $diagnosis, $priority];
    }

    public function test_each_supported_diagnosis_maps_to_exactly_one_catalog_entry(): void
    {
        [$job, $measurement] = $this->candidate();
        $this->assertSame(['eligible' => true, 'catalog_key' => 'verify_measurement_consistency'], (new DetermineActionEligibilityAction)->execute($measurement, $job));

        [$job, $collection] = $this->candidate('insufficient_explanatory_evidence');
        $this->assertSame(['eligible' => true, 'catalog_key' => 'collect_explanatory_evidence'], (new DetermineActionEligibilityAction)->execute($collection, $job));
    }

    public function test_it_rejects_each_ineligible_condition(): void
    {
        $action = new DetermineActionEligibilityAction;
        [$job, $fact] = $this->candidate();
        $fact->setRelation('priorityResult', null);
        $this->assertSame('missing_priority', $action->execute($fact, $job)['reason']);

        [$job, $fact] = $this->candidate();
        $fact->setRelation('diagnosisResult', null);
        $this->assertSame('missing_diagnosis', $action->execute($fact, $job)['reason']);

        [$job, $fact] = $this->candidate('unsupported');
        $this->assertSame('unsupported_diagnosis', $action->execute($fact, $job)['reason']);

        [$job, $fact, $diagnosis] = $this->candidate();
        $diagnosis->evidence_refs_json = [];
        $this->assertSame('missing_required_evidence', $action->execute($fact, $job)['reason']);

        [$job, $fact] = $this->candidate('insufficient_explanatory_evidence');
        $fact->diagnosisResult->missing_evidence_json = ['  '];
        $this->assertSame('missing_required_evidence', $action->execute($fact, $job)['reason']);

        [$job, $fact] = $this->candidate();
        $job->template_key = null;
        $this->assertSame('not_decision_enabled', $action->execute($fact, $job)['reason']);

        [$job, $fact] = $this->candidate();
        config(['action_catalog.verify_measurement_consistency.enabled' => false]);
        $this->assertSame('catalog_disabled', $action->execute($fact, $job)['reason']);
    }
}
