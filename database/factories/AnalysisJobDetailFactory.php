<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisJobDetail>
 */
class AnalysisJobDetailFactory extends Factory
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
            'prompt' => 'CSVに含まれるデータを分析し、主要な傾向と特徴を要約してください。',
            'raw_response' => null,
            'result' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
