<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Validates AI-proposed column mappings and applies deterministic
 * confidence / duplicate / required-field rules. See
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * This is a pure validator: it never calls an AI provider, never touches
 * the database, and makes no semantic judgment of its own about which
 * column "means" what — that judgment was already made (or not made) by
 * MapAnalysisTemplateColumnsAction's AI call. This action only decides,
 * deterministically, whether to trust and use what the AI proposed.
 *
 * Responsibilities:
 * - Normalize each proposed {field, column, confidence} against the
 *   Template's own field list and each field's known column_candidates,
 *   forcing anything malformed or referencing an unknown column to
 *   "unmapped" (see docs/product/ANALYSIS_TEMPLATE_MODULE.md "unknown
 *   candidate")
 * - Detect duplicate/ambiguous mappings: two different fields resolved to
 *   the same real column
 * - Apply the confidence policy (Required+high / Optional+high -> used;
 *   everything else -> not used) via a per-field "status"
 * - Report which required_fields / required_field_groups ended up
 *   unsatisfied, so ResolveAnalysisTemplateAction can decide to fail the
 *   AnalysisJob (this action itself never throws)
 *
 * Every semantic field declared on the Template is always present in the
 * returned mapping — including fields the AI never mentioned, or that had
 * zero column_candidates — each with status "unmapped" by default. This
 * keeps the result a complete record of "what this Template defines vs.
 * what actually got used" for storage and display (see AnalysisJobDetail.column_mapping).
 *
 * Status vocabulary (deliberately kept to these 4, no more):
 * - "mapped": accepted for use (confidence high, unambiguous, valid column)
 * - "unmapped": no usable candidate (AI said unmapped, field never
 *   mentioned, or referenced an unknown column)
 * - "ambiguous": the same column was proposed at "high" confidence for
 *   two or more different fields — none of them are used
 * - "ignored": a specific, valid, known-candidate column was proposed,
 *   but not used — either its confidence was only "low", or it lost a
 *   high vs. low duplicate-column tie-break to another field
 *
 * Out of scope: calling the AI, loading the Template definition from
 * config, building the AI Context, throwing on a failed required-field
 * check (ResolveAnalysisTemplateAction owns that decision), persistence.
 */
class ValidateColumnMappingAction
{
    /**
     * @var list<string>
     */
    private const array VALID_CONFIDENCES = ['high', 'low', 'unmapped'];

    /**
     * @param list<array<string, mixed>> $proposedMappings raw, untrusted proposals from MapAnalysisTemplateColumnsAction
     * @param array<string, array{kind: string, label: string}> $fields the Template's own "fields" definition
     * @param array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>> $columnCandidates semantic field => its type-filtered column candidates
     * @param list<string> $requiredFields
     * @param list<list<string>> $requiredFieldGroups each inner list is an "at least one of" requirement
     * @return array{
     *     mapping: array<string, array{column: string|null, confidence: string, status: string}>,
     *     missing_required_fields: list<string>,
     *     missing_required_field_groups: list<int>
     * }
     */
    public function execute(
        array $proposedMappings,
        array $fields,
        array $columnCandidates,
        array $requiredFields,
        array $requiredFieldGroups,
    ): array {
        $normalized = $this->normalize($proposedMappings, $fields, $columnCandidates);
        $mapping = $this->resolveDuplicates($normalized);

        $missingRequiredFields = array_values(array_filter(
            $requiredFields,
            static fn (string $field): bool => ($mapping[$field]['status'] ?? 'unmapped') !== 'mapped',
        ));

        $missingRequiredFieldGroups = [];

        foreach ($requiredFieldGroups as $index => $group) {
            $satisfied = array_any(
                $group,
                static fn (string $field): bool => ($mapping[$field]['status'] ?? 'unmapped') === 'mapped',
            );

            if (! $satisfied) {
                $missingRequiredFieldGroups[] = $index;
            }
        }

        return [
            'mapping' => $mapping,
            'missing_required_fields' => $missingRequiredFields,
            'missing_required_field_groups' => $missingRequiredFieldGroups,
        ];
    }

    /**
     * Build a {column, confidence} entry for every Template field,
     * defaulting to unmapped, then apply whichever raw proposals are
     * structurally valid and reference a known field / known candidate
     * column. The first proposal for a given field wins; later duplicate
     * proposals for the same field are ignored (mirrors
     * CalculateDerivedMetricsAction's "first valid definition wins" rule
     * for duplicate names).
     *
     * @param list<array<string, mixed>> $proposedMappings
     * @param array<string, array{kind: string, label: string}> $fields
     * @param array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>> $columnCandidates
     * @return array<string, array{column: string|null, confidence: string}>
     */
    private function normalize(array $proposedMappings, array $fields, array $columnCandidates): array
    {
        $normalized = [];

        foreach (array_keys($fields) as $fieldKey) {
            $normalized[$fieldKey] = ['column' => null, 'confidence' => 'unmapped'];
        }

        $seenFields = [];

        foreach ($proposedMappings as $raw) {
            $field = $raw['field'] ?? null;

            if (! is_string($field) || ! array_key_exists($field, $fields)) {
                continue; // unknown/hallucinated field name — ignore entirely
            }

            if (isset($seenFields[$field])) {
                continue; // first occurrence for this field wins
            }

            $seenFields[$field] = true;

            $confidence = $raw['confidence'] ?? null;

            if (! is_string($confidence) || ! in_array($confidence, self::VALID_CONFIDENCES, true)) {
                continue; // malformed confidence — leave the default "unmapped" entry
            }

            if ($confidence === 'unmapped') {
                continue; // already the default
            }

            $column = $raw['column'] ?? null;

            if (! is_string($column) || $column === '') {
                continue; // high/low without a real column name — leave "unmapped"
            }

            $knownColumns = array_column($columnCandidates[$field] ?? [], 'column');

            if (! in_array($column, $knownColumns, true)) {
                continue; // unknown candidate — force "unmapped" (see class docblock)
            }

            $normalized[$field] = ['column' => $column, 'confidence' => $confidence];
        }

        return $normalized;
    }

    /**
     * Detect columns claimed by more than one field and apply the
     * duplicate/ambiguity policy, producing the final per-field status.
     *
     * @param array<string, array{column: string|null, confidence: string}> $normalized
     * @return array<string, array{column: string|null, confidence: string, status: string}>
     */
    private function resolveDuplicates(array $normalized): array
    {
        $mapping = [];

        foreach ($normalized as $field => $entry) {
            $mapping[$field] = [
                'column' => $entry['column'],
                'confidence' => $entry['confidence'],
                'status' => $this->baseStatus($entry),
            ];
        }

        $fieldsByColumn = [];

        foreach ($normalized as $field => $entry) {
            if ($entry['column'] !== null) {
                $fieldsByColumn[$entry['column']][] = $field;
            }
        }

        foreach ($fieldsByColumn as $fieldsSharingColumn) {
            if (count($fieldsSharingColumn) < 2) {
                continue;
            }

            $highFields = array_values(array_filter(
                $fieldsSharingColumn,
                static fn (string $field): bool => $normalized[$field]['confidence'] === 'high',
            ));

            if (count($highFields) >= 2) {
                // Two or more fields both confidently claim the same
                // column: cannot deterministically prefer one, so none of
                // them are used.
                foreach ($highFields as $field) {
                    $mapping[$field]['status'] = 'ambiguous';
                }

                foreach ($fieldsSharingColumn as $field) {
                    if (! in_array($field, $highFields, true)) {
                        $mapping[$field]['status'] = 'ignored';
                    }
                }
            } elseif (count($highFields) === 1) {
                // Exactly one high-confidence claim: it wins, every other
                // (necessarily lower-confidence) claim on the same column
                // is ignored rather than ambiguous.
                $winner = $highFields[0];

                foreach ($fieldsSharingColumn as $field) {
                    if ($field !== $winner) {
                        $mapping[$field]['status'] = 'ignored';
                    }
                }
            }

            // count($highFields) === 0: every claim on this column is
            // "low" — baseStatus() already resolved each to "ignored",
            // and there is no higher-confidence claim to prefer, so no
            // further action is needed here.
        }

        return $mapping;
    }

    /**
     * @param array{column: string|null, confidence: string} $entry
     */
    private function baseStatus(array $entry): string
    {
        if ($entry['column'] === null) {
            return 'unmapped';
        }

        return $entry['confidence'] === 'high' ? 'mapped' : 'ignored';
    }
}
