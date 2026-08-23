<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Resolves one AnalysisJob's Effective Column Mapping: the single,
 * deterministic Fact that Planning/Calculation/Analyze actually use,
 * combining the AI's validated proposal with the user's manual overrides
 * (if any). See docs/product/MAPPING_CONTROL.md.
 *
 * Priority: Manual Mapping > Validated AI Mapping > Unmapped.
 *
 * Responsibilities:
 * - Overlay the user's sparse manual overrides onto the AI's validated
 *   mapping to build one complete Full Mapping Proposal (every semantic
 *   field, one {field, column, confidence} entry each)
 * - Re-validate that Full Mapping Proposal through
 *   ValidateColumnMappingAction — the *same* full re-validation an AI-only
 *   proposal gets, never a shortcut — so type mismatches, unknown
 *   columns, and (critically) cross-source duplicates are all caught
 * - Tag each resulting field with its source ("ai" or "manual") for
 *   audit (docs/product/MAPPING_CONTROL.md "Auditability")
 *
 * Why the Full Mapping Proposal is re-validated as a whole, not merged
 * from two independently-validated results: validating the AI's mapping
 * and the user's manual mapping *separately* and merging the two
 * afterwards cannot detect a cross-source duplicate — e.g. the AI
 * proposing "product" -> "商品名" while the user separately, manually,
 * proposes "category" -> "商品名". Neither mapping is ambiguous on its
 * own; only the combined, single-column-list view is. Building one Full
 * Mapping Proposal first and validating it exactly once, through the
 * existing high+high "ambiguous" rule, is the only way to catch this
 * deterministically without adding a second, bespoke duplicate-detection
 * rule that would have to be kept in sync with ValidateColumnMappingAction's.
 *
 * This action does not decide *what* counts as a manual override, does
 * not build column_candidates, does not persist anything, and does not
 * call the AI. It also does not filter recommended_derived_metrics
 * (FilterRecommendedDerivedMetricsAction) — resolving a mapping and
 * filtering Derived Metric hints against one are deliberately separate
 * responsibilities.
 */
class ResolveEffectiveColumnMappingAction
{
    public function __construct(
        private readonly ValidateColumnMappingAction $validateColumnMappingAction,
    ) {}

    /**
     * @param array<string, array{kind: string, label: string}> $fields the Template's own "fields" definition
     * @param array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>> $columnCandidates semantic field => its type-filtered column candidates
     * @param array<string, array{column: string|null, confidence: string, status: string}> $validatedAiMapping ResolveAnalysisTemplateAction's "column_mapping_for_storage" (the AI's proposal, already validated once)
     * @param array<string, array{column: string|null}> $manualOverrides sparse: only fields the user explicitly submitted an opinion for (column: null means "explicitly unset")
     * @param list<string> $requiredFields
     * @param list<list<string>> $requiredFieldGroups
     * @return array{
     *     effective_mapping: array<string, array{column: string|null, status: string, source: 'ai'|'manual'}>,
     *     missing_required_fields: list<string>,
     *     missing_required_field_groups: list<int>
     * }
     */
    public function execute(
        array $fields,
        array $columnCandidates,
        array $validatedAiMapping,
        array $manualOverrides,
        array $requiredFields,
        array $requiredFieldGroups,
    ): array {
        $fullProposal = $this->buildFullMappingProposal($fields, $validatedAiMapping, $manualOverrides);

        $validated = $this->validateColumnMappingAction->execute(
            $fullProposal,
            $fields,
            $columnCandidates,
            $requiredFields,
            $requiredFieldGroups,
        );

        return [
            'effective_mapping' => $this->tagSource($validated['mapping'], $manualOverrides),
            'missing_required_fields' => $validated['missing_required_fields'],
            'missing_required_field_groups' => $validated['missing_required_field_groups'],
        ];
    }

    /**
     * Build one complete proposal (every Template field, one entry each)
     * in the exact {field, column, confidence} shape
     * ValidateColumnMappingAction expects — the same shape
     * MapAnalysisTemplateColumnsAction's decoded AI response already has.
     *
     * A field the user touched (present in $manualOverrides, regardless
     * of what the AI said) always wins: a non-null column becomes
     * confidence "high" (a human's explicit choice is not a "maybe"), and
     * a null column becomes confidence "unmapped" (an explicit "unset").
     *
     * A field the user did not touch falls back to the AI's already
     * validated result — but only if that result actually reached
     * "mapped"; an AI field left "low"/"ambiguous"/"ignored"/"unmapped"
     * contributes nothing here (it was never trusted in the first place,
     * so it must not be resurrected at "high" confidence just because
     * this is a re-validation pass).
     *
     * @param array<string, array{kind: string, label: string}> $fields
     * @param array<string, array{column: string|null, confidence: string, status: string}> $validatedAiMapping
     * @param array<string, array{column: string|null}> $manualOverrides
     * @return list<array{field: string, column: string|null, confidence: string}>
     */
    private function buildFullMappingProposal(array $fields, array $validatedAiMapping, array $manualOverrides): array
    {
        $proposal = [];

        foreach (array_keys($fields) as $field) {
            if (array_key_exists($field, $manualOverrides)) {
                $column = $manualOverrides[$field]['column'] ?? null;

                $proposal[] = [
                    'field' => $field,
                    'column' => $column,
                    'confidence' => $column !== null ? 'high' : 'unmapped',
                ];

                continue;
            }

            $ai = $validatedAiMapping[$field] ?? null;

            if ($ai !== null && $ai['status'] === 'mapped' && $ai['column'] !== null) {
                $proposal[] = ['field' => $field, 'column' => $ai['column'], 'confidence' => 'high'];

                continue;
            }

            $proposal[] = ['field' => $field, 'column' => null, 'confidence' => 'unmapped'];
        }

        return $proposal;
    }

    /**
     * @param array<string, array{column: string|null, confidence: string, status: string}> $validatedMapping
     * @param array<string, array{column: string|null}> $manualOverrides
     * @return array<string, array{column: string|null, status: string, source: 'ai'|'manual'}>
     */
    private function tagSource(array $validatedMapping, array $manualOverrides): array
    {
        $result = [];

        foreach ($validatedMapping as $field => $entry) {
            $result[$field] = [
                'column' => $entry['column'],
                'status' => $entry['status'],
                'source' => array_key_exists($field, $manualOverrides) ? 'manual' : 'ai',
            ];
        }

        return $result;
    }
}
