<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\AnalysisJob;

use App\Actions\AnalysisJob\NormalizeAnalysisResultAction;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class NormalizeAnalysisResultActionTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function execute(string $rawResponse, bool $decisionEnabled = false): array
    {
        return (new NormalizeAnalysisResultAction)->execute($rawResponse, $decisionEnabled);
    }

    public function test_decision_enabled_analysis_strips_valid_legacy_recommendations_and_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'NormalizeAnalysisResultAction: stripped legacy recommendations from a Decision-enabled analysis.',
                ['recommendation_count' => 1],
            );

        $result = $this->execute($this->encode([
            'summary' => 'Summary',
            'recommendations' => [
                ['title' => 'Legacy', 'description' => 'Legacy prose.', 'priority' => 'high'],
            ],
        ]), true);

        $this->assertSame([], $result['recommendations']);
    }

    public function test_non_decision_enabled_analysis_preserves_valid_legacy_recommendations(): void
    {
        Log::shouldReceive('warning')->never();

        $result = $this->execute($this->encode([
            'summary' => 'Summary',
            'recommendations' => [
                ['title' => 'Legacy', 'description' => 'Legacy prose.', 'priority' => 'high'],
            ],
        ]));

        $this->assertCount(1, $result['recommendations']);
    }

    public function test_decision_enabled_analysis_still_rejects_a_malformed_recommendation(): void
    {
        Log::shouldReceive('warning')->never();
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 'Summary',
            'recommendations' => [['title' => 'Missing description']],
        ]), true);
    }

    /**
     * 1. valid full result: 全sectionが揃った正常なJSONをnormalizeできる
     */
    public function test_it_normalizes_a_valid_full_result(): void
    {
        $result = $this->execute($this->encode([
            'summary' => '売上が減少しました',
            'highlights' => ['関東で減少'],
            'metrics' => [
                ['label' => 'Revenue', 'value' => '1000000', 'unit' => 'JPY', 'change' => '-12.4%'],
            ],
            'tables' => [
                ['title' => 'Region', 'columns' => ['region', 'sales'], 'rows' => [['Tokyo', '500000']]],
            ],
            'insights' => [
                ['title' => 'Decline', 'description' => 'Sales declined.', 'evidence' => 'Observed in the table.'],
            ],
            'recommendations' => [
                ['title' => 'Review budget', 'description' => 'Consider reallocating.', 'priority' => 'high'],
            ],
        ]));

        $this->assertSame([
            'summary' => '売上が減少しました',
            'highlights' => ['関東で減少'],
            'metrics' => [
                ['label' => 'Revenue', 'value' => '1000000', 'unit' => 'JPY', 'change' => '-12.4%'],
            ],
            'tables' => [
                ['title' => 'Region', 'columns' => ['region', 'sales'], 'rows' => [['Tokyo', '500000']]],
            ],
            'insights' => [
                ['title' => 'Decline', 'description' => 'Sales declined.', 'evidence' => 'Observed in the table.'],
            ],
            'recommendations' => [
                ['title' => 'Review budget', 'description' => 'Consider reallocating.', 'priority' => 'high'],
            ],
        ], $result);
    }

    /**
     * 2. only summary: summaryのみでも正常にnormalizeでき、optional sectionsは空配列
     */
    public function test_only_summary_normalizes_with_all_optional_sections_empty(): void
    {
        $result = $this->execute($this->encode(['summary' => '売上が減少しました']));

        $this->assertSame('売上が減少しました', $result['summary']);
        $this->assertSame([], $result['highlights']);
        $this->assertSame([], $result['metrics']);
        $this->assertSame([], $result['tables']);
        $this->assertSame([], $result['insights']);
        $this->assertSame([], $result['recommendations']);
    }

    /**
     * 3. missing summary は InvalidArgumentException
     */
    public function test_missing_summary_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['highlights' => []]));
    }

    /**
     * summary が string 以外なら InvalidArgumentException
     */
    public function test_non_string_summary_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['summary' => 123]));
    }

    /**
     * 4. invalid JSON は InvalidArgumentException
     */
    public function test_invalid_json_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute('{not valid json');
    }

    /**
     * トップレベルがscalar（JSON object/arrayではない）場合も
     * InvalidArgumentException
     */
    public function test_scalar_json_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute('"just a string"');
    }

    /**
     * 5. highlights missing -> []
     */
    public function test_missing_highlights_defaults_to_empty_array(): void
    {
        $result = $this->execute($this->encode(['summary' => 's']));

        $this->assertSame([], $result['highlights']);
    }

    /**
     * 6. highlights が array 以外なら InvalidArgumentException
     */
    public function test_non_array_highlights_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['summary' => 's', 'highlights' => 'not an array']));
    }

    /**
     * 7. highlights に string 以外が含まれる場合は InvalidArgumentException
     * (mixed typeをstringへcastしない)
     */
    public function test_non_string_highlight_item_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['summary' => 's', 'highlights' => ['A', 123]]));
    }

    /**
     * 8. valid metrics
     */
    public function test_valid_metrics_are_normalized(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [
                ['label' => 'Revenue', 'value' => '1000', 'unit' => 'JPY', 'change' => '+5%'],
            ],
        ]));

        $this->assertSame(
            [['label' => 'Revenue', 'value' => '1000', 'unit' => 'JPY', 'change' => '+5%']],
            $result['metrics'],
        );
    }

    /**
     * 9. metrics が array 以外なら InvalidArgumentException
     */
    public function test_non_array_metrics_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['summary' => 's', 'metrics' => 'not an array']));
    }

    /**
     * 10. metric item が object(array) 以外なら InvalidArgumentException
     */
    public function test_non_object_metric_item_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['summary' => 's', 'metrics' => ['just a string']]));
    }

    /**
     * 11. metric missing label
     */
    public function test_metric_missing_label_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['value' => '1000']],
        ]));
    }

    /**
     * 12. metric invalid label type
     */
    public function test_metric_invalid_label_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 123, 'value' => '1000']],
        ]));
    }

    /**
     * 13. metric missing value
     */
    public function test_metric_missing_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue']],
        ]));
    }

    /**
     * 14. metric invalid value type (number is not cast to string)
     */
    public function test_metric_invalid_value_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => 1000]],
        ]));
    }

    /**
     * 15. metric unit string
     */
    public function test_metric_unit_string_is_preserved(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'unit' => 'JPY']],
        ]));

        $this->assertSame('JPY', $result['metrics'][0]['unit']);
    }

    /**
     * 16. metric unit null
     */
    public function test_metric_unit_null_is_preserved(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'unit' => null]],
        ]));

        $this->assertNull($result['metrics'][0]['unit']);
    }

    /**
     * metric unit omitted entirely defaults to null (see report: this
     * project's chosen interpretation of the "string|null" contract)
     */
    public function test_metric_missing_unit_defaults_to_null(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000']],
        ]));

        $this->assertNull($result['metrics'][0]['unit']);
    }

    /**
     * 17. metric invalid unit type
     */
    public function test_metric_invalid_unit_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'unit' => 123]],
        ]));
    }

    /**
     * 18. metric change string
     */
    public function test_metric_change_string_is_preserved(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'change' => '+5%']],
        ]));

        $this->assertSame('+5%', $result['metrics'][0]['change']);
    }

    /**
     * 19. metric change null
     */
    public function test_metric_change_null_is_preserved(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'change' => null]],
        ]));

        $this->assertNull($result['metrics'][0]['change']);
    }

    /**
     * 20. metric invalid change type
     */
    public function test_metric_invalid_change_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'change' => 5.0]],
        ]));
    }

    /**
     * 21. valid tables
     */
    public function test_valid_tables_are_normalized(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'tables' => [
                ['title' => 'Region', 'columns' => ['region', 'sales'], 'rows' => [['Tokyo', '500000'], ['Osaka', '300000']]],
            ],
        ]));

        $this->assertSame(
            [['title' => 'Region', 'columns' => ['region', 'sales'], 'rows' => [['Tokyo', '500000'], ['Osaka', '300000']]]],
            $result['tables'],
        );
    }

    /**
     * tables[].rows のcell数はcolumns数と一致する必要がある。
     */
    public function test_table_row_with_too_few_cells_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [[
                'title' => 'Region',
                'columns' => ['region', 'sales'],
                'rows' => [['Tokyo']],
            ]],
        ]));
    }

    /**
     * tables[].rows のcell数がcolumns数を超える場合も拒否する。
     */
    public function test_table_row_with_too_many_cells_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [[
                'title' => 'Region',
                'columns' => ['region'],
                'rows' => [['Tokyo', '500000']],
            ]],
        ]));
    }

    /**
     * columns/rowsがともに空のtableはV1で許可する。
     */
    public function test_empty_table_is_valid(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'tables' => [[
                'title' => 'Empty',
                'columns' => [],
                'rows' => [],
            ]],
        ]));

        $this->assertSame([
            ['title' => 'Empty', 'columns' => [], 'rows' => []],
        ], $result['tables']);
    }

    /**
     * 22. table missing title
     */
    public function test_table_missing_title_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [['columns' => ['a'], 'rows' => [['1']]]],
        ]));
    }

    /**
     * 23. table invalid columns (non-array)
     */
    public function test_table_non_array_columns_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [['title' => 'T', 'columns' => 'not an array', 'rows' => []]],
        ]));
    }

    /**
     * 24. table columns contain non-string
     */
    public function test_table_columns_containing_non_string_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [['title' => 'T', 'columns' => ['a', 123], 'rows' => []]],
        ]));
    }

    /**
     * 25. table invalid rows (non-array)
     */
    public function test_table_non_array_rows_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [['title' => 'T', 'columns' => ['a'], 'rows' => 'not an array']],
        ]));
    }

    /**
     * 26. row is not array
     */
    public function test_table_row_that_is_not_an_array_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [['title' => 'T', 'columns' => ['a'], 'rows' => ['not a row array']]],
        ]));
    }

    /**
     * 27. cell non-string
     */
    public function test_table_cell_non_string_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'tables' => [['title' => 'T', 'columns' => ['a'], 'rows' => [[123]]]],
        ]));
    }

    /**
     * 28. valid insights
     */
    public function test_valid_insights_are_normalized(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'insights' => [
                ['title' => 'Decline', 'description' => 'Sales declined.', 'evidence' => 'See table.'],
            ],
        ]));

        $this->assertSame(
            [['title' => 'Decline', 'description' => 'Sales declined.', 'evidence' => 'See table.']],
            $result['insights'],
        );
    }

    /**
     * 29. insight missing title
     */
    public function test_insight_missing_title_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'insights' => [['description' => 'Sales declined.']],
        ]));
    }

    /**
     * 30. insight missing description
     */
    public function test_insight_missing_description_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'insights' => [['title' => 'Decline']],
        ]));
    }

    /**
     * 31. invalid evidence type
     */
    public function test_insight_invalid_evidence_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'insights' => [['title' => 'Decline', 'description' => 'Sales declined.', 'evidence' => ['not', 'a', 'string']]],
        ]));
    }

    /**
     * insight evidence null / omitted are both accepted (same "string|null"
     * policy as metrics.unit/change; see report)
     */
    public function test_insight_evidence_null_and_omitted_both_normalize_to_null(): void
    {
        $withNull = $this->execute($this->encode([
            'summary' => 's',
            'insights' => [['title' => 'Decline', 'description' => 'Sales declined.', 'evidence' => null]],
        ]));
        $this->assertNull($withNull['insights'][0]['evidence']);

        $withoutKey = $this->execute($this->encode([
            'summary' => 's',
            'insights' => [['title' => 'Decline', 'description' => 'Sales declined.']],
        ]));
        $this->assertNull($withoutKey['insights'][0]['evidence']);
    }

    /**
     * 32. valid recommendations
     */
    public function test_valid_recommendations_are_normalized(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'recommendations' => [
                ['title' => 'Review budget', 'description' => 'Consider reallocating.', 'priority' => 'high'],
            ],
        ]));

        $this->assertSame(
            [['title' => 'Review budget', 'description' => 'Consider reallocating.', 'priority' => 'high']],
            $result['recommendations'],
        );
    }

    /**
     * 33. recommendations missing -> []
     */
    public function test_missing_recommendations_defaults_to_empty_array(): void
    {
        $result = $this->execute($this->encode(['summary' => 's']));

        $this->assertSame([], $result['recommendations']);
    }

    /**
     * recommendations が array 以外なら InvalidArgumentException
     */
    public function test_non_array_recommendations_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode(['summary' => 's', 'recommendations' => 'not an array']));
    }

    /**
     * recommendation missing title / description も InvalidArgumentException
     */
    public function test_recommendation_missing_title_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'recommendations' => [['description' => 'Consider reallocating.', 'priority' => 'high']],
        ]));
    }

    public function test_recommendation_missing_description_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'recommendations' => [['title' => 'Review budget', 'priority' => 'high']],
        ]));
    }

    /**
     * 34. invalid recommendation priority ("urgent" 等の許可値外)
     */
    public function test_invalid_recommendation_priority_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->execute($this->encode([
            'summary' => 's',
            'recommendations' => [['title' => 'T', 'description' => 'D', 'priority' => 'urgent']],
        ]));
    }

    /**
     * 35. priority null
     */
    public function test_recommendation_priority_null_is_preserved(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'recommendations' => [['title' => 'T', 'description' => 'D', 'priority' => null]],
        ]));

        $this->assertNull($result['recommendations'][0]['priority']);
    }

    /**
     * priority omitted entirely also defaults to null
     */
    public function test_recommendation_priority_omitted_defaults_to_null(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'recommendations' => [['title' => 'T', 'description' => 'D']],
        ]));

        $this->assertNull($result['recommendations'][0]['priority']);
    }

    /**
     * 36. priority high / medium / low
     */
    public function test_recommendation_priority_accepts_high_medium_low(): void
    {
        foreach (['high', 'medium', 'low'] as $priority) {
            $result = $this->execute($this->encode([
                'summary' => 's',
                'recommendations' => [['title' => 'T', 'description' => 'D', 'priority' => $priority]],
            ]));

            $this->assertSame($priority, $result['recommendations'][0]['priority']);
        }
    }

    /**
     * 37. normalizer は正常時に必ず6つのtop-level keyを返す
     */
    public function test_execute_returns_exactly_six_top_level_keys(): void
    {
        $result = $this->execute($this->encode(['summary' => 's']));

        $this->assertSame(
            ['summary', 'highlights', 'metrics', 'tables', 'insights', 'recommendations'],
            array_keys($result),
        );
    }

    /**
     * 38. unknown top-level key:
     * V1では未知のtop-level keyはrejectせず無視する。
     * 既知の6key以外は出力に含めず、処理自体は正常に継続する。
     */
    public function test_unknown_top_level_keys_are_ignored_not_rejected(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'foo' => 'bar',
        ]));

        $this->assertSame(
            ['summary', 'highlights', 'metrics', 'tables', 'insights', 'recommendations'],
            array_keys($result),
        );
        $this->assertArrayNotHasKey('foo', $result);
    }

    /**
     * 未知のitem内keyも同様に無視される（label/value/unit/change以外は出力に含めない）
     */
    public function test_unknown_metric_item_keys_are_ignored(): void
    {
        $result = $this->execute($this->encode([
            'summary' => 's',
            'metrics' => [['label' => 'Revenue', 'value' => '1000', 'extra' => 'ignored']],
        ]));

        $this->assertSame(['label', 'value', 'unit', 'change'], array_keys($result['metrics'][0]));
    }
}
