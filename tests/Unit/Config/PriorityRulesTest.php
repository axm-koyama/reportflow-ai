<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Validates the shape of config/priority_rules.php (Phase 4-C: mirrors
 * tests/Unit/Config/DiagnosisCategoriesTest.php's own convention — a plain
 * config array, no dedicated Rule class hierarchy, so structural integrity
 * is guarded here rather than by PHP type declarations).
 */
class PriorityRulesTest extends TestCase
{
    public function test_ad_performance_conversion_rate_rule_has_the_expected_shape(): void
    {
        $rule = config('priority_rules.ad_performance.metrics.conversion_rate');

        $this->assertIsArray($rule);
        $this->assertSame('denominator_share', $rule['impact_basis']);
        $this->assertIsNumeric($rule['gap_reference_multiple']);
        $this->assertGreaterThan(0, $rule['gap_reference_multiple']);
        $this->assertIsString($rule['formula_version']);
        $this->assertNotSame('', $rule['formula_version']);
    }

    public function test_band_thresholds_are_within_zero_and_one_and_high_exceeds_medium(): void
    {
        foreach (config('priority_rules', []) as $templateKey => $template) {
            foreach ($template['metrics'] ?? [] as $metricKey => $rule) {
                $high = $rule['band_thresholds']['high'] ?? null;
                $medium = $rule['band_thresholds']['medium'] ?? null;

                $this->assertIsNumeric($high, "{$templateKey}.{$metricKey}: band_thresholds.high must be numeric.");
                $this->assertIsNumeric($medium, "{$templateKey}.{$metricKey}: band_thresholds.medium must be numeric.");
                $this->assertGreaterThanOrEqual(0.0, (float) $high, "{$templateKey}.{$metricKey}: high must be >= 0.");
                $this->assertLessThanOrEqual(1.0, (float) $high, "{$templateKey}.{$metricKey}: high must be <= 1.");
                $this->assertGreaterThanOrEqual(0.0, (float) $medium, "{$templateKey}.{$metricKey}: medium must be >= 0.");
                $this->assertLessThanOrEqual(1.0, (float) $medium, "{$templateKey}.{$metricKey}: medium must be <= 1.");
                $this->assertGreaterThan((float) $medium, (float) $high, "{$templateKey}.{$metricKey}: high must exceed medium.");
            }
        }
    }

    /**
     * Every metric declared in config/priority_rules.php must also have a
     * matching config/evaluation_metrics.php entry — Priority never
     * duplicates practical_significance_floor (see
     * ResolvePriorityRuleAction), so a rule with no matching Evaluation
     * metric would be permanently unusable.
     */
    public function test_every_priority_rule_metric_has_a_matching_evaluation_metrics_entry(): void
    {
        foreach (config('priority_rules', []) as $templateKey => $template) {
            $evaluationMetricKeys = array_column(
                config("evaluation_metrics.{$templateKey}.metrics", []),
                'metric_key',
            );

            foreach (array_keys($template['metrics'] ?? []) as $metricKey) {
                $this->assertContains(
                    $metricKey,
                    $evaluationMetricKeys,
                    "priority_rules.{$templateKey}.metrics.{$metricKey} has no matching evaluation_metrics.{$templateKey} entry.",
                );
            }
        }
    }

    public function test_ad_performance_conversion_rate_uses_the_v1_defaults(): void
    {
        $rule = config('priority_rules.ad_performance.metrics.conversion_rate');

        $this->assertEqualsWithDelta(4.0, $rule['gap_reference_multiple'], 1e-9);
        $this->assertEqualsWithDelta(0.35, $rule['band_thresholds']['high'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $rule['band_thresholds']['medium'], 1e-9);
        $this->assertSame('priority_v1.1', $rule['formula_version']);
    }
}
