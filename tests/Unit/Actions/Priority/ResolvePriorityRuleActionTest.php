<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Priority;

use App\Actions\Priority\ResolvePriorityRuleAction;
use Tests\TestCase;

/**
 * Direct coverage for ResolvePriorityRuleAction (Phase 4-C v1). See
 * docs/product/PRIORITY_ENGINE.md "Priority Config".
 *
 * Zero AI I/O, zero DB I/O — pure config lookup.
 */
class ResolvePriorityRuleActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'priority_rules.ad_performance.metrics.conversion_rate' => [
                'impact_basis' => 'denominator_share',
                'gap_reference_multiple' => 4,
                'band_thresholds' => ['high' => 0.35, 'medium' => 0.10],
                'formula_version' => 'priority_v1.1',
            ],
            'evaluation_metrics.ad_performance.metrics' => [
                [
                    'metric_key' => 'conversion_rate',
                    'practical_significance_floor' => 0.005,
                ],
            ],
        ]);
    }

    private function action(): ResolvePriorityRuleAction
    {
        return new ResolvePriorityRuleAction;
    }

    public function test_resolves_a_configured_metric(): void
    {
        $rule = $this->action()->execute('ad_performance', 'conversion_rate');

        $this->assertNotNull($rule);
        $this->assertSame('denominator_share', $rule['impact_basis']);
        $this->assertEqualsWithDelta(4.0, $rule['gap_reference_multiple'], 1e-9);
        $this->assertEqualsWithDelta(0.35, $rule['band_thresholds']['high'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $rule['band_thresholds']['medium'], 1e-9);
        $this->assertSame('priority_v1.1', $rule['formula_version']);
        $this->assertEqualsWithDelta(0.005, $rule['practical_significance_floor'], 1e-9);
    }

    public function test_unknown_template_resolves_to_null(): void
    {
        $this->assertNull($this->action()->execute('unknown_template', 'conversion_rate'));
    }

    public function test_unknown_metric_resolves_to_null(): void
    {
        $this->assertNull($this->action()->execute('ad_performance', 'unknown_metric'));
    }

    public function test_a_metric_with_no_evaluation_metrics_floor_resolves_to_null(): void
    {
        config(['evaluation_metrics.ad_performance.metrics' => []]);

        $this->assertNull($this->action()->execute('ad_performance', 'conversion_rate'));
    }

    public function test_a_zero_gap_reference_multiple_resolves_to_null(): void
    {
        config(['priority_rules.ad_performance.metrics.conversion_rate.gap_reference_multiple' => 0]);

        $this->assertNull($this->action()->execute('ad_performance', 'conversion_rate'));
    }

    public function test_a_high_threshold_not_exceeding_medium_resolves_to_null(): void
    {
        config(['priority_rules.ad_performance.metrics.conversion_rate.band_thresholds' => [
            'high' => 0.10,
            'medium' => 0.10,
        ]]);

        $this->assertNull($this->action()->execute('ad_performance', 'conversion_rate'));
    }

    public function test_a_threshold_outside_zero_to_one_resolves_to_null(): void
    {
        config(['priority_rules.ad_performance.metrics.conversion_rate.band_thresholds' => [
            'high' => 1.5,
            'medium' => 0.10,
        ]]);

        $this->assertNull($this->action()->execute('ad_performance', 'conversion_rate'));
    }

    public function test_a_blank_formula_version_resolves_to_null(): void
    {
        config(['priority_rules.ad_performance.metrics.conversion_rate.formula_version' => '']);

        $this->assertNull($this->action()->execute('ad_performance', 'conversion_rate'));
    }
}
