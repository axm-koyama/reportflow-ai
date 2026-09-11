<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\DataFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisJob>
 */
class AnalysisJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_file_id' => DataFile::factory(),
            'title' => fake()->randomElement([
                '売上傾向分析',
                '地域別売上比較',
                '解約リスク顧客抽出',
                '顧客分類分析',
            ]),
            'status' => AnalysisJobStatus::Pending,
            'recovered_from_analysis_job_id' => null,
        ];
    }
}
