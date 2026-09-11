<?php

declare(strict_types=1);

namespace App\Actions\Priority;

use InvalidArgumentException;

/**
 * Pure Priority arithmetic for one already-eligible EvaluationFact — the
 * same "pure calculation, DB-free, AI-free" role TwoProportionZTestAction
 * plays for Phase 4-A. See docs/product/PRIORITY_ENGINE.md.
 *
 * Formula (v1.1 — see PRIORITY_ENGINE.md "Priority Formula" /
 * "Gap Reference — なぜtest_baseline_valueか"):
 *
 *   impact_score  = impact_value / impact_total
 *   gap_reference = practical_significance_floor * gap_reference_multiple
 *   gap_raw_value = abs(metric_value - test_baseline_value)
 *   gap_score     = min(gap_raw_value / gap_reference, 1)
 *   priority_score = impact_score * gap_score
 *
 * Gap is measured against test_baseline_value (Phase 4-A's leave-one-out
 * peer/control baseline), never display_baseline_value (the weighted
 * average that *includes* this entity itself). Using display_baseline_value
 * here would self-dilute the gap for exactly the entities Impact is
 * designed to weight most heavily: a large-traffic-share entity pulls the
 * display baseline toward its own rate, shrinking its own measured gap the
 * larger its share grows — directly working against Priority's purpose.
 * test_baseline_value is unaffected by the entity's own share by
 * construction (see EVALUATION_ENGINE.md "Test / Control Baseline").
 *
 * No weighted sum, no evaluation_level weight, no diagnosis_confidence
 * factor — see PRIORITY_ENGINE.md "Evaluation LevelをScoreに入れない" for
 * why folding evaluation_level into the formula would double-count the
 * same underlying signal (metric_value vs. its baseline) Gap Score already
 * carries.
 *
 * priority_score is provably within [0, 1] once inputs pass validate()
 * below, with no defensive clamp needed: impact_score lands in [0, 1]
 * because impact_value is validated to be within [0, impact_total] and
 * impact_total > 0; gap_score lands in [0, 1] by construction of min(...,
 * 1) applied to a non-negative abs() value; the product of two values in
 * [0, 1] is itself in [0, 1].
 */
class CalculatePriorityAction
{
    /**
     * @param  float  $impactValue  this entity's own value for the configured impact_basis (e.g. its denominator_value)
     * @param  float  $impactTotal  population total impact_value is divided by (see PrioritizeAnalysisJobAction "Impact Total母集団定義")
     * @param  float  $metricValue  EvaluationFact.metric_value, verbatim — never recomputed
     * @param  float  $testBaselineValue  EvaluationFact.test_baseline_value (leave-one-out peer/control baseline), verbatim — never recomputed, never display_baseline_value (see class docblock)
     * @param  array{
     *     impact_basis: string,
     *     gap_reference_multiple: float,
     *     band_thresholds: array{high: float, medium: float},
     *     formula_version: string,
     *     practical_significance_floor: float,
     * }  $priorityRule  resolved by ResolvePriorityRuleAction
     * @return array{
     *     impact_basis: string,
     *     impact_value: float,
     *     impact_total: float,
     *     impact_score: float,
     *     gap_raw_value: float,
     *     gap_reference_value: float,
     *     gap_score: float,
     *     priority_score: float,
     *     priority_band: string,
     *     formula_version: string,
     * }
     *
     * @throws InvalidArgumentException if any input is out of its valid range — a controlled,
     *                                  caller-visible failure (see PrioritizeAnalysisJobAction's
     *                                  docblock "Soft-fail"), never silently coerced.
     */
    public function execute(float $impactValue, float $impactTotal, float $metricValue, float $testBaselineValue, array $priorityRule): array
    {
        $this->validate($impactValue, $impactTotal, $metricValue, $testBaselineValue, $priorityRule);

        $impactScore = $impactValue / $impactTotal;

        $gapReferenceValue = $priorityRule['practical_significance_floor'] * $priorityRule['gap_reference_multiple'];
        $gapRawValue = abs($metricValue - $testBaselineValue);
        $gapScore = min($gapRawValue / $gapReferenceValue, 1.0);

        $priorityScore = $impactScore * $gapScore;

        return [
            'impact_basis' => $priorityRule['impact_basis'],
            'impact_value' => $impactValue,
            'impact_total' => $impactTotal,
            'impact_score' => $impactScore,
            'gap_raw_value' => $gapRawValue,
            'gap_reference_value' => $gapReferenceValue,
            'gap_score' => $gapScore,
            'priority_score' => $priorityScore,
            'priority_band' => $this->resolveBand($priorityScore, $priorityRule['band_thresholds']),
            'formula_version' => $priorityRule['formula_version'],
        ];
    }

    /**
     * @param  array{high: float, medium: float}  $bandThresholds
     */
    private function resolveBand(float $priorityScore, array $bandThresholds): string
    {
        return match (true) {
            $priorityScore >= $bandThresholds['high'] => 'high',
            $priorityScore >= $bandThresholds['medium'] => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  array{
     *     impact_basis: string,
     *     gap_reference_multiple: float,
     *     band_thresholds: array{high: float, medium: float},
     *     formula_version: string,
     *     practical_significance_floor: float,
     * }  $priorityRule
     */
    private function validate(float $impactValue, float $impactTotal, float $metricValue, float $testBaselineValue, array $priorityRule): void
    {
        if (! is_finite($impactValue) || $impactValue < 0.0) {
            throw new InvalidArgumentException('impact_value must be a non-negative, finite number.');
        }

        if (! is_finite($impactTotal) || $impactTotal <= 0.0) {
            throw new InvalidArgumentException('impact_total must be a positive, finite number.');
        }

        if ($impactValue > $impactTotal) {
            throw new InvalidArgumentException('impact_value must not exceed impact_total.');
        }

        if (! is_finite($metricValue)) {
            throw new InvalidArgumentException('metric_value must be a finite number.');
        }

        if (! is_finite($testBaselineValue)) {
            throw new InvalidArgumentException('test_baseline_value must be a finite number.');
        }

        if ($priorityRule['practical_significance_floor'] <= 0.0) {
            throw new InvalidArgumentException('practical_significance_floor must be positive.');
        }

        if ($priorityRule['gap_reference_multiple'] <= 0.0) {
            throw new InvalidArgumentException('gap_reference_multiple must be positive.');
        }

        $high = $priorityRule['band_thresholds']['high'];
        $medium = $priorityRule['band_thresholds']['medium'];

        if ($high < 0.0 || $high > 1.0 || $medium < 0.0 || $medium > 1.0) {
            throw new InvalidArgumentException('band_thresholds must each be within [0, 1].');
        }

        if ($high <= $medium) {
            throw new InvalidArgumentException('band_thresholds.high must be greater than band_thresholds.medium.');
        }
    }
}
