<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationFact>
 */
class EvaluationFactFactory extends Factory
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
            'entity_type' => 'channel',
            'entity_key' => 'Social',
            'metric_key' => 'conversion_rate',
            'metric_type' => 'rate',
            'metric_value' => 0.04,
            'display_baseline_value' => 0.054,
            'test_baseline_value' => 0.061,
            'numerator_value' => 200,
            'denominator_value' => 5000,
            'control_numerator_value' => 610,
            'control_denominator_value' => 10000,
            'delta_absolute' => -0.014,
            'delta_percent' => -0.259259,
            'z_score' => -5.36,
            'direction' => 'below',
            'evaluation_level' => 'high',
            'rule_version' => 'evaluation_rule_v1.0',
            'computed_at' => now(),
        ];
    }
}
