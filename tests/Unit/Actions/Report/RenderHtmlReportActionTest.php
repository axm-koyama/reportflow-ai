<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Report;

use App\Actions\Report\RenderHtmlReportAction;
use Tests\TestCase;

class RenderHtmlReportActionTest extends TestCase
{
    public function test_it_renders_expected_sections_escapes_strings_and_is_deterministic(): void
    {
        $snapshot = $this->snapshot();
        $action = app(RenderHtmlReportAction::class);
        $first = $action->execute($snapshot);
        $second = $action->execute($snapshot);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('日本語の要約', $first);
        $this->assertStringContainsString('&lt;script&gt;alert', $first);
        $this->assertStringNotContainsString('<script>alert', $first);
        $this->assertStringContainsString('Evaluation', $first);
        $this->assertStringContainsString('原因の仮説', $first);
        $this->assertStringContainsString('確認優先度', $first);
        $this->assertStringContainsString('Controlled Actions', $first);
        $this->assertStringContainsString('2026-09-08 00:00:00', $first);
        $this->assertStringNotContainsString('2026-09-08T00:00:00+00:00', $first);
        $this->assertStringNotContainsString('<h1', $first);
        $this->assertStringContainsString('badge-advisory', $first);
        $this->assertStringContainsString('>Advisory only — not executed</span>', $first);
        $this->assertStringNotContainsString('ⓘ', $first);
        $this->assertSame('report_renderer_v1.1', RenderHtmlReportAction::RENDERER_VERSION);
        $this->assertSame(hash('sha256', $first), hash('sha256', $second));
    }

    public function test_it_contains_no_operational_controls_or_raw_fields(): void
    {
        $html = app(RenderHtmlReportAction::class)->execute($this->snapshot());

        $this->assertStringContainsString('Diagnosis rationale', $html);
        $this->assertStringContainsString('High', $html);
        $this->assertStringContainsString('Check measurement', $html);

        foreach (['<form', '<button', '<a ', 'Approve', 'Reject', 'Edit', 'Execute', 'Retry', 'PDF', 'raw response', 'raw_response', 'prompt', 'priority_score', 'self_reported_confidence'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'source' => ['title' => '<script>alert(1)</script>', 'analysis_job_id' => 1, 'display_mode' => 'Free Analysis', 'data_file_name' => '日本語.csv', 'completed_at' => null, 'recovered_from_analysis_job_id' => null],
            'generated_at' => '2026-09-08T00:00:00+00:00',
            'analysis' => ['summary' => '日本語の要約', 'highlights' => [], 'metrics' => [], 'tables' => [['title' => 'Long', 'columns' => ['A'], 'rows' => [[str_repeat('長', 500)]]]], 'insights' => [], 'recommendations' => []],
            'evaluation' => ['applicable' => true, 'rows' => [[
                'entity_key' => 'Email', 'metric_label' => 'Conversion rate', 'metric_value' => 0.02, 'display_baseline_value' => 0.04, 'delta_absolute' => -0.02, 'direction' => 'below', 'evaluation_level' => 'high',
            ]]],
            'diagnosis' => ['rows' => [[
                'entity_key' => 'Email', 'status' => 'available', 'category_label' => 'Measurement Consistency Risk', 'rationale' => 'Diagnosis rationale', 'evidence_refs' => ['evaluation_fact:1'], 'missing_evidence' => [],
            ]]],
            'priority' => ['rows' => [[
                'entity_key' => 'Email', 'status' => 'available', 'priority_band' => 'high', 'impact_score' => 0.2, 'gap_raw_value' => 0.02,
            ]]],
            'controlled_actions' => ['applicable' => true, 'eligible_count' => 1, 'proposals' => [[
                'title' => 'Check measurement', 'advisory_label' => 'Advisory only — not executed', 'catalog_label' => 'Verify measurement consistency', 'entity_key' => 'Email', 'metric_label' => 'Conversion rate', 'priority_band' => 'high', 'rationale' => 'Verify definitions.', 'evidence_refs' => ['evaluation_fact:1'], 'selected_checks' => ['verify_tag_firing'], 'missing_evidence' => [],
            ]]],
        ];
    }
}
