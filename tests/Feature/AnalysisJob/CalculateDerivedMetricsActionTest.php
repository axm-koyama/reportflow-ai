<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\CalculateDerivedMetricsAction;
use Tests\TestCase;

class CalculateDerivedMetricsActionTest extends TestCase
{
    /**
     * A representative MetricAggregationAction-shaped output: a "channel"
     * dimension with two groups, each carrying "revenue" / "spend"
     * measures. Used as the aggregated_metrics fixture across tests.
     *
     * @return array<string, mixed>
     */
    private function aggregatedMetrics(): array
    {
        return [
            'dimensions' => [
                [
                    'dimension' => 'channel',
                    'group_count' => 2,
                    'groups' => [
                        [
                            'value' => 'Email',
                            'count' => 5,
                            'metrics' => [
                                'revenue' => ['sum' => 4500000, 'count' => 5, 'avg' => 900000],
                                'spend' => ['sum' => 110000, 'count' => 5, 'avg' => 22000],
                            ],
                        ],
                        [
                            'value' => 'Display',
                            'count' => 5,
                            'metrics' => [
                                'revenue' => ['sum' => 2550000, 'count' => 5, 'avg' => 510000],
                                'spend' => ['sum' => 800000, 'count' => 5, 'avg' => 160000],
                            ],
                        ],
                    ],
                ],
            ],
            'measures' => ['revenue', 'spend'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function roasDefinition(array $overrides = []): array
    {
        return array_replace([
            'name' => 'ROAS',
            'operator' => 'divide',
            'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
            'right' => ['metric' => 'spend', 'aggregation' => 'sum'],
            'group_by' => 'channel',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $derivedMetrics
     * @return array<string, mixed>
     */
    private function metricGroup(array $derivedMetrics, string $metricName, string $groupValue): array
    {
        foreach ($derivedMetrics['metrics'] as $metric) {
            if ($metric['name'] !== $metricName) {
                continue;
            }

            foreach ($metric['groups'] as $group) {
                if ($group['value'] === $groupValue) {
                    return $group;
                }
            }
        }

        $this->fail("Group \"{$groupValue}\" of metric \"{$metricName}\" not found.");
    }

    // --- Operators -----------------------------------------------------

    public function test_divide_computes_the_correct_result_per_group(): void
    {
        $result = (new CalculateDerivedMetricsAction)->execute(
            [$this->roasDefinition()],
            $this->aggregatedMetrics(),
        );

        $this->assertSame([], $result['rejected']);
        $this->assertEqualsWithDelta(40.909090909091, $this->metricGroup($result, 'ROAS', 'Email')['result'], 0.0001);
        $this->assertEqualsWithDelta(3.1875, $this->metricGroup($result, 'ROAS', 'Display')['result'], 0.0001);
    }

    public function test_multiply_computes_the_correct_result_per_group(): void
    {
        $definition = $this->roasDefinition(['name' => 'Product', 'operator' => 'multiply']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame(4500000 * 110000, $this->metricGroup($result, 'Product', 'Email')['result']);
        $this->assertSame(2550000 * 800000, $this->metricGroup($result, 'Product', 'Display')['result']);
    }

    public function test_add_computes_the_correct_result_per_group(): void
    {
        $definition = $this->roasDefinition(['name' => 'Total', 'operator' => 'add']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame(4500000 + 110000, $this->metricGroup($result, 'Total', 'Email')['result']);
    }

    public function test_subtract_computes_the_correct_result_per_group(): void
    {
        $definition = $this->roasDefinition(['name' => 'Profit', 'operator' => 'subtract']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame(4500000 - 110000, $this->metricGroup($result, 'Profit', 'Email')['result']);
        $this->assertSame(2550000 - 800000, $this->metricGroup($result, 'Profit', 'Display')['result']);
    }

    public function test_percentage_computes_left_divided_by_right_times_100(): void
    {
        $definition = $this->roasDefinition(['name' => 'RevenueShare', 'operator' => 'percentage']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        // (4500000 / 110000) * 100
        $this->assertEqualsWithDelta(4090.909090909091, $this->metricGroup($result, 'RevenueShare', 'Email')['result'], 0.0001);
    }

    // --- Zero division ---------------------------------------------------

    public function test_divide_by_zero_returns_null_with_reason_instead_of_throwing(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['spend'] = ['sum' => 0, 'count' => 0, 'avg' => null];

        $result = (new CalculateDerivedMetricsAction)->execute([$this->roasDefinition()], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'ROAS', 'Email');
        $this->assertNull($group['result']);
        $this->assertSame('division_by_zero', $group['reason']);

        // The other group, unaffected, still computes normally.
        $this->assertArrayNotHasKey('reason', $this->metricGroup($result, 'ROAS', 'Display'));
    }

    public function test_percentage_by_zero_returns_null_with_reason(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['spend'] = ['sum' => 0, 'count' => 0, 'avg' => null];

        $definition = $this->roasDefinition(['operator' => 'percentage']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'ROAS', 'Email');
        $this->assertNull($group['result']);
        $this->assertSame('division_by_zero', $group['reason']);
    }

    public function test_add_by_zero_is_not_treated_as_division_by_zero(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['spend'] = ['sum' => 0, 'count' => 5, 'avg' => 0];

        $definition = $this->roasDefinition(['name' => 'Total', 'operator' => 'add']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'Total', 'Email');
        $this->assertSame(4500000, $group['result']);
        $this->assertArrayNotHasKey('reason', $group);
    }

    // --- NULL operand ----------------------------------------------------

    public function test_missing_operand_returns_null_with_reason_instead_of_treating_it_as_zero(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        // count = 0 => avg is null, matching MetricAggregationAction's own convention.
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['spend'] = ['sum' => 0, 'count' => 0, 'avg' => null];

        $definition = $this->roasDefinition([
            'right' => ['metric' => 'spend', 'aggregation' => 'avg'],
        ]);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'ROAS', 'Email');
        $this->assertNull($group['result']);
        $this->assertSame('missing_operand', $group['reason']);
    }

    /**
     * Defensive guard in resolveOperand(): if a group's per-measure entry
     * is not itself an array (an unexpected internal shape, since
     * aggregated_metrics is normally well-formed), the operand resolves to
     * null (missing_operand) instead of raising a TypeError/exception.
     */
    public function test_a_non_array_metric_entry_resolves_to_missing_operand_instead_of_throwing(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['spend'] = 'not-an-array';

        $result = (new CalculateDerivedMetricsAction)->execute([$this->roasDefinition()], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'ROAS', 'Email');
        $this->assertNull($group['result']);
        $this->assertSame('missing_operand', $group['reason']);

        // The unaffected group still computes normally.
        $this->assertArrayNotHasKey('reason', $this->metricGroup($result, 'ROAS', 'Display'));
    }

    // --- Unknown metric / aggregation / group_by -------------------------

    public function test_unknown_metric_is_rejected_with_reason(): void
    {
        $definition = $this->roasDefinition([
            'left' => ['metric' => 'profit', 'aggregation' => 'sum'],
        ]);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame([['name' => 'ROAS', 'reason' => 'unknown_metric']], $result['rejected']);
    }

    public function test_unknown_aggregation_is_rejected_with_reason(): void
    {
        $definition = $this->roasDefinition([
            'left' => ['metric' => 'revenue', 'aggregation' => 'median'],
        ]);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame([['name' => 'ROAS', 'reason' => 'invalid_aggregation']], $result['rejected']);
    }

    public function test_unknown_group_by_is_rejected_with_reason(): void
    {
        $definition = $this->roasDefinition(['group_by' => 'region']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame([['name' => 'ROAS', 'reason' => 'unknown_group_by']], $result['rejected']);
    }

    public function test_null_group_by_is_rejected_and_never_treated_as_grand_total(): void
    {
        $definition = $this->roasDefinition(['group_by' => null]);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame([['name' => 'ROAS', 'reason' => 'missing_group_by']], $result['rejected']);
    }

    public function test_missing_group_by_key_is_rejected(): void
    {
        $definition = $this->roasDefinition();
        unset($definition['group_by']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([['name' => 'ROAS', 'reason' => 'missing_group_by']], $result['rejected']);
    }

    // --- Invalid operator --------------------------------------------------

    public function test_invalid_operator_is_rejected_with_reason(): void
    {
        $definition = $this->roasDefinition(['operator' => 'modulo']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame([['name' => 'ROAS', 'reason' => 'invalid_operator']], $result['rejected']);
    }

    /**
     * "eval" / "expression parser" style values must be rejected just like
     * any other unknown operator string — never executed.
     */
    public function test_a_formula_string_operator_is_rejected_not_executed(): void
    {
        $definition = $this->roasDefinition(['operator' => 'revenue / spend']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame('invalid_operator', $result['rejected'][0]['reason']);
    }

    // --- Invalid name --------------------------------------------------

    public function test_a_name_with_symbols_is_rejected(): void
    {
        $definition = $this->roasDefinition(['name' => 'ROAS!!']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame([], $result['metrics']);
        $this->assertSame('invalid_name', $result['rejected'][0]['reason']);
    }

    public function test_an_empty_name_is_rejected(): void
    {
        $definition = $this->roasDefinition(['name' => '']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame('invalid_name', $result['rejected'][0]['reason']);
    }

    public function test_a_name_starting_with_a_digit_is_rejected(): void
    {
        $definition = $this->roasDefinition(['name' => '1ROAS']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $this->aggregatedMetrics());

        $this->assertSame('invalid_name', $result['rejected'][0]['reason']);
    }

    // --- Partial validity -------------------------------------------------

    public function test_one_invalid_definition_does_not_fail_the_whole_batch(): void
    {
        $valid = $this->roasDefinition();
        $invalid = $this->roasDefinition(['name' => 'Bogus', 'left' => ['metric' => 'profit', 'aggregation' => 'sum']]);

        $result = (new CalculateDerivedMetricsAction)->execute([$invalid, $valid], $this->aggregatedMetrics());

        $this->assertCount(1, $result['metrics']);
        $this->assertSame('ROAS', $result['metrics'][0]['name']);
        $this->assertSame([['name' => 'Bogus', 'reason' => 'unknown_metric']], $result['rejected']);
    }

    // --- Duplicate name --------------------------------------------------

    public function test_the_first_definition_using_a_name_is_accepted(): void
    {
        $first = $this->roasDefinition(['name' => 'revenue_per_spend']);
        $second = $this->roasDefinition(['name' => 'revenue_per_spend', 'operator' => 'multiply']);

        $result = (new CalculateDerivedMetricsAction)->execute([$first, $second], $this->aggregatedMetrics());

        $this->assertCount(1, $result['metrics']);
        // The first definition's operator ("divide") is the one that won.
        $this->assertSame('divide', $result['metrics'][0]['operator']);
    }

    public function test_a_later_definition_reusing_a_name_is_rejected_as_duplicate(): void
    {
        $first = $this->roasDefinition(['name' => 'revenue_per_spend']);
        $second = $this->roasDefinition(['name' => 'revenue_per_spend', 'operator' => 'multiply']);

        $result = (new CalculateDerivedMetricsAction)->execute([$first, $second], $this->aggregatedMetrics());

        $this->assertSame(
            [['name' => 'revenue_per_spend', 'reason' => 'duplicate_name']],
            $result['rejected'],
        );
    }

    /**
     * A third, unrelated, independently valid definition must still be
     * computed normally even though an earlier duplicate was rejected.
     */
    public function test_other_valid_definitions_are_still_computed_alongside_a_duplicate(): void
    {
        $first = $this->roasDefinition(['name' => 'revenue_per_spend']);
        $duplicate = $this->roasDefinition(['name' => 'revenue_per_spend', 'operator' => 'multiply']);
        $other = $this->roasDefinition(['name' => 'conversions_per_spend', 'left' => ['metric' => 'revenue', 'aggregation' => 'avg']]);

        $result = (new CalculateDerivedMetricsAction)->execute([$first, $duplicate, $other], $this->aggregatedMetrics());

        $this->assertSame(['revenue_per_spend', 'conversions_per_spend'], array_column($result['metrics'], 'name'));
        $this->assertSame(
            [['name' => 'revenue_per_spend', 'reason' => 'duplicate_name']],
            $result['rejected'],
        );
    }

    /**
     * A definition rejected for another reason never "claims" its name — a
     * later definition may still legitimately use it.
     */
    public function test_a_name_rejected_for_another_reason_does_not_block_a_later_valid_use_of_that_name(): void
    {
        $invalid = $this->roasDefinition(['name' => 'revenue_per_spend', 'left' => ['metric' => 'profit', 'aggregation' => 'sum']]);
        $valid = $this->roasDefinition(['name' => 'revenue_per_spend']);

        $result = (new CalculateDerivedMetricsAction)->execute([$invalid, $valid], $this->aggregatedMetrics());

        $this->assertCount(1, $result['metrics']);
        $this->assertSame('revenue_per_spend', $result['metrics'][0]['name']);
        $this->assertSame(
            [['name' => 'revenue_per_spend', 'reason' => 'unknown_metric']],
            $result['rejected'],
        );
    }

    // --- max_derived_metrics -------------------------------------------

    public function test_definitions_beyond_the_configured_limit_are_rejected_not_thrown(): void
    {
        config(['derived_metrics.max_derived_metrics' => 2]);

        $definitions = [
            $this->roasDefinition(['name' => 'Metric1']),
            $this->roasDefinition(['name' => 'Metric2']),
            $this->roasDefinition(['name' => 'Metric3']),
            $this->roasDefinition(['name' => 'Metric4']),
        ];

        $result = (new CalculateDerivedMetricsAction)->execute($definitions, $this->aggregatedMetrics());

        $this->assertSame(['Metric1', 'Metric2'], array_column($result['metrics'], 'name'));
        $this->assertSame(
            [
                ['name' => 'Metric3', 'reason' => 'limit_exceeded'],
                ['name' => 'Metric4', 'reason' => 'limit_exceeded'],
            ],
            $result['rejected'],
        );
    }

    public function test_default_max_derived_metrics_is_five(): void
    {
        $this->assertSame(5, config('derived_metrics.max_derived_metrics'));
    }

    // --- Multiple groups --------------------------------------------------

    public function test_a_definition_produces_one_result_per_group_in_its_dimension(): void
    {
        $result = (new CalculateDerivedMetricsAction)->execute([$this->roasDefinition()], $this->aggregatedMetrics());

        $this->assertCount(2, $result['metrics'][0]['groups']);
        $this->assertSame(['Email', 'Display'], array_column($result['metrics'][0]['groups'], 'value'));
    }

    // --- Decimal results --------------------------------------------------

    public function test_a_non_evenly_divisible_result_keeps_decimal_precision(): void
    {
        $result = (new CalculateDerivedMetricsAction)->execute([$this->roasDefinition()], $this->aggregatedMetrics());

        // 2550000 / 800000 = 3.1875 exactly.
        $this->assertSame(3.1875, $this->metricGroup($result, 'ROAS', 'Display')['result']);
    }

    // --- Zero as a valid operand -------------------------------------------

    public function test_zero_is_a_valid_operand_not_treated_as_missing(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['revenue'] = ['sum' => 0, 'count' => 5, 'avg' => 0];

        $definition = $this->roasDefinition(['name' => 'Total', 'operator' => 'add']);

        $result = (new CalculateDerivedMetricsAction)->execute([$definition], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'Total', 'Email');
        // 0 + 110000, not treated as "missing" just because the left side is 0.
        $this->assertSame(110000, $group['result']);
        $this->assertArrayNotHasKey('reason', $group);
    }

    public function test_zero_as_the_left_operand_of_divide_is_a_valid_zero_result(): void
    {
        $aggregatedMetrics = $this->aggregatedMetrics();
        $aggregatedMetrics['dimensions'][0]['groups'][0]['metrics']['revenue'] = ['sum' => 0, 'count' => 5, 'avg' => 0];

        $result = (new CalculateDerivedMetricsAction)->execute([$this->roasDefinition()], $aggregatedMetrics);

        $group = $this->metricGroup($result, 'ROAS', 'Email');
        $this->assertSame(0, $group['result']);
        $this->assertArrayNotHasKey('reason', $group);
    }

    // --- Traceability / output shape ---------------------------------------

    public function test_a_computed_metric_retains_its_left_right_and_group_by_for_traceability(): void
    {
        $result = (new CalculateDerivedMetricsAction)->execute([$this->roasDefinition()], $this->aggregatedMetrics());

        $metric = $result['metrics'][0];
        $this->assertSame('divide', $metric['operator']);
        $this->assertSame('channel', $metric['group_by']);
        $this->assertSame(['metric' => 'revenue', 'aggregation' => 'sum'], $metric['left']);
        $this->assertSame(['metric' => 'spend', 'aggregation' => 'sum'], $metric['right']);
    }

    // --- Empty input --------------------------------------------------

    public function test_an_empty_definition_list_returns_an_empty_valid_structure(): void
    {
        $result = (new CalculateDerivedMetricsAction)->execute([], $this->aggregatedMetrics());

        $this->assertSame(['metrics' => [], 'rejected' => []], $result);
    }

    // --- No eval / no dynamic dispatch (structural safety net) -----------

    /**
     * A defensive, structural guard against reintroducing eval() or an
     * expression parser: the class must never reference either.
     */
    public function test_the_action_never_references_eval_or_an_expression_parser(): void
    {
        $reflection = new \ReflectionClass(CalculateDerivedMetricsAction::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('eval(', $source);
        $this->assertStringNotContainsString('call_user_func', $source);
        $this->assertStringNotContainsString('->{$', $source);
    }
}
