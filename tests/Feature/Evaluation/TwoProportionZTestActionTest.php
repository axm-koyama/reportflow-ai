<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Actions\Evaluation\TwoProportionZTestAction;
use Tests\TestCase;

/**
 * Direct unit coverage for the pure two-proportion z-test math. See
 * docs/product/EVALUATION_ENGINE.md "Two-Proportion Z-Test" /
 * "Count Validity" / "Normal Approximation Gate".
 */
class TwoProportionZTestActionTest extends TestCase
{
    private function action(): TwoProportionZTestAction
    {
        return new TwoProportionZTestAction;
    }

    /**
     * Independent Numerical Verification: Social (200/5000) vs its peer
     * control (610/10000). Hand/independently computed (Python) as
     * z ≈ -5.3643390485032, matching the ≈ -5.36 figure verified during
     * Phase 4-A investigation.
     */
    public function test_it_matches_the_independently_verified_social_channel_example(): void
    {
        $result = $this->action()->execute(200, 5000, 610, 10000, 5);

        $this->assertTrue($result['gate_passed']);
        $this->assertNotNull($result['z_score']);
        $this->assertEqualsWithDelta(-5.3643390485032, $result['z_score'], 1e-9);
        $this->assertEqualsWithDelta(0.054, $result['p_pool'], 1e-12);
    }

    public function test_positive_z_when_the_entity_rate_exceeds_the_control_rate(): void
    {
        $result = $this->action()->execute(120, 1000, 80, 1000, 5);

        $this->assertTrue($result['gate_passed']);
        $this->assertEqualsWithDelta(2.9814239699997187, $result['z_score'], 1e-9);
    }

    public function test_na_zero_is_insufficient(): void
    {
        $result = $this->action()->execute(0, 0, 610, 10000, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
    }

    public function test_nb_zero_is_insufficient(): void
    {
        $result = $this->action()->execute(200, 5000, 0, 0, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
    }

    public function test_p_pool_zero_is_insufficient(): void
    {
        // Every numerator is 0: pPool = 0/(nA+nB) = 0.
        $result = $this->action()->execute(0, 5000, 0, 10000, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        $this->assertSame(0.0, $result['p_pool']);
    }

    public function test_p_pool_one_is_insufficient(): void
    {
        // Every observation is a "success": pPool = (nA+nB)/(nA+nB) = 1.
        $result = $this->action()->execute(5000, 5000, 10000, 10000, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        $this->assertSame(1.0, $result['p_pool']);
    }

    public function test_se_zero_boundary_is_insufficient(): void
    {
        // xA=xB=0 drives pPool to exactly 0, which is exactly the
        // condition that would make SE = sqrt(0 * ... ) = 0. The pPool
        // boundary guard catches this before sqrt()/division ever runs,
        // so SE = 0 is structurally unreachable rather than merely
        // checked after the fact — see TwoProportionZTestAction's
        // docblock.
        $result = $this->action()->execute(0, 1, 0, 1, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        $this->assertSame(0.0, $result['p_pool']);
    }

    public function test_normal_approximation_gate_passes_for_ample_counts(): void
    {
        $result = $this->action()->execute(200, 5000, 610, 10000, 5);

        $this->assertTrue($result['gate_passed']);
    }

    public function test_normal_approximation_gate_fails_for_small_counts(): void
    {
        // pPool = 3/30 = 0.1; nA*pPool = 10*0.1 = 1 < threshold 5.
        $result = $this->action()->execute(1, 10, 2, 20, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        // p_pool is still reported for diagnostics even though the gate failed.
        $this->assertEqualsWithDelta(0.1, $result['p_pool'], 1e-12);
    }

    public function test_large_counts_produce_a_finite_result(): void
    {
        $result = $this->action()->execute(1_000_000, 10_000_000, 990_000, 10_000_000, 5);

        $this->assertTrue($result['gate_passed']);
        $this->assertEqualsWithDelta(7.470189229928193, $result['z_score'], 1e-6);
    }

    public function test_integer_like_float_counts_are_valid(): void
    {
        // Aggregated sums can legitimately arrive as float (MetricAggregationAction
        // accumulates int|float); 10.0 must be accepted exactly like 10.
        $result = $this->action()->execute(200.0, 5000.0, 610.0, 10000.0, 5);

        $this->assertTrue($result['gate_passed']);
        $this->assertEqualsWithDelta(-5.3643390485032, $result['z_score'], 1e-9);
    }

    public function test_fractional_count_is_invalid(): void
    {
        $result = $this->action()->execute(10.5, 100, 610, 10000, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        $this->assertNull($result['p_pool']);
    }

    public function test_numerator_greater_than_denominator_is_invalid(): void
    {
        $result = $this->action()->execute(120, 100, 610, 10000, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        $this->assertNull($result['p_pool']);
    }

    public function test_negative_count_is_invalid(): void
    {
        $result = $this->action()->execute(-5, 100, 610, 10000, 5);

        $this->assertFalse($result['gate_passed']);
        $this->assertNull($result['z_score']);
        $this->assertNull($result['p_pool']);
    }
}
