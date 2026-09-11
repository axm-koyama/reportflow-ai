<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiagnosisResult>
 */
class DiagnosisResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_job_id' => AnalysisJob::factory(),
            'evaluation_fact_id' => EvaluationFact::factory(),
            'category_key' => 'insufficient_explanatory_evidence',
            'self_reported_confidence' => 0.4,
            'rationale_summary' => 'The supplied evidence does not distinguish between possible causes for this observation.',
            'evidence_refs_json' => [],
            'missing_evidence_json' => ['landing-page-level conversion rate'],
            'supporting_facts_json' => [],
            'raw_response' => null,
            'model' => 'gpt-4.1',
            'prompt_version' => 'diagnosis_prompt_v1.0',
        ];
    }
}
