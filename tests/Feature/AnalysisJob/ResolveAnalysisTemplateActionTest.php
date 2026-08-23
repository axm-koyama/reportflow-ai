<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\ResolveAnalysisTemplateAction;
use App\AI\AiAnalysisClient;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ResolveAnalysisTemplateActionTest extends TestCase
{
    /**
     * A Data Profile shaped like DataProfilingAction's real output for a
     * CSV with columns: 媒体(string), 広告コスト(integer), 売上金額(integer),
     * CV数(integer).
     *
     * @return array<string, mixed>
     */
    private function dataProfile(): array
    {
        return [
            'file' => ['name' => 'sample.csv', 'row_count' => 5, 'column_count' => 4],
            'columns' => [
                ['name' => '媒体', 'inferred_type' => 'string', 'non_null_count' => 5, 'null_count' => 0, 'unique_count' => 2],
                ['name' => '広告コスト', 'inferred_type' => 'integer', 'non_null_count' => 5, 'null_count' => 0, 'unique_count' => 5],
                ['name' => '売上金額', 'inferred_type' => 'integer', 'non_null_count' => 5, 'null_count' => 0, 'unique_count' => 5],
                ['name' => 'CV数', 'inferred_type' => 'integer', 'non_null_count' => 5, 'null_count' => 0, 'unique_count' => 4],
            ],
            'numeric_statistics' => [],
            'categorical_summaries' => [],
            'sample_rows' => [
                ['媒体' => 'Email', '広告コスト' => '1000', '売上金額' => '5000', 'CV数' => '10'],
                ['媒体' => 'Paid Search', '広告コスト' => '2000', '売上金額' => '9000', 'CV数' => '15'],
            ],
        ];
    }

    private function mockMapColumnsToReturn(array $mappings): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => $mappings], JSON_THROW_ON_ERROR));
    }

    /**
     * A Data Profile shaped like DataProfilingAction's real output for a
     * Japanese sales CSV with columns: 日付(date), 商品名(string),
     * 分類(string), 店舗名(string), 地域(string), 販売数量(integer),
     * 注文件数(integer), 売上金額(integer) — the Phase 3-B E2E "Case C" shape
     * (docs/product/ANALYSIS_TEMPLATE_MODULE.md §14).
     *
     * @return array<string, mixed>
     */
    private function salesDataProfile(): array
    {
        return [
            'file' => ['name' => 'sales.csv', 'row_count' => 4, 'column_count' => 8],
            'columns' => [
                ['name' => '日付', 'inferred_type' => 'date', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 4],
                ['name' => '商品名', 'inferred_type' => 'string', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 3],
                ['name' => '分類', 'inferred_type' => 'string', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 2],
                ['name' => '店舗名', 'inferred_type' => 'string', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 2],
                ['name' => '地域', 'inferred_type' => 'string', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 2],
                ['name' => '販売数量', 'inferred_type' => 'integer', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 4],
                ['name' => '注文件数', 'inferred_type' => 'integer', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 4],
                ['name' => '売上金額', 'inferred_type' => 'integer', 'non_null_count' => 4, 'null_count' => 0, 'unique_count' => 4],
            ],
            'numeric_statistics' => [],
            'categorical_summaries' => [],
            'sample_rows' => [
                ['日付' => '2026-01-01', '商品名' => '商品A', '分類' => '食品', '店舗名' => '渋谷店', '地域' => '関東', '販売数量' => '10', '注文件数' => '8', '売上金額' => '50000'],
                ['日付' => '2026-01-02', '商品名' => '商品B', '分類' => '雑貨', '店舗名' => '大阪店', '地域' => '関西', '販売数量' => '5', '注文件数' => '4', '売上金額' => '30000'],
            ],
        ];
    }

    public function test_it_throws_for_an_unknown_template_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ResolveAnalysisTemplateAction::class)->execute('not_a_real_template', '', $this->dataProfile());
    }

    /**
     * Normal mapping: all required fields resolve at high confidence.
     */
    public function test_normal_mapping_returns_the_resolved_template_and_column_mapping(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'CV数', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $this->dataProfile());

        $this->assertSame('広告パフォーマンス分析', $result['analysis_template']['name']);
        $this->assertIsString($result['analysis_template']['instruction']);
        $this->assertNotSame('', $result['analysis_template']['instruction']);
        $this->assertIsArray($result['analysis_template']['recommended_derived_metrics']);
        $this->assertNotSame([], $result['analysis_template']['recommended_derived_metrics']);

        // analysis_template does not leak Laravel-only concerns to the AI.
        $this->assertArrayNotHasKey('required_fields', $result['analysis_template']);
        $this->assertArrayNotHasKey('fields', $result['analysis_template']);

        $this->assertSame([
            'channel' => '媒体',
            'spend' => '広告コスト',
            'revenue' => '売上金額',
            'conversions' => 'CV数',
        ], $result['column_mapping']);

        $this->assertSame('mapped', $result['column_mapping_for_storage']['channel']['status']);
        $this->assertSame('媒体', $result['column_mapping_for_storage']['channel']['column']);
        $this->assertSame('high', $result['column_mapping_for_storage']['channel']['confidence']);

        // Fields with zero column_candidates (e.g. no date-typed column
        // exists in this CSV) are still present in the storage record.
        $this->assertArrayHasKey('date', $result['column_mapping_for_storage']);
        $this->assertSame('unmapped', $result['column_mapping_for_storage']['date']['status']);
    }

    /**
     * Required field ("channel") missing -> exception with a
     * business-readable message, no special status introduced.
     */
    public function test_required_field_missing_throws_a_business_readable_exception(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
        ]);

        try {
            app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $this->dataProfile());
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('広告パフォーマンス分析', $exception->getMessage());
            $this->assertStringContainsString('チャネル', $exception->getMessage());
        }
    }

    /**
     * Required field group (spend/revenue/conversions/clicks/impressions)
     * entirely unmapped -> exception.
     */
    public function test_required_field_group_entirely_missing_throws_a_business_readable_exception(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
        ]);

        try {
            app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $this->dataProfile());
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('広告費', $exception->getMessage());
        }
    }

    /**
     * A required field ("channel") that becomes ambiguous (two fields
     * both claim its only high-confidence candidate column) also throws.
     */
    public function test_a_required_field_becoming_ambiguous_throws(): void
    {
        // Both "channel" and "campaign" claim "媒体" at high confidence.
        $this->mockMapColumnsToReturn([
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'campaign', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
        ]);

        $this->expectException(RuntimeException::class);

        app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $this->dataProfile());
    }

    /**
     * Regression for the E2E-observed bug: "impressions" is unmapped, so
     * the "click_through_rate" (clicks/impressions) and "conversion_rate"
     * (conversions/clicks) hints must be dropped before Planning AI ever
     * sees them — rather than trusting Planning AI to ignore them itself,
     * which it was observed not to do reliably (it silently substituted
     * an unrelated mapped measure while keeping the original hint name).
     * Hints whose fields are both mapped ("return_on_ad_spend",
     * "cost_per_conversion") must still pass through unchanged.
     */
    public function test_recommendations_referencing_an_unmapped_field_are_filtered_out(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'CV数', 'confidence' => 'high'],
            // "clicks" and "impressions" are never proposed at all — the
            // CSV in dataProfile() has no more integer-typed columns for
            // the AI to pick from.
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $this->dataProfile());

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertContains('return_on_ad_spend', $names);
        $this->assertContains('cost_per_conversion', $names);
        $this->assertNotContains('conversion_rate', $names);
        $this->assertNotContains('click_through_rate', $names);
    }

    /**
     * A recommendation whose field mapping exists but was demoted to
     * "ignored" (low confidence, or lost a duplicate-column tie-break)
     * must be filtered out exactly like "unmapped" — "mapped" is the only
     * status that counts as usable.
     */
    public function test_recommendations_referencing_an_ignored_field_are_filtered_out(): void
    {
        $dataProfile = $this->dataProfile();
        // Add a second integer column so "conversions" has a low-confidence
        // duplicate claim to lose against, landing it on "ignored" rather
        // than "unmapped".
        $dataProfile['columns'][] = ['name' => 'CV数2', 'inferred_type' => 'integer', 'non_null_count' => 5, 'null_count' => 0, 'unique_count' => 4];

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => [
                ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
                ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
                ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
                ['field' => 'conversions', 'column' => 'CV数', 'confidence' => 'low'],
                ['field' => 'clicks', 'column' => 'CV数', 'confidence' => 'high'],
            ]], JSON_THROW_ON_ERROR));

        $result = app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $dataProfile);

        $this->assertSame('ignored', $result['column_mapping_for_storage']['conversions']['status']);

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertNotContains('cost_per_conversion', $names); // needs conversions (ignored)
        $this->assertNotContains('conversion_rate', $names); // needs conversions (ignored)
        $this->assertContains('return_on_ad_spend', $names); // spend/revenue unaffected
    }

    /**
     * A recommendation referencing an "ambiguous" field (two fields both
     * claim the same column at high confidence) must be filtered out
     * exactly like "unmapped"/"ignored" — distinct from
     * ResolveAnalysisTemplateActionTest::test_a_required_field_becoming_ambiguous_throws(),
     * which covers a *required* field becoming ambiguous (job fails
     * outright). Here "conversions"/"clicks" are optional
     * required_field_group members, so ambiguity between them does not
     * fail the job (the group is still satisfied via "spend"/"revenue")
     * — it only takes recommendations depending on them out of the
     * Planning Context, which is the behavior under test.
     */
    public function test_recommendations_referencing_an_ambiguous_optional_field_are_filtered_out(): void
    {
        $dataProfile = $this->dataProfile();
        // A second integer column lets "conversions" and "clicks" both
        // claim the same column at "high" confidence.
        $dataProfile['columns'][] = ['name' => 'CV数2', 'inferred_type' => 'integer', 'non_null_count' => 5, 'null_count' => 0, 'unique_count' => 4];

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => [
                ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
                ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
                ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
                ['field' => 'conversions', 'column' => 'CV数', 'confidence' => 'high'],
                ['field' => 'clicks', 'column' => 'CV数', 'confidence' => 'high'],
            ]], JSON_THROW_ON_ERROR));

        $result = app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $dataProfile);

        // Confirms this test actually exercises the "ambiguous" status
        // (not "unmapped"/"ignored") and that the AnalysisJob does not
        // fail — "channel" is resolved and the required_field_group is
        // satisfied via "spend"/"revenue", independently of the
        // conversions/clicks ambiguity.
        $this->assertSame('ambiguous', $result['column_mapping_for_storage']['conversions']['status']);
        $this->assertSame('ambiguous', $result['column_mapping_for_storage']['clicks']['status']);

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertNotContains('cost_per_conversion', $names); // needs conversions (ambiguous)
        $this->assertNotContains('conversion_rate', $names); // needs conversions + clicks (both ambiguous)
        $this->assertNotContains('click_through_rate', $names); // needs clicks (ambiguous)
        $this->assertContains('return_on_ad_spend', $names); // spend/revenue unaffected by the ambiguity
    }

    /**
     * Column candidate filtering: dimension/measure/temporal kinds are
     * matched purely by inferred_type, never by column name.
     */
    public function test_column_candidates_are_filtered_by_inferred_type_not_by_name(): void
    {
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(json_encode(['mappings' => [
                ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
                ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
            ]], JSON_THROW_ON_ERROR));

        app(ResolveAnalysisTemplateAction::class)->execute('ad_performance', '', $this->dataProfile());

        // "channel" (dimension) only gets the string-typed column as a candidate.
        $this->assertSame(['媒体'], array_column($capturedContext['column_candidates']['channel'], 'column'));

        // "spend" (measure) gets all 3 integer-typed columns as candidates
        // — filtering never uses column names like "広告コスト"/"売上金額"/"CV数".
        $this->assertEqualsCanonicalizing(
            ['広告コスト', '売上金額', 'CV数'],
            array_column($capturedContext['column_candidates']['spend'], 'column'),
        );

        // "date" (temporal) has no matching column in this CSV at all.
        $this->assertSame([], $capturedContext['column_candidates']['date']);
    }

    // --- sales_analysis (Phase 3-B) ------------------------------------

    /**
     * "revenue" is the only required field: an AnalysisJob succeeds even
     * when every other semantic field (quantity/orders/product/category/
     * store/region/customer/date) is never proposed at all.
     */
    public function test_sales_analysis_revenue_only_required_succeeds_with_everything_else_unmapped(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $this->assertSame('mapped', $result['column_mapping_for_storage']['revenue']['status']);

        foreach (['quantity', 'orders', 'product', 'category', 'store', 'region', 'customer', 'date'] as $optionalField) {
            $this->assertSame('unmapped', $result['column_mapping_for_storage'][$optionalField]['status']);
        }
    }

    /**
     * Japanese column names are mapped by meaning across all 9 semantic
     * fields at once, including the 4 same-type ("string") dimension
     * fields (product/category/store/region — see
     * docs/product/ANALYSIS_TEMPLATE_MODULE.md §14 "5 dimension field"
     * risk note) sharing the exact same column_candidates pool. This
     * exercises ValidateColumnMappingAction's deterministic acceptance of
     * an AI proposal that already disambiguated them correctly; it does
     * not itself prove the AI can reliably do so (see the real-API E2E).
     */
    public function test_sales_analysis_maps_japanese_columns_by_meaning(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'quantity', 'column' => '販売数量', 'confidence' => 'high'],
            ['field' => 'orders', 'column' => '注文件数', 'confidence' => 'high'],
            ['field' => 'product', 'column' => '商品名', 'confidence' => 'high'],
            ['field' => 'category', 'column' => '分類', 'confidence' => 'high'],
            ['field' => 'store', 'column' => '店舗名', 'confidence' => 'high'],
            ['field' => 'region', 'column' => '地域', 'confidence' => 'high'],
            ['field' => 'date', 'column' => '日付', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $this->assertSame([
            'revenue' => '売上金額',
            'quantity' => '販売数量',
            'orders' => '注文件数',
            'product' => '商品名',
            'category' => '分類',
            'store' => '店舗名',
            'region' => '地域',
            'date' => '日付',
        ], $result['column_mapping']);
    }

    /**
     * average_unit_price (revenue/quantity) survives filtering when
     * "quantity" is mapped.
     */
    public function test_sales_analysis_average_unit_price_survives_when_quantity_is_mapped(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'quantity', 'column' => '販売数量', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertContains('average_unit_price', $names);
    }

    /**
     * average_order_value (revenue/orders) survives filtering when
     * "orders" is mapped.
     */
    public function test_sales_analysis_average_order_value_survives_when_orders_is_mapped(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'orders', 'column' => '注文件数', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertContains('average_order_value', $names);
    }

    /**
     * "quantity" unmapped -> average_unit_price (revenue/quantity) is
     * filtered out, while average_order_value (revenue/orders, both
     * mapped) is unaffected.
     */
    public function test_sales_analysis_average_unit_price_is_filtered_out_when_quantity_is_unmapped(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'orders', 'column' => '注文件数', 'confidence' => 'high'],
            // "quantity" is never proposed -> stays unmapped.
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertNotContains('average_unit_price', $names);
        $this->assertContains('average_order_value', $names);
    }

    /**
     * "orders" unmapped -> average_order_value (revenue/orders) is
     * filtered out, while average_unit_price (revenue/quantity, both
     * mapped) is unaffected.
     */
    public function test_sales_analysis_average_order_value_is_filtered_out_when_orders_is_unmapped(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'quantity', 'column' => '販売数量', 'confidence' => 'high'],
            // "orders" is never proposed -> stays unmapped.
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $names = array_column($result['analysis_template']['recommended_derived_metrics'], 'name');

        $this->assertContains('average_unit_price', $names);
        $this->assertNotContains('average_order_value', $names);
    }

    /**
     * Both "quantity" and "orders" unmapped -> both recommended hints are
     * filtered out, leaving an empty list — this does not fail the
     * AnalysisJob (see ExecuteAnalysisJobActionTest for the full-pipeline
     * confirmation that Planning AI is still called normally and the Job
     * still completes).
     */
    public function test_sales_analysis_recommended_derived_metrics_is_empty_when_quantity_and_orders_are_both_unmapped(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $this->assertSame([], $result['analysis_template']['recommended_derived_metrics']);
    }

    /**
     * "date" is a normal Column Mapping field: it resolves to "mapped"
     * with its real column exactly like any other field. Whether it is
     * usable as an aggregation/group_by dimension is a separate concern
     * this action has no opinion on (see MetricAggregationAction /
     * ExecuteAnalysisJobActionTest for the "date never becomes an
     * aggregated_metrics dimension" confirmation — Phase 3-B §15).
     */
    public function test_sales_analysis_date_is_mapped_as_a_normal_column_mapping_field(): void
    {
        $this->mockMapColumnsToReturn([
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'date', 'column' => '日付', 'confidence' => 'high'],
        ]);

        $result = app(ResolveAnalysisTemplateAction::class)->execute('sales_analysis', '', $this->salesDataProfile());

        $this->assertSame('mapped', $result['column_mapping_for_storage']['date']['status']);
        $this->assertSame('日付', $result['column_mapping_for_storage']['date']['column']);
        $this->assertSame('日付', $result['column_mapping']['date']);
    }
}
