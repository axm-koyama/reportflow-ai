<?php

declare(strict_types=1);

namespace App\Actions\Priority;

/**
 * Resolves config/priority_rules.php's per-Template, per-metric Priority
 * rule into a single, ready-to-use array for CalculatePriorityAction — the
 * same "config lookup, translated for one calculation" role
 * ResolveEvaluationMetricDefinitionsAction plays for Phase 4-A. See
 * docs/product/PRIORITY_ENGINE.md.
 *
 * Zero AI I/O, zero DB I/O — pure config lookup.
 *
 * practical_significance_floor is deliberately read from
 * config/evaluation_metrics.php here, not duplicated into
 * config/priority_rules.php — that value's Single Source of Truth stays
 * Phase 4-A's own config (see docs/product/PRIORITY_ENGINE.md "Gap
 * Reference"). A metric with a config/priority_rules.php entry but no
 * matching config/evaluation_metrics.php floor (should not happen in
 * practice — Priority is only ever evaluated for a metric_key that already
 * produced an EvaluationFact from an evaluation_metrics.php-defined metric)
 * resolves to null defensively, same as any other missing piece.
 *
 * A Template key, metric_key, or floor absent from either config file
 * simply resolves to null — "this metric has no Priority rule (yet)",
 * never an error. The caller (PrioritizeAnalysisJobAction) treats null as
 * a normal skip.
 */
class ResolvePriorityRuleAction
{
    /**
     * @return array{
     *     impact_basis: string,
     *     gap_reference_multiple: float,
     *     band_thresholds: array{high: float, medium: float},
     *     formula_version: string,
     *     practical_significance_floor: float,
     * }|null
     */
    public function execute(string $templateKey, string $metricKey): ?array
    {
        $rule = config("priority_rules.{$templateKey}.metrics.{$metricKey}");

        if (! is_array($rule)) {
            return null;
        }

        $impactBasis = $rule['impact_basis'] ?? null;
        $gapReferenceMultiple = $rule['gap_reference_multiple'] ?? null;
        $bandThresholds = $rule['band_thresholds'] ?? null;
        $formulaVersion = $rule['formula_version'] ?? null;

        if (! is_string($impactBasis) || $impactBasis === '') {
            return null;
        }

        if (! is_numeric($gapReferenceMultiple) || (float) $gapReferenceMultiple <= 0) {
            return null;
        }

        if (! is_array($bandThresholds)) {
            return null;
        }

        $high = $bandThresholds['high'] ?? null;
        $medium = $bandThresholds['medium'] ?? null;

        if (! is_numeric($high) || ! is_numeric($medium)) {
            return null;
        }

        $high = (float) $high;
        $medium = (float) $medium;

        if ($high < 0.0 || $high > 1.0 || $medium < 0.0 || $medium > 1.0 || $high <= $medium) {
            return null;
        }

        if (! is_string($formulaVersion) || $formulaVersion === '') {
            return null;
        }

        $practicalSignificanceFloor = $this->practicalSignificanceFloorFor($templateKey, $metricKey);

        if ($practicalSignificanceFloor === null || $practicalSignificanceFloor <= 0.0) {
            return null;
        }

        return [
            'impact_basis' => $impactBasis,
            'gap_reference_multiple' => (float) $gapReferenceMultiple,
            'band_thresholds' => ['high' => $high, 'medium' => $medium],
            'formula_version' => $formulaVersion,
            'practical_significance_floor' => $practicalSignificanceFloor,
        ];
    }

    /**
     * Look up this metric's configured practical_significance_floor
     * directly from config/evaluation_metrics.php — the same Single Source
     * of Truth Phase 4-A itself uses (see EvaluateRateMetricAction). No new
     * config key duplicates it for Phase 4-C.
     */
    private function practicalSignificanceFloorFor(string $templateKey, string $metricKey): ?float
    {
        $metrics = config("evaluation_metrics.{$templateKey}.metrics", []);

        if (! is_array($metrics)) {
            return null;
        }

        foreach ($metrics as $metric) {
            if (! is_array($metric) || ($metric['metric_key'] ?? null) !== $metricKey) {
                continue;
            }

            $floor = $metric['practical_significance_floor'] ?? null;

            return is_numeric($floor) ? (float) $floor : null;
        }

        return null;
    }
}
