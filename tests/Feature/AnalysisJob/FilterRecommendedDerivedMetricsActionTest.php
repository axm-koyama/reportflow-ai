<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\FilterRecommendedDerivedMetricsAction;
use Tests\TestCase;

/**
 * Direct unit coverage for the filtering logic extracted (Phase 3-C) out
 * of ResolveAnalysisTemplateAction so ExecuteAnalysisJobAction can
 * re-apply it against a confirmed Effective Mapping (not just the
 * AI-validated mapping) on the manual Mapping confirmation path — see
 * docs/product/MAPPING_CONTROL.md. ResolveAnalysisTemplateActionTest's
 * own filtering tests already exercise this indirectly; this file tests
 * the extracted Action directly.
 */
class FilterRecommendedDerivedMetricsActionTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function recommendations(): array
    {
        return [
            ['name' => 'average_unit_price', 'left_field' => 'revenue', 'right_field' => 'quantity', 'operator_hint' => 'divide'],
            ['name' => 'average_order_value', 'left_field' => 'revenue', 'right_field' => 'orders', 'operator_hint' => 'divide'],
        ];
    }

    public function test_keeps_a_recommendation_when_both_fields_are_mapped(): void
    {
        $mapping = [
            'revenue' => ['column' => '売上金額', 'status' => 'mapped'],
            'quantity' => ['column' => '販売数量', 'status' => 'mapped'],
            'orders' => ['column' => null, 'status' => 'unmapped'],
        ];

        $result = app(FilterRecommendedDerivedMetricsAction::class)->execute($this->recommendations(), $mapping);

        $this->assertSame(['average_unit_price'], array_column($result, 'name'));
    }

    public function test_drops_a_recommendation_when_a_field_is_ambiguous(): void
    {
        $mapping = [
            'revenue' => ['column' => '売上金額', 'status' => 'mapped'],
            'quantity' => ['column' => '販売数量', 'status' => 'ambiguous'],
            'orders' => ['column' => '注文件数', 'status' => 'mapped'],
        ];

        $result = app(FilterRecommendedDerivedMetricsAction::class)->execute($this->recommendations(), $mapping);

        $this->assertSame(['average_order_value'], array_column($result, 'name'));
    }

    public function test_drops_a_recommendation_referencing_a_field_absent_from_the_mapping_entirely(): void
    {
        // "quantity"/"orders" not present as keys at all (e.g. a Template
        // field that was never part of this mapping dict).
        $mapping = ['revenue' => ['column' => '売上金額', 'status' => 'mapped']];

        $result = app(FilterRecommendedDerivedMetricsAction::class)->execute($this->recommendations(), $mapping);

        $this->assertSame([], $result);
    }

    public function test_this_action_works_identically_regardless_of_whether_the_mapping_is_ai_only_or_effective(): void
    {
        // Same shape either way: {column, status}. This Action does not
        // and cannot distinguish an AI-validated mapping from a confirmed
        // Effective Mapping — that is the point (see class docblock).
        $aiOnlyMapping = ['revenue' => ['column' => '売上金額', 'status' => 'mapped'], 'orders' => ['column' => null, 'status' => 'unmapped']];
        $effectiveMapping = ['revenue' => ['column' => '売上金額', 'status' => 'mapped', 'source' => 'ai'], 'orders' => ['column' => '注文件数', 'status' => 'mapped', 'source' => 'manual']];

        $this->assertSame(
            [],
            array_column(app(FilterRecommendedDerivedMetricsAction::class)->execute([$this->recommendations()[1]], $aiOnlyMapping), 'name'),
        );
        $this->assertSame(
            ['average_order_value'],
            array_column(app(FilterRecommendedDerivedMetricsAction::class)->execute([$this->recommendations()[1]], $effectiveMapping), 'name'),
        );
    }
}
