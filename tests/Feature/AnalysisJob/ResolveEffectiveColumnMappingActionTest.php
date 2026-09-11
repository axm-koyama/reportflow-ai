<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\ResolveEffectiveColumnMappingAction;
use Tests\TestCase;

class ResolveEffectiveColumnMappingActionTest extends TestCase
{
    /**
     * @return array<string, array{kind: string, label: string}>
     */
    private function fields(): array
    {
        return [
            'revenue' => ['kind' => 'measure', 'label' => '売上'],
            'quantity' => ['kind' => 'measure', 'label' => '数量'],
            'orders' => ['kind' => 'measure', 'label' => '注文数'],
            'product' => ['kind' => 'dimension', 'label' => '商品'],
            'category' => ['kind' => 'dimension', 'label' => 'カテゴリ'],
        ];
    }

    /**
     * "product" and "category" deliberately share the exact same
     * candidate pool (both dimension-kind) so a cross-source duplicate
     * between them is possible, mirroring sales_analysis's real
     * "product"/"category"/"store"/"region"/"customer" overlap.
     *
     * @return array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>>
     */
    private function columnCandidates(): array
    {
        return [
            'revenue' => [['column' => '売上金額', 'inferred_type' => 'integer', 'sample_values' => []]],
            'quantity' => [['column' => '販売数量', 'inferred_type' => 'integer', 'sample_values' => []]],
            'orders' => [['column' => '注文件数', 'inferred_type' => 'integer', 'sample_values' => []]],
            'product' => [
                ['column' => '商品名', 'inferred_type' => 'string', 'sample_values' => []],
                ['column' => '分類', 'inferred_type' => 'string', 'sample_values' => []],
            ],
            'category' => [
                ['column' => '商品名', 'inferred_type' => 'string', 'sample_values' => []],
                ['column' => '分類', 'inferred_type' => 'string', 'sample_values' => []],
            ],
        ];
    }

    /**
     * @return array<string, array{column: string|null, confidence: string, status: string}>
     */
    private function validatedAiMapping(): array
    {
        return [
            'revenue' => ['column' => '売上金額', 'confidence' => 'high', 'status' => 'mapped'],
            'quantity' => ['column' => '販売数量', 'confidence' => 'high', 'status' => 'mapped'],
            'orders' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'product' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'category' => ['column' => '分類', 'confidence' => 'high', 'status' => 'mapped'],
        ];
    }

    private function action(): ResolveEffectiveColumnMappingAction
    {
        return app(ResolveEffectiveColumnMappingAction::class);
    }

    // --- F: manual valid override -> source=manual ----------------------

    public function test_manual_valid_override_is_tagged_source_manual(): void
    {
        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $this->validatedAiMapping(),
            ['category' => ['column' => '商品名']],
            ['revenue'],
            [],
        );

        $this->assertSame('商品名', $result['effective_mapping']['category']['column']);
        $this->assertSame('mapped', $result['effective_mapping']['category']['status']);
        $this->assertSame('manual', $result['effective_mapping']['category']['source']);

        // A field never touched by manual stays source "ai".
        $this->assertSame('売上金額', $result['effective_mapping']['revenue']['column']);
        $this->assertSame('ai', $result['effective_mapping']['revenue']['source']);
    }

    // --- G: manual unknown column -> forced unmapped, not an exception --

    public function test_manual_unknown_column_is_forced_unmapped(): void
    {
        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $this->validatedAiMapping(),
            ['category' => ['column' => '存在しない列']],
            ['revenue'],
            [],
        );

        $this->assertSame('unmapped', $result['effective_mapping']['category']['status']);
        $this->assertNull($result['effective_mapping']['category']['column']);
        $this->assertSame('manual', $result['effective_mapping']['category']['source']);
    }

    // --- H: manual wrong type (revenue=measure, user picks a string) ----

    public function test_manual_mapping_to_a_column_of_the_wrong_type_is_forced_unmapped(): void
    {
        // "商品名" is a string column and is not in "revenue"'s (measure)
        // column_candidates at all — the exact same reuse of
        // ValidateColumnMappingAction's "unknown candidate" rule that
        // already protects the AI path, with no new type-checking code.
        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $this->validatedAiMapping(),
            ['revenue' => ['column' => '商品名']],
            ['revenue'],
            [],
        );

        $this->assertSame('unmapped', $result['effective_mapping']['revenue']['status']);
        $this->assertNull($result['effective_mapping']['revenue']['column']);
        // "revenue" is required and now unmapped -> reported.
        $this->assertSame(['revenue'], $result['missing_required_fields']);
    }

    // --- J: Manual > AI ---------------------------------------------------

    public function test_manual_override_wins_over_a_mapped_ai_value(): void
    {
        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $this->validatedAiMapping(), // category -> 分類 (ai, mapped)
            ['category' => ['column' => '商品名']],
            ['revenue'],
            [],
        );

        $this->assertSame('商品名', $result['effective_mapping']['category']['column']);
        $this->assertSame('manual', $result['effective_mapping']['category']['source']);
    }

    // --- K: Manual unset > AI mapped -> effective unmapped ---------------

    public function test_manual_unset_overrides_an_ai_mapped_value(): void
    {
        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $this->validatedAiMapping(), // quantity -> 販売数量 (ai, mapped)
            ['quantity' => ['column' => null]],
            ['revenue'],
            [],
        );

        $this->assertSame('unmapped', $result['effective_mapping']['quantity']['status']);
        $this->assertNull($result['effective_mapping']['quantity']['column']);
        $this->assertSame('manual', $result['effective_mapping']['quantity']['source']);
    }

    // --- L: AI x Manual cross-source duplicate -> ambiguous ---------------

    /**
     * The AI mapped "product" to "商品名" independently of any manual
     * input; the user separately, manually, maps "category" to the exact
     * same real column. Neither mapping is ambiguous considered alone —
     * only the combined Full Mapping Proposal reveals the conflict. This
     * is exactly why manual overrides are never validated independently
     * and merged after the fact (see the Action's own docblock).
     */
    public function test_ai_and_manual_mapping_the_same_column_is_detected_as_cross_source_ambiguous(): void
    {
        $aiMapping = $this->validatedAiMapping();
        $aiMapping['product'] = ['column' => '商品名', 'confidence' => 'high', 'status' => 'mapped'];

        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $aiMapping,
            ['category' => ['column' => '商品名']],
            ['revenue'],
            [],
        );

        $this->assertSame('ambiguous', $result['effective_mapping']['product']['status']);
        $this->assertSame('ambiguous', $result['effective_mapping']['category']['status']);
    }

    // --- required unmet after manual --------------------------------------

    public function test_required_field_left_unmapped_is_reported_as_missing(): void
    {
        $aiMapping = $this->validatedAiMapping();
        $aiMapping['revenue'] = ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'];

        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $aiMapping,
            [], // user submitted no override for "revenue" either
            ['revenue'],
            [],
        );

        $this->assertSame(['revenue'], $result['missing_required_fields']);
        $this->assertSame('unmapped', $result['effective_mapping']['revenue']['status']);
    }

    // --- an AI field that was only "low"/"ignored" is never resurrected --

    public function test_a_low_confidence_ai_field_the_user_never_touched_becomes_unmapped_not_high(): void
    {
        $aiMapping = $this->validatedAiMapping();
        // "product" was proposed by the AI but only at low confidence,
        // hence not "mapped" -- ValidateColumnMappingAction already
        // demoted it to "ignored" upstream. It must not be resurrected
        // as a trusted "high" confidence value just because this is a
        // re-validation pass.
        $aiMapping['product'] = ['column' => '分類', 'confidence' => 'low', 'status' => 'ignored'];

        $result = $this->action()->execute(
            $this->fields(),
            $this->columnCandidates(),
            $aiMapping,
            [],
            ['revenue'],
            [],
        );

        $this->assertSame('unmapped', $result['effective_mapping']['product']['status']);
        $this->assertSame('ai', $result['effective_mapping']['product']['source']);
    }
}
