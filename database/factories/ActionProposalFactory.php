<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActionProposal> */
class ActionProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'analysis_job_id' => AnalysisJob::factory(),
            'evaluation_fact_id' => EvaluationFact::factory(),
            'diagnosis_result_id' => DiagnosisResult::factory(),
            'priority_result_id' => PriorityResult::factory(),
            'catalog_key' => 'verify_measurement_consistency',
            'title' => 'Verify measurement consistency',
            'rationale_summary' => 'The evidence makes measurement consistency worth checking.',
            'selected_checks_json' => ['verify_tag_firing'],
            'evidence_refs_json' => ['evaluation_fact:1'],
            'missing_evidence_json' => [],
            'raw_response' => null,
            'model' => 'gpt-5.4-mini',
            'prompt_version' => 'action_prompt_v1',
            'contract_version' => 'action_contract_v1',
            'proposed_at' => now(),
        ];
    }
}
