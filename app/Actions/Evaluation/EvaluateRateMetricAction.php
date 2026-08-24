<?php

declare(strict_types=1);

namespace App\Actions\Evaluation;

/**
 * Evaluates one resolved rate-metric definition (from
 * ResolveEvaluationMetricDefinitionsAction) against aggregated_metrics,
 * producing EvaluationFact-shaped rows (not yet persisted — no
 * analysis_job_id/computed_at) for every entity found in the target
 * dimension. See docs/product/EVALUATION_ENGINE.md.
 *
 * DB I/O free by design (EvaluateAnalysisJobAction owns persistence) and
 * AI-free by construction — this only ever reads aggregated_metrics
 * (never derived_metrics, never calls the AI).
 *
 * Three distinct outcomes per call, matching
 * docs/product/EVALUATION_ENGINE.md "aggregated_metrics前提確認" /
 * "Mapping不足時":
 *
 * 1. The entity dimension itself is not present in aggregated_metrics
 *    (e.g. MetricAggregationAction's max_dimensions/max_cardinality_per_dimension
 *    dropped it even though it *was* mapped) — entities cannot be
 *    enumerated at all, so this returns an empty list, exactly like
 *    ResolveEvaluationMetricDefinitionsAction's "entity_field_unmapped"
 *    skip. The caller is expected to log this.
 * 2. The dimension is present, but numerator_field/denominator_field are
 *    null (Mapping-unmapped) or not present in aggregated_metrics.measures
 *    (aggregation limit dropped them) — entities *can* be enumerated, so
 *    one evaluation_level=insufficient_data row is produced per entity.
 * 3. Both operands are usable: every entity is evaluated. An entity whose
 *    own raw counts fail count validity (see validateCount()) still gets
 *    its own insufficient_data row, but — critically — is excluded from
 *    every weighted sum (its own display baseline contribution and every
 *    other entity's leave-one-out control), so one corrupt entity never
 *    skews another entity's evaluation.
 *
 * Baselines are always weighted aggregates (sum of numerators / sum of
 * denominators across entities), never a mean of per-entity rates — see
 * docs/product/EVALUATION_ENGINE.md "Display Baseline" (Simpson's
 * paradox).
 */
class EvaluateRateMetricAction
{
    /**
     * Tolerance for treating an aggregated_metrics sum as "essentially an
     * integer" event count. See docs/product/EVALUATION_ENGINE.md "Count
     * Validity" — deliberately tight, matching
     * TwoProportionZTestAction::INTEGER_LIKE_EPSILON.
     */
    private const float COUNT_EPSILON = 1e-9;

    /**
     * Tolerance used only when comparing abs(delta_absolute) against
     * practical_significance_floor (see resolveLevel()). This is a
     * distinct concept from COUNT_EPSILON above (that one absorbs float
     * noise around an *integer* event count; this one absorbs float
     * noise around a *rate delta* on the 0-1 scale) and must not be
     * conflated with it or reused for it.
     *
     * Without this, a delta that is mathematically exactly equal to the
     * floor can land a few ULPs on either side purely from
     * floating-point subtraction (e.g. 147/3000 - 810/15000 evaluates to
     * -0.0049999999999999975, not exactly -0.005), which would make a
     * boundary-equal delta non-deterministically downgrade depending on
     * which side of zero the float noise happened to fall. Comparing
     * abs(delta_absolute) + COMPARISON_EPSILON < floor instead of a raw
     * abs(delta_absolute) < floor guarantees a mathematically-equal
     * delta never downgrades, while staying far too small (1e-10) to
     * mistake a genuinely-below-floor delta for an equal one — see
     * docs/product/EVALUATION_ENGINE.md "Practical Significance Floor".
     */
    private const float COMPARISON_EPSILON = 1e-10;

    public function __construct(
        private readonly TwoProportionZTestAction $twoProportionZTestAction,
    ) {}

    /**
     * @param array{
     *     metric_key: string,
     *     metric_type: string,
     *     entity_type: string,
     *     entity_field: string,
     *     numerator_field: string|null,
     *     denominator_field: string|null,
     *     unfavorable_direction: string|null,
     *     practical_significance_floor: float,
     *     normal_approximation_min_expected: int|float,
     *     z_threshold_high: float,
     *     z_threshold_medium: float,
     *     rule_version: string,
     * } $definition one resolved definition from ResolveEvaluationMetricDefinitionsAction
     * @param array<string, mixed> $aggregatedMetrics the MetricAggregationAction output for this AnalysisJob
     * @return list<array<string, mixed>>
     */
    public function execute(array $definition, array $aggregatedMetrics): array
    {
        $dimension = $this->findDimension($aggregatedMetrics, $definition['entity_field']);

        if ($dimension === null) {
            return [];
        }

        $groups = $dimension['groups'] ?? [];

        if (! is_array($groups) || $groups === []) {
            return [];
        }

        $measures = $aggregatedMetrics['measures'] ?? [];
        $numeratorField = $definition['numerator_field'];
        $denominatorField = $definition['denominator_field'];

        $numeratorUsable = is_string($numeratorField) && is_array($measures) && in_array($numeratorField, $measures, true);
        $denominatorUsable = is_string($denominatorField) && is_array($measures) && in_array($denominatorField, $measures, true);

        if (! $numeratorUsable || ! $denominatorUsable) {
            return array_map(
                fn (array $group): array => $this->insufficientFact($definition, (string) ($group['value'] ?? '')),
                $groups,
            );
        }

        // Pass 1: extract + validate each entity's raw counts, preserving
        // aggregated_metrics' own group order.
        $entities = [];

        foreach ($groups as $group) {
            $value = (string) ($group['value'] ?? '');
            $xRaw = $group['metrics'][$numeratorField]['sum'] ?? null;
            $nRaw = $group['metrics'][$denominatorField]['sum'] ?? null;

            $entities[] = ['value' => $value, ...$this->validateCount($xRaw, $nRaw)];
        }

        // Pass 2: weighted sums over valid entities only — an entity with
        // invalid counts contributes to nobody's baseline.
        $totalX = 0;
        $totalN = 0;

        foreach ($entities as $entity) {
            if ($entity['valid']) {
                $totalX += $entity['x'];
                $totalN += $entity['n'];
            }
        }

        // Force float division throughout — PHP's "/" returns int when
        // both operands are int and evenly divisible (e.g. 0/1000),
        // which would make metric_value/baseline's type inconsistent
        // across inputs.
        $displayBaseline = $totalN > 0 ? $totalX / (float) $totalN : null;

        // Pass 3: build one fact per entity, in original order.
        $facts = [];

        foreach ($entities as $entity) {
            if (! $entity['valid']) {
                $facts[] = $this->insufficientFact(
                    $definition,
                    $entity['value'],
                    $entity['storable_x'],
                    $entity['storable_n'],
                );

                continue;
            }

            $facts[] = $this->evaluateEntity($definition, $entity['value'], $entity['x'], $entity['n'], $totalX, $totalN, $displayBaseline);
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $aggregatedMetrics
     * @return array<string, mixed>|null the aggregated_metrics.dimensions[] entry matching $entityColumn, or null if not present
     */
    private function findDimension(array $aggregatedMetrics, string $entityColumn): ?array
    {
        foreach ($aggregatedMetrics['dimensions'] ?? [] as $dimension) {
            if (is_array($dimension) && ($dimension['dimension'] ?? null) === $entityColumn) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * Compute one valid entity's full evaluation: leave-one-out control,
     * metric/baseline values, delta, direction, the two-proportion
     * z-test, and the final evaluation_level.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function evaluateEntity(
        array $definition,
        string $entityKey,
        int $x,
        int $n,
        int $totalX,
        int $totalN,
        ?float $displayBaseline,
    ): array {
        $controlX = $totalX - $x;
        $controlN = $totalN - $n;

        $metricValue = $n > 0 ? $x / (float) $n : null;
        $testBaseline = $controlN > 0 ? $controlX / (float) $controlN : null;

        $deltaAbsolute = ($metricValue !== null && $displayBaseline !== null)
            ? $metricValue - $displayBaseline
            : null;

        $deltaPercent = ($deltaAbsolute !== null && $displayBaseline !== null && $displayBaseline != 0.0)
            ? $deltaAbsolute / $displayBaseline
            : null;

        $direction = $this->resolveDirection($metricValue, $displayBaseline);

        $zResult = $this->twoProportionZTestAction->execute(
            $x,
            $n,
            $controlX,
            $controlN,
            $definition['normal_approximation_min_expected'],
        );

        $evaluationLevel = $this->resolveLevel($zResult['z_score'], $deltaAbsolute, $definition);

        return $this->fact($definition, $entityKey, [
            'metric_value' => $metricValue,
            'display_baseline_value' => $displayBaseline,
            'test_baseline_value' => $testBaseline,
            'numerator_value' => $x,
            'denominator_value' => $n,
            'control_numerator_value' => $controlX,
            'control_denominator_value' => $controlN,
            'delta_absolute' => $deltaAbsolute,
            'delta_percent' => $deltaPercent,
            'z_score' => $zResult['z_score'],
            'direction' => $direction,
            'evaluation_level' => $evaluationLevel,
        ]);
    }

    /**
     * metric_value > display_baseline -> "above"; < -> "below"; == ->
     * "equal". A pure numeric fact — never collapsed to "in_line" or
     * otherwise blended with evaluation_level (see
     * docs/product/EVALUATION_ENGINE.md "Direction": a low-signal entity
     * still keeps its true numeric direction).
     */
    private function resolveDirection(?float $metricValue, ?float $displayBaseline): ?string
    {
        if ($metricValue === null || $displayBaseline === null) {
            return null;
        }

        if ($metricValue > $displayBaseline) {
            return 'above';
        }

        if ($metricValue < $displayBaseline) {
            return 'below';
        }

        return 'equal';
    }

    /**
     * z -> high/medium/low by config threshold, then downgraded one step
     * (high->medium, medium->low, low stays low) when
     * abs(delta_absolute) is under practical_significance_floor — always
     * compared against delta_absolute (0-1 scale), never delta_percent
     * (see docs/product/EVALUATION_ENGINE.md "Practical Significance
     * Floor").
     *
     * The floor comparison uses COMPARISON_EPSILON so a delta that is
     * mathematically exactly equal to the floor never downgrades merely
     * because of float subtraction noise (see that constant's docblock);
     * only a delta genuinely below the floor does.
     *
     * @param array<string, mixed> $definition
     */
    private function resolveLevel(?float $zScore, ?float $deltaAbsolute, array $definition): string
    {
        if ($zScore === null) {
            return 'insufficient_data';
        }

        $absZ = abs($zScore);

        $level = match (true) {
            $absZ >= $definition['z_threshold_high'] => 'high',
            $absZ >= $definition['z_threshold_medium'] => 'medium',
            default => 'low',
        };

        if ($deltaAbsolute !== null && abs($deltaAbsolute) + self::COMPARISON_EPSILON < $definition['practical_significance_floor']) {
            $level = match ($level) {
                'high' => 'medium',
                'medium' => 'low',
                default => 'low',
            };
        }

        return $level;
    }

    /**
     * Validate one entity's raw (numerator, denominator) sum pair pulled
     * from aggregated_metrics. See docs/product/EVALUATION_ENGINE.md
     * "Count Validity": both must independently be non-negative,
     * integer-like (within COUNT_EPSILON — absorbing float accumulation
     * noise, never forgiving a genuine fraction), and numerator <=
     * denominator.
     *
     * storable_x/storable_n hold the individually-valid rounded integer
     * even when the pair as a whole is invalid (e.g. numerator >
     * denominator), so an insufficient_data row can still audit the raw
     * counts that triggered it wherever that is itself safely
     * representable in an unsignedBigInteger column; a count that fails
     * its own integer-like/non-negative check is never stored, rounded,
     * or truncated.
     *
     * @return array{valid: bool, x: int, n: int, storable_x: int|null, storable_n: int|null}
     */
    private function validateCount(mixed $xRaw, mixed $nRaw): array
    {
        $x = $this->normalizeCount($xRaw);
        $n = $this->normalizeCount($nRaw);

        $valid = $x !== null && $n !== null && $x <= $n;

        return [
            'valid' => $valid,
            'x' => $valid ? $x : 0,
            'n' => $valid ? $n : 0,
            'storable_x' => $x,
            'storable_n' => $n,
        ];
    }

    /**
     * A single raw sum value -> a non-negative integer, or null if it is
     * missing, not numeric, non-finite, negative, or not integer-like.
     */
    private function normalizeCount(mixed $raw): ?int
    {
        if (! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        if (! is_finite((float) $raw)) {
            return null;
        }

        if (abs($raw - round($raw)) > self::COUNT_EPSILON) {
            return null;
        }

        $rounded = (int) round($raw);

        return $rounded >= 0 ? $rounded : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function insufficientFact(
        array $definition,
        string $entityKey,
        ?int $numeratorValue = null,
        ?int $denominatorValue = null,
    ): array {
        return $this->fact($definition, $entityKey, [
            'metric_value' => null,
            'display_baseline_value' => null,
            'test_baseline_value' => null,
            'numerator_value' => $numeratorValue,
            'denominator_value' => $denominatorValue,
            'control_numerator_value' => null,
            'control_denominator_value' => null,
            'delta_absolute' => null,
            'delta_percent' => null,
            'z_score' => null,
            'direction' => null,
            'evaluation_level' => 'insufficient_data',
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $computed
     * @return array<string, mixed>
     */
    private function fact(array $definition, string $entityKey, array $computed): array
    {
        return array_merge([
            'entity_type' => $definition['entity_type'],
            'entity_key' => $entityKey,
            'metric_key' => $definition['metric_key'],
            'metric_type' => $definition['metric_type'],
            'rule_version' => $definition['rule_version'],
        ], $computed);
    }
}
