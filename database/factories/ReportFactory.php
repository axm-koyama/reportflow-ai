<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalysisJob;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        $html = '<article><h1>Stored report</h1></article>';

        return [
            'analysis_job_id' => AnalysisJob::factory(),
            'title' => 'Stored report',
            'snapshot_json' => ['schema_version' => 'report_schema_v1.0'],
            'rendered_html' => $html,
            'schema_version' => 'report_schema_v1.0',
            'renderer_version' => 'report_renderer_v1.0',
            'content_hash' => hash('sha256', $html),
            'generated_at' => now(),
        ];
    }
}
