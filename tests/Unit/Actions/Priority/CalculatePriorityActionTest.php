<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Priority;

use App\Actions\Priority\CalculatePriorityAction;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Direct coverage for CalculatePriorityAction (Phase 4-C v1.1): pure
 * arithmetic, no DB/AI I/O. See docs/product/PRIORITY_ENGINE.md "Priority
 * Formula" / "Gap Reference" / "Priority invariants".
 *
 * gap_raw_value is always abs(metricValue - testBaselineValue) —
 * test_baseline_value (leave-one-out peer/control baseline), never
 * display_baseline_value (which self-dilutes for a large-traffic-share
 * entity; see CalculatePriorityAction's own class docblock). Every test
 * below therefore passes metricValue/testBaselineValue directly rather
 * than a pre-combined delta.
 */
class CalculatePriorityActionTest extends TestCase
{
    /**
     * @return array{
     *     impact_basis: string,
     *     gap_reference_multiple: float,
     *     band_thresholds: array{high: float, medium: float},
     *     formula_version: string,
     *     practical_significance_floor: float,
     * }
     */
    private function rule(): array
    {
        return [
            'impact_basis' => 'denominator_share',
            'gap_reference_multiple' => 4.0,
            'band_thresholds' => ['high' => 0.35, 'medium' => 0.10],
            'formula_version' => 'priority_v1.1',
            'practical_significance_floor' => 0.005,
        ];
    }

    private function action(): CalculatePriorityAction
    {
        return new CalculatePriorityAction;
    }

    /**
     * The reviewer's own worked example: metric_value=0.02,
     * test_baseline_value=0.06 -> gap_raw_value=0.04 exactly.
     */
    public function test_gap_raw_value_is_the_absolute_metric_to_test_baseline_difference(): void
    {
        $result = $this->action()->execute(5000.0, 10000.0, 0.02, 0.06, $this->rule());

        $this->assertEqualsWithDelta(0.04, $result['gap_raw_value'], 1e-9);
    }

    /**
     * Case A Social, independently computed from the real leave-one-out
     * control (see EVALUATION_ENGINE.md's own worked example / the
     * ExecuteAnalysisJobPriorityTest CSV): metric_value=0.035,
     * test_baseline_value=0.0525 -> gap_raw=0.0175, impact 12000/60000 =
     * 0.20, gap_reference = 0.005*4 = 0.02, gap = min(0.0175/0.02, 1) =
     * 0.875, score = 0.20*0.875 = 0.175 -> medium (>= 0.10, < 0.35).
     */
    public function test_case_a_social_matches_the_independently_computed_score(): void
    {
        $result = $this->action()->execute(12000.0, 60000.0, 0.035, 0.0525, $this->rule());

        $this->assertEqualsWithDelta(0.20, $result['impact_score'], 1e-9);
        $this->assertEqualsWithDelta(0.02, $result['gap_reference_value'], 1e-9);
        $this->assertEqualsWithDelta(0.0175, $result['gap_raw_value'], 1e-9);
        $this->assertEqualsWithDelta(0.875, $result['gap_score'], 1e-9);
        $this->assertEqualsWithDelta(0.175, $result['priority_score'], 1e-9);
        $this->assertSame('medium', $result['priority_band']);
        $this->assertSame('denominator_share', $result['impact_basis']);
        $this->assertSame('priority_v1.1', $result['formula_version']);
    }

    /**
     * Case A Display: metric_value=0.045, test_baseline_value=0.05 ->
     * gap_raw=0.005, impact 12000/60000 = 0.20, gap = min(0.005/0.02, 1)
     * = 0.25, score = 0.20*0.25 = 0.05 -> low.
     */
    public function test_case_a_display_matches_the_independently_computed_score(): void
    {
        $result = $this->action()->execute(12000.0, 60000.0, 0.045, 0.05, $this->rule());

        $this->assertEqualsWithDelta(0.05, $result['priority_score'], 1e-9);
        $this->assertSame('low', $result['priority_band']);
    }

    /**
     * The self-dilution regression this fix targets: a 70,000-click
     * dominant channel at metric_value=0.02 against a leave-one-out
     * peer control of 0.06 (rather than a self-diluted display baseline
     * of ~0.032) keeps gap_raw_value at the full 0.04 (4.0pp), clipping
     * gap_score to 1.0 instead of the ~0.6 the old display-baseline
     * formula would have produced.
     */
    public function test_a_dominant_entitys_gap_is_not_self_diluted_by_its_own_share(): void
    {
        $result = $this->action()->execute(70000.0, 100000.0, 0.02, 0.06, $this->rule());

        $this->assertEqualsWithDelta(0.04, $result['gap_raw_value'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['gap_score'], 1e-9);
        $this->assertEqualsWithDelta(0.70, $result['priority_score'], 1e-9);
        $this->assertSame('high', $result['priority_band']);
    }

    public function test_gap_score_clips_at_one(): void
    {
        $result = $this->action()->execute(10000.0, 10000.0, 1.0, 0.0, $this->rule());

        $this->assertEqualsWithDelta(1.0, $result['gap_score'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['priority_score'], 1e-9);
    }

    public function test_zero_impact_produces_zero_score(): void
    {
        $result = $this->action()->execute(0.0, 10000.0, 1.0, 0.0, $this->rule());

        $this->assertEqualsWithDelta(0.0, $result['impact_score'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $result['priority_score'], 1e-9);
        $this->assertSame('low', $result['priority_band']);
    }

    public function test_zero_gap_produces_zero_score(): void
    {
        $result = $this->action()->execute(5000.0, 10000.0, 0.05, 0.05, $this->rule());

        $this->assertEqualsWithDelta(0.0, $result['gap_score'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $result['priority_score'], 1e-9);
    }

    public function test_increasing_impact_with_the_same_gap_increases_the_score(): void
    {
        $small = $this->action()->execute(1000.0, 10000.0, 0.02, 0.04, $this->rule());
        $large = $this->action()->execute(5000.0, 10000.0, 0.02, 0.04, $this->rule());

        $this->assertGreaterThan($small['priority_score'], $large['priority_score']);
    }

    public function test_increasing_gap_with_the_same_impact_increases_the_score(): void
    {
        $small = $this->action()->execute(5000.0, 10000.0, 0.048, 0.05, $this->rule());
        $large = $this->action()->execute(5000.0, 10000.0, 0.03, 0.05, $this->rule());

        $this->assertGreaterThan($small['priority_score'], $large['priority_score']);
    }

    /**
     * The core self-dilution invariant, generalized: holding the peer gap
     * fixed, increasing an entity's impact share must never *decrease* its
     * priority_score. (Under the old display_baseline-based formula this
     * failed: a larger share would drag the baseline toward the entity's
     * own rate and shrink its measured gap.)
     */
    public function test_increasing_impact_share_with_the_same_peer_gap_never_decreases_the_score(): void
    {
        $share70 = $this->action()->execute(70000.0, 100000.0, 0.02, 0.06, $this->rule());
        $share90 = $this->action()->execute(90000.0, 100000.0, 0.02, 0.06, $this->rule());

        $this->assertGreaterThan($share70['priority_score'], $share90['priority_score']);
        $this->assertEqualsWithDelta($share70['gap_raw_value'], $share90['gap_raw_value'], 1e-9, 'gap must not shrink just because impact share grew.');
    }

    public function test_priority_score_is_always_within_zero_and_one(): void
    {
        $result = $this->action()->execute(9999.0, 10000.0, 100.0, 0.0, $this->rule());

        $this->assertGreaterThanOrEqual(0.0, $result['priority_score']);
        $this->assertLessThanOrEqual(1.0, $result['priority_score']);
    }

    public function test_score_exactly_at_the_high_threshold_is_high(): void
    {
        // impact 10000/10000 = 1.0; gap_reference = 0.005*4 = 0.02;
        // gap = 0.007/0.02 = 0.35; score = 1.0*0.35 = 0.35 exactly.
        $result = $this->action()->execute(10000.0, 10000.0, 0.007, 0.0, $this->rule());

        $this->assertEqualsWithDelta(0.35, $result['priority_score'], 1e-9);
        $this->assertSame('high', $result['priority_band']);
    }

    public function test_score_exactly_at_the_medium_threshold_is_medium(): void
    {
        // impact 1.0; gap = 0.002/0.02 = 0.10; score = 0.10 exactly.
        $result = $this->action()->execute(10000.0, 10000.0, 0.002, 0.0, $this->rule());

        $this->assertEqualsWithDelta(0.10, $result['priority_score'], 1e-9);
        $this->assertSame('medium', $result['priority_band']);
    }

    public function test_score_below_the_medium_threshold_is_low(): void
    {
        $result = $this->action()->execute(10000.0, 10000.0, 0.0019, 0.0, $this->rule());

        $this->assertLessThan(0.10, $result['priority_score']);
        $this->assertSame('low', $result['priority_band']);
    }

    public function test_negative_impact_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute(-1.0, 10000.0, 0.02, 0.04, $this->rule());
    }

    public function test_non_positive_impact_total_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute(1000.0, 0.0, 0.02, 0.04, $this->rule());
    }

    public function test_impact_value_exceeding_impact_total_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute(11000.0, 10000.0, 0.02, 0.04, $this->rule());
    }

    public function test_non_positive_practical_significance_floor_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['practical_significance_floor'] = 0.0;

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute(1000.0, 10000.0, 0.02, 0.04, $rule);
    }

    public function test_non_positive_gap_reference_multiple_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['gap_reference_multiple'] = 0.0;

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute(1000.0, 10000.0, 0.02, 0.04, $rule);
    }

    public function test_high_threshold_not_exceeding_medium_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['band_thresholds'] = ['high' => 0.10, 'medium' => 0.10];

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute(1000.0, 10000.0, 0.02, 0.04, $rule);
    }

    /**
     * Same EvaluationFact-derived inputs -> byte-for-byte identical output,
     * regardless of how many times computed — no hidden state, no
     * randomness (see docs/product/PRIORITY_ENGINE.md "Idempotency").
     */
    public function test_identical_inputs_produce_identical_output(): void
    {
        $first = $this->action()->execute(12000.0, 60000.0, 0.035, 0.0525, $this->rule());
        $second = $this->action()->execute(12000.0, 60000.0, 0.035, 0.0525, $this->rule());

        $this->assertSame($first, $second);
    }
}
