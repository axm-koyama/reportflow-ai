<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\ActionProposal;

use App\Actions\ActionProposal\BuildActionEvidencePackageAction;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Tests\TestCase;

class BuildActionEvidencePackageActionTest extends TestCase
{
    public function test_it_copies_only_the_action_contract_fields_and_builds_stable_references(): void
    {
        $job = new AnalysisJob(['template_key' => 'ad_performance']);
        $job->analysis_job_id = 123;
        $fact = new EvaluationFact([
            'analysis_job_id' => 123,
            'metric_key' => 'conversion_rate',
            'entity_key' => 'Social',
            'evaluation_level' => 'high',
            'direction' => 'below',
        ]);
        $fact->evaluation_fact_id = 10;
        $diagnosis = new DiagnosisResult([
            'analysis_job_id' => 123,
            'evaluation_fact_id' => 10,
            'category_key' => 'measurement_consistency_risk',
            'rationale_summary' => 'Worth checking.',
            'evidence_refs_json' => ['trigger:evaluation_fact:10'],
            'missing_evidence_json' => [],
        ]);
        $diagnosis->diagnosis_result_id = 20;
        $priority = new PriorityResult([
            'analysis_job_id' => 123,
            'evaluation_fact_id' => 10,
            'priority_band' => 'medium',
            'priority_score' => 0.175,
            'formula_version' => 'priority_v1.1',
        ]);
        $priority->priority_result_id = 30;
        $fact->setRelation('diagnosisResult', $diagnosis);
        $fact->setRelation('priorityResult', $priority);

        $package = (new BuildActionEvidencePackageAction)->execute($job, $fact, 'verify_measurement_consistency');

        $this->assertSame([
            'analysis_job_id', 'action_catalog_key', 'trigger_fact', 'diagnosis', 'priority',
            'allowed_checks', 'allowed_evidence_refs', 'contract_version',
        ], array_keys($package));
        $this->assertSame(['evaluation_fact:10', 'diagnosis_result:20', 'priority_result:30'], $package['allowed_evidence_refs']);
        $this->assertSame('action_contract_v1', $package['contract_version']);
        $this->assertArrayNotHasKey('prompt', $package);
        $this->assertArrayNotHasKey('raw_response', $package['diagnosis']);
    }
}
