<?php

declare(strict_types=1);

namespace App\Actions\Evaluation;

/**
 * Computes a pooled two-proportion z-test statistic. See
 * docs/product/EVALUATION_ENGINE.md "Two-Proportion Z-Test".
 *
 * Pure deterministic math: no AI I/O, no database I/O, no config()
 * lookups of its own (the normal-approximation threshold is passed in by
 * the caller, which reads it from config/evaluation_metrics.php — see
 * EvaluateRateMetricAction). Given the same inputs it always returns the
 * same result.
 *
 * pA = xA / nA
 * pB = xB / nB
 * pPool = (xA + xB) / (nA + nB)
 * SE = sqrt(pPool * (1 - pPool) * (1/nA + 1/nB))
 * z = (pA - pB) / SE
 *
 * This never throws, regardless of input: an event count that is
 * negative, non-integer-like, or has numerator > denominator, and every
 * mathematical degeneracy (nA/nB = 0, pPool = 0 or 1, SE = 0, a
 * non-finite result) all resolve to gate_passed = false / z_score = null
 * rather than an exception — one bad entity/metric must never break the
 * whole AnalysisJob. See docs/product/EVALUATION_ENGINE.md "Count
 * Validity" / "Normal Approximation Gate".
 */
class TwoProportionZTestAction
{
    /**
     * Tolerance for treating a float as "essentially an integer" (an
     * event count). Deliberately tight — see
     * docs/product/EVALUATION_ENGINE.md "Count Validity": this is meant
     * to absorb float accumulation noise (e.g. a sum computed as
     * 10.000000000000002), not to forgive a genuinely fractional count
     * like 10.5.
     */
    private const float INTEGER_LIKE_EPSILON = 1e-9;

    /**
     * @param int|float $xA entity numerator event count
     * @param int|float $nA entity denominator event count
     * @param int|float $xB control numerator event count
     * @param int|float $nB control denominator event count
     * @param int|float $normalApproximationMinExpected minimum expected successes/failures required in every one of the 4 (entity/control x numerator/denominator) cells before the normal approximation is trusted (config/evaluation_metrics.php "normal_approximation_min_expected")
     * @return array{z_score: float|null, p_pool: float|null, gate_passed: bool}
     */
    public function execute(
        int|float $xA,
        int|float $nA,
        int|float $xB,
        int|float $nB,
        int|float $normalApproximationMinExpected,
    ): array {
        $insufficient = ['z_score' => null, 'p_pool' => null, 'gate_passed' => false];

        if (! $this->isValidCount($xA) || ! $this->isValidCount($nA)
            || ! $this->isValidCount($xB) || ! $this->isValidCount($nB)
        ) {
            return $insufficient;
        }

        // Round only after confirming each value is integer-like — never
        // silently round/truncate a genuinely fractional count (see
        // docs/product/EVALUATION_ENGINE.md "Count Validity").
        $xA = (int) round($xA);
        $nA = (int) round($nA);
        $xB = (int) round($xB);
        $nB = (int) round($nB);

        if ($xA < 0 || $nA < 0 || $xB < 0 || $nB < 0) {
            return $insufficient;
        }

        if ($xA > $nA || $xB > $nB) {
            return $insufficient;
        }

        if ($nA === 0 || $nB === 0) {
            return $insufficient;
        }

        // Force float division: PHP's "/" returns int when both operands
        // are int and evenly divisible (e.g. 0/15000), which would make
        // p_pool's type inconsistent across inputs.
        $pPool = ($xA + $xB) / (float) ($nA + $nB);

        if ($pPool <= 0.0 || $pPool >= 1.0) {
            // SE would be exactly 0 (every observation on one side) —
            // not a meaningful test.
            return ['z_score' => null, 'p_pool' => $pPool, 'gate_passed' => false];
        }

        $gatePassed = $nA * $pPool >= $normalApproximationMinExpected
            && $nA * (1 - $pPool) >= $normalApproximationMinExpected
            && $nB * $pPool >= $normalApproximationMinExpected
            && $nB * (1 - $pPool) >= $normalApproximationMinExpected;

        if (! $gatePassed) {
            return ['z_score' => null, 'p_pool' => $pPool, 'gate_passed' => false];
        }

        $se = sqrt($pPool * (1 - $pPool) * (1 / $nA + 1 / $nB));

        if ($se <= 0.0 || ! is_finite($se)) {
            return ['z_score' => null, 'p_pool' => $pPool, 'gate_passed' => false];
        }

        $pA = $xA / $nA;
        $pB = $xB / $nB;

        $z = ($pA - $pB) / $se;

        if (! is_finite($z)) {
            return ['z_score' => null, 'p_pool' => $pPool, 'gate_passed' => false];
        }

        return ['z_score' => $z, 'p_pool' => $pPool, 'gate_passed' => true];
    }

    /**
     * Checks only that $value is finite and "integer-like" within
     * INTEGER_LIKE_EPSILON (see the class docblock — 10.0 is valid, 10.5
     * is not). This does *not* check non-negativity or numerator <=
     * denominator: those are relational/sign checks that only make sense
     * once every value has passed this per-value check and been rounded
     * to an actual int, so execute() performs them itself immediately
     * after rounding (see the `$xA < 0 || ...` and `$xA > $nA || ...`
     * guards there) rather than duplicating them here per-value.
     */
    private function isValidCount(int|float $value): bool
    {
        if (! is_finite((float) $value)) {
            return false;
        }

        return abs($value - round($value)) <= self::INTEGER_LIKE_EPSILON;
    }
}
