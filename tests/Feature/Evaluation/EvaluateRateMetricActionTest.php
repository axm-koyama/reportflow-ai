<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Actions\Evaluation\EvaluateRateMetricAction;
use App\Actions\Evaluation\TwoProportionZTestAction;
use Tests\TestCase;

/**
 * Direct unit coverage for evaluating one resolved rate-metric definition
 * against aggregated_metrics. See docs/product/EVALUATION_ENGINE.md
 * "Display Baseline" / "Test Baseline" / "Practical Significance Floor" /
 * "Count Validity".
 *
 * All fixture numbers with a specific expected z/level are independently
 * computed (Python) — see the Phase 4-A investigation report and
 * TwoProportionZTestActionTest for the shared -5.3643390485032 Social
 * example.
 */
class EvaluateRateMetricActionTest extends TestCase
{
    private function action(): EvaluateRateMetricAction
    {
        return new EvaluateRateMetricAction(new TwoProportionZTestAction);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function definition(array $overrides = []): array
    {
        return array_merge([
            'metric_key' => 'conversion_rate',
            'metric_type' => 'rate',
            'entity_type' => 'channel',
            'entity_field' => 'channel',
            'numerator_field' => 'conversions',
            'denominator_field' => 'clicks',
            'unfavorable_direction' => 'below',
            'practical_significance_floor' => 0.005,
            'normal_approximation_min_expected' => 5,
            'z_threshold_high' => 1.96,
            'z_threshold_medium' => 1.00,
            'rule_version' => 'evaluation_rule_v1.0',
        ], $overrides);
    }

    /**
     * @param array<string, array<string, int|float>> $groups value => {measureName: sum}
     * @param list<string> $measures
     * @return array<string, mixed>
     */
    private function aggregatedMetrics(string $dimension, array $groups, array $measures): array
    {
        $groupEntries = [];

        foreach ($groups as $value => $metrics) {
            $metricEntries = [];

            foreach ($metrics as $measureName => $sum) {
                $metricEntries[$measureName] = ['sum' => $sum, 'count' => 1, 'avg' => $sum];
            }

            $groupEntries[] = ['value' => (string) $value, 'count' => 1, 'metrics' => $metricEntries];
        }

        return [
            'dimensions' => [
                ['dimension' => $dimension, 'group_count' => count($groupEntries), 'groups' => $groupEntries],
            ],
            'measures' => $measures,
        ];
    }

    private function factFor(array $facts, string $entityKey): array
    {
        foreach ($facts as $fact) {
            if ($fact['entity_key'] === $entityKey) {
                return $fact;
            }
        }

        $this->fail("No fact found for entity_key \"{$entityKey}\".");
    }

    /** Email 243/3000, Social 200/5000, Paid 220/4000, Organic 147/3000. */
    private function fourChannelAggregatedMetrics(): array
    {
        return $this->aggregatedMetrics('channel', [
            'Email' => ['conversions' => 243, 'clicks' => 3000],
            'Social' => ['conversions' => 200, 'clicks' => 5000],
            'Paid' => ['conversions' => 220, 'clicks' => 4000],
            'Organic' => ['conversions' => 147, 'clicks' => 3000],
        ], ['conversions', 'clicks']);
    }

    // --- Baselines ----------------------------------------------------

    public function test_weighted_display_baseline_is_sum_over_sum_not_a_mean(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        // (243+200+220+147) / (3000+5000+4000+3000) = 810/15000 = 0.054
        foreach ($facts as $fact) {
            $this->assertEqualsWithDelta(0.054, $fact['display_baseline_value'], 1e-12);
        }

        // mean(rate) would be (0.081+0.04+0.055+0.049)/4 = 0.05625 — must
        // NOT be what display_baseline_value equals (Simpson's paradox
        // guard, see docs/product/EVALUATION_ENGINE.md "Display Baseline").
        $mean = (0.081 + 0.04 + 0.055 + 0.049) / 4;
        $this->assertGreaterThan(1e-6, abs($mean - $facts[0]['display_baseline_value']));
    }

    public function test_leave_one_out_test_baseline_excludes_the_entity_itself(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $social = $this->factFor($facts, 'Social');

        // peers = Email+Paid+Organic = (243+220+147)/(3000+4000+3000) = 610/10000 = 0.061
        $this->assertEqualsWithDelta(0.061, $social['test_baseline_value'], 1e-12);
        $this->assertGreaterThan(1e-9, abs($social['display_baseline_value'] - $social['test_baseline_value']));
    }

    public function test_delta_absolute_and_delta_percent(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $social = $this->factFor($facts, 'Social');

        $this->assertEqualsWithDelta(0.04, $social['metric_value'], 1e-12);
        $this->assertEqualsWithDelta(-0.014, $social['delta_absolute'], 1e-9);
        $this->assertEqualsWithDelta(-0.25925925925925924, $social['delta_percent'], 1e-9);
    }

    public function test_delta_percent_is_null_when_display_baseline_is_zero(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 0, 'clicks' => 1000],
            'B' => ['conversions' => 0, 'clicks' => 2000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        foreach ($facts as $fact) {
            $this->assertSame(0.0, $fact['display_baseline_value']);
            $this->assertSame(0.0, $fact['delta_absolute']);
            $this->assertNull($fact['delta_percent']);
        }
    }

    // --- Direction ------------------------------------------------------

    public function test_direction_above_and_below(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $this->assertSame('above', $this->factFor($facts, 'Email')['direction']);
        $this->assertSame('below', $this->factFor($facts, 'Social')['direction']);
    }

    public function test_direction_equal_when_metric_value_matches_the_baseline_exactly(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 100, 'clicks' => 2000],
            'B' => ['conversions' => 100, 'clicks' => 2000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        foreach ($facts as $fact) {
            $this->assertSame('equal', $fact['direction']);
            $this->assertSame(0.0, $fact['delta_absolute']);
        }
    }

    // --- Evaluation level -------------------------------------------------

    public function test_high_level(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $social = $this->factFor($facts, 'Social');
        $this->assertEqualsWithDelta(-5.3643390485032, $social['z_score'], 1e-9);
        $this->assertSame('high', $social['evaluation_level']);
    }

    public function test_medium_level_without_a_floor_downgrade(): void
    {
        // A=52/800 (0.065), B=36/800 (0.045): z≈1.7545 (medium), delta_abs=0.01 (>= floor 0.005).
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 52, 'clicks' => 800],
            'B' => ['conversions' => 36, 'clicks' => 800],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $a = $this->factFor($facts, 'A');
        $this->assertEqualsWithDelta(1.754537853226051, $a['z_score'], 1e-9);
        $this->assertEqualsWithDelta(0.01, $a['delta_absolute'], 1e-9);
        $this->assertSame('medium', $a['evaluation_level']);
    }

    public function test_low_level(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $paid = $this->factFor($facts, 'Paid');
        $this->assertEqualsWithDelta(0.3267659794043593, $paid['z_score'], 1e-9);
        $this->assertSame('low', $paid['evaluation_level']);
    }

    public function test_insufficient_data_when_the_normal_approximation_gate_fails(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 1, 'clicks' => 10],
            'B' => ['conversions' => 2, 'clicks' => 20],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $a = $this->factFor($facts, 'A');
        $this->assertNull($a['z_score']);
        $this->assertSame('insufficient_data', $a['evaluation_level']);
        // Information is not discarded even though the statistical gate failed.
        $this->assertNotNull($a['metric_value']);
        $this->assertNotNull($a['direction']);
    }

    // --- Practical significance floor --------------------------------

    public function test_practical_floor_downgrades_high_to_medium(): void
    {
        // 50040/1000000 (0.05004) vs control 49960/1000000 (0.04996):
        // z≈5.026 (high), delta_abs≈0.001 (< floor 0.005) -> medium.
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 30600, 'clicks' => 600000],
            'B' => ['conversions' => 29400, 'clicks' => 600000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $a = $this->factFor($facts, 'A');
        $this->assertEqualsWithDelta(5.026246899500333, $a['z_score'], 1e-6);
        $this->assertEqualsWithDelta(0.000999999999999994, $a['delta_absolute'], 1e-9);
        $this->assertSame('medium', $a['evaluation_level']);
    }

    public function test_practical_floor_downgrades_medium_to_low(): void
    {
        // 2550/50000 (0.051) vs control 2450/50000 (0.049): z≈1.451
        // (medium), delta_abs≈0.001 (< floor 0.005) -> low.
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 2550, 'clicks' => 50000],
            'B' => ['conversions' => 2450, 'clicks' => 50000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $a = $this->factFor($facts, 'A');
        $this->assertEqualsWithDelta(1.4509525002200196, $a['z_score'], 1e-6);
        $this->assertSame('low', $a['evaluation_level']);
    }

    public function test_low_stays_low_after_the_floor_check(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $paid = $this->factFor($facts, 'Paid');
        $this->assertSame('low', $paid['evaluation_level']);
    }

    /**
     * Regression: abs(delta_absolute) mathematically equal to
     * practical_significance_floor must NOT downgrade — only a delta
     * strictly below the floor may. This is Case 1 of the float-boundary
     * fix: floor is set to the exact float value delta_absolute computes
     * to (bit-for-bit equal by construction), so this exercises the
     * "==" boundary itself rather than relying on incidental float
     * noise (see test_..._case_2... below for that).
     */
    public function test_practical_floor_does_not_downgrade_when_delta_exactly_equals_the_floor(): void
    {
        // A=110/2000 (0.055) vs B=90/2000 (0.045): display=0.05,
        // delta_absolute(A) mathematically = 0.005, z≈1.451 (medium).
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 110, 'clicks' => 2000],
            'B' => ['conversions' => 90, 'clicks' => 2000],
        ], ['conversions', 'clicks']);

        // First pass with a floor that can never trigger a downgrade, to
        // read off the exact float value this pipeline actually computes
        // for delta_absolute.
        $probe = $this->factFor(
            $this->action()->execute($this->definition(['practical_significance_floor' => 0.0]), $aggregatedMetrics),
            'A',
        );
        $exactDelta = abs($probe['delta_absolute']);

        $facts = $this->action()->execute(
            $this->definition(['practical_significance_floor' => $exactDelta]),
            $aggregatedMetrics,
        );

        $a = $this->factFor($facts, 'A');
        $this->assertEqualsWithDelta(1.4509525002200236, $a['z_score'], 1e-6);
        // A boundary-equal delta must not downgrade: medium stays medium.
        $this->assertSame('medium', $a['evaluation_level']);
    }

    /**
     * Case 2: the same "mathematically equal to the floor" boundary, but
     * reached through real aggregation arithmetic that happens to land
     * a few ULPs *under* the floor purely from float subtraction noise
     * (147/3000 - 810/15000 evaluates to -0.0049999999999999975, not
     * exactly -0.005). Before the epsilon fix this incorrectly
     * downgraded Organic from medium to low; it must not anymore.
     */
    public function test_practical_floor_does_not_downgrade_on_float_representation_noise(): void
    {
        $facts = $this->action()->execute($this->definition(), $this->fourChannelAggregatedMetrics());

        $organic = $this->factFor($facts, 'Organic');
        $this->assertEqualsWithDelta(-1.354700184921573, $organic['z_score'], 1e-9);
        // The raw float value really is a hair under 0.005 — confirming
        // this test exercises the float-noise path, not a clean value.
        $this->assertLessThan(0.005, abs($organic['delta_absolute']));
        $this->assertGreaterThan(0.005 - 1e-9, abs($organic['delta_absolute']));

        // z≈-1.35 is "medium" before the floor check; a mathematically
        // boundary-equal delta must not downgrade it to "low".
        $this->assertSame('medium', $organic['evaluation_level']);
    }

    /**
     * Case 3: a delta clearly (not just by float noise) below the floor
     * must still downgrade — the epsilon must not swallow genuine
     * below-floor differences.
     */
    public function test_practical_floor_still_downgrades_when_clearly_below_floor(): void
    {
        // A=108/2000 (0.054) vs B=92/2000 (0.046): display=0.05,
        // delta_absolute(A) ≈ 0.004 (clearly < floor 0.005), z≈1.16 (medium).
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 108, 'clicks' => 2000],
            'B' => ['conversions' => 92, 'clicks' => 2000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $a = $this->factFor($facts, 'A');
        $this->assertEqualsWithDelta(1.1607620001760186, $a['z_score'], 1e-6);
        $this->assertEqualsWithDelta(0.004, $a['delta_absolute'], 1e-6);
        $this->assertSame('low', $a['evaluation_level']);
    }

    /**
     * §48 regression: entity 4.0% vs display baseline 4.4% ->
     * delta_absolute = -0.004 (< floor 0.005) must downgrade high to
     * medium, even though delta_percent is roughly -9% — the floor
     * comparison must use delta_absolute, never delta_percent.
     */
    public function test_practical_floor_regression_uses_delta_absolute_not_delta_percent(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Entity' => ['conversions' => 400, 'clicks' => 10000],
            'Peer' => ['conversions' => 480, 'clicks' => 10000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $entity = $this->factFor($facts, 'Entity');
        $this->assertEqualsWithDelta(0.04, $entity['metric_value'], 1e-12);
        $this->assertEqualsWithDelta(0.044, $entity['display_baseline_value'], 1e-12);
        $this->assertEqualsWithDelta(-0.004, $entity['delta_absolute'], 1e-6);
        $this->assertEqualsWithDelta(-0.09090909090909084, $entity['delta_percent'], 1e-6);
        $this->assertEqualsWithDelta(-2.7581615808723163, $entity['z_score'], 1e-6);

        // |z| >= 1.96 would normally be "high", but abs(delta_absolute)
        // 0.004 < floor 0.005 downgrades it to "medium" — a naive
        // delta_percent-based check (~-9%, far above any sane floor)
        // would have wrongly kept this at "high".
        $this->assertSame('medium', $entity['evaluation_level']);
    }

    // --- Zero denominator ----------------------------------------------

    public function test_zero_denominator_entity_is_insufficient_data_with_a_null_metric_value(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Social' => ['conversions' => 200, 'clicks' => 5000],
            'Empty' => ['conversions' => 0, 'clicks' => 0],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $empty = $this->factFor($facts, 'Empty');
        $this->assertNull($empty['metric_value']);
        $this->assertSame(0, $empty['numerator_value']);
        $this->assertSame(0, $empty['denominator_value']);
        $this->assertSame('insufficient_data', $empty['evaluation_level']);
    }

    public function test_zero_denominator_control_leaves_the_entity_itself_insufficient_data(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Social' => ['conversions' => 200, 'clicks' => 5000],
            'Empty' => ['conversions' => 0, 'clicks' => 0],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $social = $this->factFor($facts, 'Social');
        // Social's own data is fine, but its only peer ("Empty") has a
        // zero denominator, so the leave-one-out control is 0/0: no
        // statistical evidence, even though Social's own metric_value is
        // still computable.
        $this->assertEqualsWithDelta(0.04, $social['metric_value'], 1e-12);
        $this->assertSame(0, $social['control_numerator_value']);
        $this->assertSame(0, $social['control_denominator_value']);
        $this->assertNull($social['test_baseline_value']);
        $this->assertNull($social['z_score']);
        $this->assertSame('insufficient_data', $social['evaluation_level']);
    }

    // --- Channel counts --------------------------------------------------

    public function test_single_channel_has_no_control_peers(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Only' => ['conversions' => 200, 'clicks' => 5000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $this->assertCount(1, $facts);
        $only = $facts[0];
        $this->assertEqualsWithDelta(0.04, $only['metric_value'], 1e-12);
        $this->assertEqualsWithDelta(0.04, $only['display_baseline_value'], 1e-12);
        $this->assertSame('equal', $only['direction']);
        $this->assertNull($only['test_baseline_value']);
        $this->assertNull($only['z_score']);
        $this->assertSame('insufficient_data', $only['evaluation_level']);
    }

    public function test_two_channels_produce_mirrored_facts(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 100, 'clicks' => 1000],
            'B' => ['conversions' => 50, 'clicks' => 1000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $this->assertCount(2, $facts);
        $a = $this->factFor($facts, 'A');
        $b = $this->factFor($facts, 'B');

        $this->assertSame('above', $a['direction']);
        $this->assertSame('below', $b['direction']);
        $this->assertEqualsWithDelta(-$a['z_score'], $b['z_score'], 1e-9);
    }

    public function test_extremely_uneven_traffic_still_produces_finite_facts(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Huge' => ['conversions' => 100000, 'clicks' => 2000000],
            'Tiny' => ['conversions' => 2, 'clicks' => 50],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $huge = $this->factFor($facts, 'Huge');
        $tiny = $this->factFor($facts, 'Tiny');

        $this->assertIsFloat($huge['metric_value']);
        $this->assertIsFloat($tiny['metric_value']);
        $this->assertEqualsWithDelta(0.05, $huge['metric_value'], 1e-9);
        $this->assertEqualsWithDelta(0.04, $tiny['metric_value'], 1e-9);
        $this->assertSame('above', $huge['direction']);
        $this->assertSame('below', $tiny['direction']);

        // Tiny's own count (n=50) fails the normal approximation gate
        // regardless of Huge's size on the other side -> insufficient_data
        // for both (Huge's control *is* Tiny).
        $this->assertSame('insufficient_data', $huge['evaluation_level']);
        $this->assertSame('insufficient_data', $tiny['evaluation_level']);
    }

    public function test_all_numerator_zero_is_insufficient_data_for_every_entity(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 0, 'clicks' => 1000],
            'B' => ['conversions' => 0, 'clicks' => 2000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        foreach ($facts as $fact) {
            $this->assertSame(0.0, $fact['metric_value']);
            $this->assertSame('equal', $fact['direction']);
            $this->assertNull($fact['z_score']);
            $this->assertSame('insufficient_data', $fact['evaluation_level']);
        }
    }

    // --- Count validity (§11/§12) ---------------------------------------

    public function test_fractional_count_from_aggregation_is_insufficient_data_and_not_stored(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Bad' => ['conversions' => 10.5, 'clicks' => 100],
            'Good' => ['conversions' => 200, 'clicks' => 5000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $bad = $this->factFor($facts, 'Bad');
        $this->assertSame('insufficient_data', $bad['evaluation_level']);
        $this->assertNull($bad['metric_value']);
        // 10.5 cannot be stored losslessly in an unsignedBigInteger
        // column and must never be rounded/truncated into one.
        $this->assertNull($bad['numerator_value']);

        // "Bad"'s corrupt count must not corrupt "Good"'s own baseline.
        $good = $this->factFor($facts, 'Good');
        $this->assertEqualsWithDelta(0.04, $good['display_baseline_value'], 1e-12);
    }

    public function test_numerator_greater_than_denominator_is_insufficient_data(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'Bad' => ['conversions' => 120, 'clicks' => 100],
            'Good' => ['conversions' => 200, 'clicks' => 5000],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $bad = $this->factFor($facts, 'Bad');
        $this->assertSame('insufficient_data', $bad['evaluation_level']);
        $this->assertNull($bad['metric_value']);
        // Each individual count (120, 100) is itself a valid, storable
        // non-negative integer even though the pair is relationally
        // invalid — kept for audit rather than nulled outright.
        $this->assertSame(120, $bad['numerator_value']);
        $this->assertSame(100, $bad['denominator_value']);

        $good = $this->factFor($facts, 'Good');
        $this->assertEqualsWithDelta(0.04, $good['display_baseline_value'], 1e-12);
    }

    // --- Mapping/aggregation unavailability (§6/§5) ---------------------

    public function test_missing_numerator_field_produces_insufficient_data_per_entity(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['clicks' => 1000],
            'B' => ['clicks' => 2000],
        ], ['clicks']);

        $facts = $this->action()->execute($this->definition(['numerator_field' => null]), $aggregatedMetrics);

        $this->assertCount(2, $facts);
        foreach ($facts as $fact) {
            $this->assertSame('insufficient_data', $fact['evaluation_level']);
            $this->assertNull($fact['numerator_value']);
            $this->assertNull($fact['denominator_value']);
            $this->assertNull($fact['metric_value']);
        }
    }

    public function test_missing_denominator_field_produces_insufficient_data_per_entity(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['conversions' => 10],
            'B' => ['conversions' => 20],
        ], ['conversions']);

        $facts = $this->action()->execute($this->definition(['denominator_field' => null]), $aggregatedMetrics);

        $this->assertCount(2, $facts);
        foreach ($facts as $fact) {
            $this->assertSame('insufficient_data', $fact['evaluation_level']);
        }
    }

    public function test_numerator_field_absent_from_aggregated_measures_is_insufficient_data(): void
    {
        // "conversions" is mapped and named, but MetricAggregationAction's
        // max_measures dropped it — it never made it into "measures".
        $aggregatedMetrics = $this->aggregatedMetrics('channel', [
            'A' => ['clicks' => 1000],
        ], ['clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $this->assertCount(1, $facts);
        $this->assertSame('insufficient_data', $facts[0]['evaluation_level']);
    }

    public function test_entity_dimension_absent_from_aggregated_metrics_produces_no_facts(): void
    {
        // "channel" resolved via Effective Mapping, but
        // MetricAggregationAction's cardinality/dimension limits dropped
        // it before it ever reached aggregated_metrics.dimensions.
        $aggregatedMetrics = $this->aggregatedMetrics('campaign', [
            'X' => ['conversions' => 10, 'clicks' => 100],
        ], ['conversions', 'clicks']);

        $facts = $this->action()->execute($this->definition(), $aggregatedMetrics);

        $this->assertSame([], $facts);
    }
}
