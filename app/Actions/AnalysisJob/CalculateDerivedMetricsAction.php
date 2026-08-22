<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Validates AI-proposed CalculationDefinitions and computes their values
 * from aggregated_metrics. See docs/product/DERIVED_METRICS.md.
 *
 * This is a pure Calculation Engine: it never calls an AI provider, never
 * touches the database, and never evaluates a free-form expression. Each
 * of the 5 allowed operators (divide / multiply / add / subtract /
 * percentage) is executed via an explicit match() branch — there is no
 * expression evaluator, no expression parser, and no dynamic function
 * dispatch anywhere in this class. A CalculationDefinition's "operator"
 * value only ever selects one of these 5 hardcoded branches; it never
 * becomes code.
 *
 * Responsibilities:
 * - Validate each proposed CalculationDefinition (name / operator /
 *   group_by / operand metric / operand aggregation)
 * - Enforce that "name" is unique within one Calculation Plan — it is the
 *   identifier the final analysis AI uses to refer to a derived metric, so
 *   two definitions must never share one (see "duplicate_name" below)
 * - Resolve operand values from aggregated_metrics (never from
 *   sample_rows or any other source)
 * - Compute the result per group, handling division by zero and missing
 *   (null) operands without ever raising an exception or producing
 *   INF/NAN
 * - Record every rejected definition with a reason, so nothing silently
 *   disappears
 *
 * A single invalid definition never fails the whole batch: valid
 * definitions are always computed regardless of how many others were
 * rejected.
 *
 * Out of scope: calling the AI, building the AI Context, persistence,
 * nested/derived-of-derived expressions, and any operator not in the
 * fixed allow-list.
 */
class CalculateDerivedMetricsAction
{
    /**
     * Allowed CalculationDefinition operators. No other operator string is
     * ever executed, regardless of what the AI proposes.
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATORS = ['divide', 'multiply', 'add', 'subtract', 'percentage'];

    /**
     * Allowed operand aggregation types — exactly the three
     * MetricAggregationAction always computes per group.
     *
     * @var list<string>
     */
    private const array ALLOWED_AGGREGATIONS = ['sum', 'count', 'avg'];

    /**
     * Operators that divide by the right operand, and therefore require a
     * zero-division guard.
     *
     * @var list<string>
     */
    private const array DIVIDING_OPERATORS = ['divide', 'percentage'];

    /**
     * A safe metric-name identifier: starts with a letter, followed by up
     * to 63 letters/digits/underscores.
     */
    private const string NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /**
     * Set by validate()/validateOperand() immediately before signaling
     * failure, so execute() can attach the specific rejection reason
     * without validate() needing to return a compound success/failure
     * structure.
     */
    private string $lastRejectionReason = 'invalid_definition';

    /**
     * Validate and compute derived metrics from AI-proposed
     * CalculationDefinitions.
     *
     * @param list<array<string, mixed>> $proposedDefinitions raw, untrusted definitions from PlanDerivedMetricsAction
     * @param array<string, mixed> $aggregatedMetrics the MetricAggregationAction output for the same AnalysisJob
     * @return array<string, mixed>
     */
    public function execute(array $proposedDefinitions, array $aggregatedMetrics): array
    {
        $dimensions = $this->indexDimensions($aggregatedMetrics);
        $measures = array_fill_keys($aggregatedMetrics['measures'] ?? [], true);
        $limit = max((int) config('derived_metrics.max_derived_metrics', 5), 0);

        $metrics = [];
        $rejected = [];
        $seenNames = [];

        foreach (array_values($proposedDefinitions) as $index => $raw) {
            if ($index >= $limit) {
                $rejected[] = ['name' => $this->rejectionName($raw), 'reason' => 'limit_exceeded'];

                continue;
            }

            $validated = $this->validate($raw, $measures, $dimensions);

            if ($validated === null) {
                $rejected[] = ['name' => $this->rejectionName($raw), 'reason' => $this->lastRejectionReason];

                continue;
            }

            // A "name" is the identifier the final analysis AI uses to refer
            // to this derived metric, so it must be unique within one
            // Calculation Plan. The first otherwise-valid definition to use
            // a given name wins; any later definition reusing that name is
            // rejected here, even though it would otherwise be valid on its
            // own. A definition rejected for another reason (e.g.
            // unknown_metric) never "claims" its name — a later definition
            // may still legitimately use it.
            if (isset($seenNames[$validated['name']])) {
                $rejected[] = ['name' => $validated['name'], 'reason' => 'duplicate_name'];

                continue;
            }

            $seenNames[$validated['name']] = true;

            $metrics[] = $this->compute($validated, $dimensions[$validated['group_by']]);
        }

        return [
            'metrics' => $metrics,
            'rejected' => $rejected,
        ];
    }

    /**
     * @param array<string, mixed> $aggregatedMetrics
     * @return array<string, array<string, mixed>> dimension name => its full aggregated_metrics.dimensions[] entry
     */
    private function indexDimensions(array $aggregatedMetrics): array
    {
        $index = [];

        foreach ($aggregatedMetrics['dimensions'] ?? [] as $dimension) {
            if (is_array($dimension) && is_string($dimension['dimension'] ?? null)) {
                $index[$dimension['dimension']] = $dimension;
            }
        }

        return $index;
    }

    /**
     * Validate one raw, untrusted CalculationDefinition.
     *
     * @param mixed $raw
     * @param array<string, true> $measures
     * @param array<string, array<string, mixed>> $dimensions
     * @return array{name: string, operator: string, left: array{metric: string, aggregation: string}, right: array{metric: string, aggregation: string}, group_by: string}|null
     */
    private function validate(mixed $raw, array $measures, array $dimensions): ?array
    {
        if (! is_array($raw)) {
            return $this->reject('invalid_definition');
        }

        $name = $raw['name'] ?? null;

        if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return $this->reject('invalid_name');
        }

        $operator = $raw['operator'] ?? null;

        if (! is_string($operator) || ! in_array($operator, self::ALLOWED_OPERATORS, true)) {
            return $this->reject('invalid_operator');
        }

        $groupBy = $raw['group_by'] ?? null;

        if (! is_string($groupBy) || $groupBy === '') {
            return $this->reject('missing_group_by');
        }

        if (! array_key_exists($groupBy, $dimensions)) {
            return $this->reject('unknown_group_by');
        }

        $left = $this->validateOperand($raw['left'] ?? null, $measures);

        if ($left === null) {
            return $this->reject($this->lastRejectionReason);
        }

        $right = $this->validateOperand($raw['right'] ?? null, $measures);

        if ($right === null) {
            return $this->reject($this->lastRejectionReason);
        }

        return [
            'name' => $name,
            'operator' => $operator,
            'left' => $left,
            'right' => $right,
            'group_by' => $groupBy,
        ];
    }

    /**
     * @param mixed $operand
     * @param array<string, true> $measures
     * @return array{metric: string, aggregation: string}|null
     */
    private function validateOperand(mixed $operand, array $measures): ?array
    {
        if (! is_array($operand)) {
            $this->lastRejectionReason = 'invalid_operand';

            return null;
        }

        $metric = $operand['metric'] ?? null;

        if (! is_string($metric) || $metric === '') {
            $this->lastRejectionReason = 'invalid_operand';

            return null;
        }

        if (! array_key_exists($metric, $measures)) {
            $this->lastRejectionReason = 'unknown_metric';

            return null;
        }

        $aggregation = $operand['aggregation'] ?? null;

        if (! is_string($aggregation) || ! in_array($aggregation, self::ALLOWED_AGGREGATIONS, true)) {
            $this->lastRejectionReason = 'invalid_aggregation';

            return null;
        }

        return ['metric' => $metric, 'aggregation' => $aggregation];
    }

    /**
     * @return null
     */
    private function reject(string $reason): mixed
    {
        $this->lastRejectionReason = $reason;

        return null;
    }

    /**
     * Best-effort name for a rejected definition, for readability in the
     * "rejected" output. Never throws, regardless of how malformed $raw is.
     */
    private function rejectionName(mixed $raw): string
    {
        $name = is_array($raw) ? ($raw['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : '(unnamed)';
    }

    /**
     * Compute one validated CalculationDefinition's result for every group
     * of its group_by dimension.
     *
     * @param array{name: string, operator: string, left: array{metric: string, aggregation: string}, right: array{metric: string, aggregation: string}, group_by: string} $definition
     * @param array<string, mixed> $dimension the aggregated_metrics.dimensions[] entry for $definition['group_by']
     * @return array<string, mixed>
     */
    private function compute(array $definition, array $dimension): array
    {
        $groups = [];

        foreach ($dimension['groups'] ?? [] as $group) {
            $groups[] = $this->computeGroup($definition, $group);
        }

        return [
            'name' => $definition['name'],
            'operator' => $definition['operator'],
            'left' => $definition['left'],
            'right' => $definition['right'],
            'group_by' => $definition['group_by'],
            'groups' => $groups,
        ];
    }

    /**
     * @param array{operator: string, left: array{metric: string, aggregation: string}, right: array{metric: string, aggregation: string}} $definition
     * @param array<string, mixed> $group one aggregated_metrics.dimensions[].groups[] entry
     * @return array<string, mixed>
     */
    private function computeGroup(array $definition, array $group): array
    {
        $value = (string) ($group['value'] ?? '');

        $left = $this->resolveOperand($definition['left'], $group);
        $right = $this->resolveOperand($definition['right'], $group);

        // Never treat a missing operand as 0 — an unknown quantity is not
        // the same as zero (see docs/product/DERIVED_METRICS.md "NULL
        // handling").
        if ($left === null || $right === null) {
            return ['value' => $value, 'result' => null, 'reason' => 'missing_operand'];
        }

        if (in_array($definition['operator'], self::DIVIDING_OPERATORS, true) && $right == 0) {
            return ['value' => $value, 'result' => null, 'reason' => 'division_by_zero'];
        }

        $result = match ($definition['operator']) {
            'add' => $left + $right,
            'subtract' => $left - $right,
            'multiply' => $left * $right,
            'divide' => $left / $right,
            'percentage' => ($left / $right) * 100,
        };

        return ['value' => $value, 'result' => $result];
    }

    /**
     * @param array{metric: string, aggregation: string} $operand
     * @param array<string, mixed> $group
     * @return int|float|null
     */
    private function resolveOperand(array $operand, array $group): int|float|null
    {
        $metrics = $group['metrics'] ?? [];

        if (! is_array($metrics)) {
            return null;
        }

        $metric = $metrics[$operand['metric']] ?? null;

        if (! is_array($metric)) {
            return null;
        }

        $value = $metric[$operand['aggregation']] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }
}
