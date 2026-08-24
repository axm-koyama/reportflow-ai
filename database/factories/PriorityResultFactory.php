<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriorityResult>
 */
class PriorityResultFactory extends Factory
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
            'impact_basis' => 'denominator_share',
            'impact_value' => 5000,
            'impact_total' => 25000,
            'impact_score' => 0.2,
            'gap_raw_value' => 0.014,
            'gap_reference_value' => 0.02,
            'gap_score' => 0.7,
            'priority_score' => 0.14,
            'priority_band' => 'medium',
            'formula_version' => 'priority_v1.1',
            'computed_at' => now(),
        ];
    }
}
