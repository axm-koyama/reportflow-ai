<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use InvalidArgumentException;
use RuntimeException;

/**
 * Thin orchestration for one Analysis Template resolution: loads the
 * Template definition, builds type-filtered column candidates from a
 * Data Profile, delegates to MapAnalysisTemplateColumnsAction (AI I/O)
 * and ValidateColumnMappingAction (deterministic validation), and fails
 * loudly when a required field cannot be resolved. See
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * This action deliberately contains no AI transport logic (that is
 * MapAnalysisTemplateColumnsAction's job) and no confidence / duplicate /
 * required-field decision logic (that is ValidateColumnMappingAction's
 * job) — it only wires them together and reshapes their output into what
 * ExecuteAnalysisJobAction's later steps need.
 *
 * Candidate filtering (one of the two pieces of business logic this
 * action owns) is intentionally simple and type-driven only: a field's
 * "kind" is matched against DataProfilingAction's inferred_type, never
 * against a column-name dictionary. See "Column Candidate Filtering" in
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * The other piece of business logic this action owns is filtering
 * config('analysis_templates')'s "recommended_derived_metrics" against
 * the just-validated column_mapping before either AI ever sees them: a
 * recommendation is kept only when both its left_field and right_field
 * resolved to status "mapped" with a non-null column. This is
 * deterministic — never delegated to AI judgment — because Planning AI
 * has previously been observed silently substituting an unmapped hint's
 * missing operand with an unrelated mapped measure while keeping the
 * hint's original semantic "name" (e.g. proposing clicks / spend under
 * the name "click_through_rate" when "impressions" was unmapped),
 * producing a numerically valid but business-meaningless metric. See
 * "Recommended Derived Metrics Filtering" in
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md. This filtering only narrows
 * which hints are offered — it never restricts what Planning AI may
 * propose on its own from available_measures (see
 * PlanDerivedMetricsAction's System Instruction Rule 9); it is a hint
 * filter, not a whitelist enforced on the final Metric Plan.
 *
 * Out of scope: calling the AI directly, confidence/ambiguity decisions,
 * persistence, AnalysisJob status updates. When template_key is null,
 * this action is never called at all — see ExecuteAnalysisJobAction.
 */
class ResolveAnalysisTemplateAction
{
    public function __construct(
        private readonly MapAnalysisTemplateColumnsAction $mapAnalysisTemplateColumnsAction,
        private readonly ValidateColumnMappingAction $validateColumnMappingAction,
    ) {}

    /**
     * Resolve one Analysis Template against one DataFile's Data Profile.
     *
     * analysis_template.recommended_derived_metrics in the returned array
     * is already filtered against the resolved column_mapping (see the
     * class docblock) — every remaining entry's left_field/right_field is
     * guaranteed to be a "mapped" field with a non-null column.
     *
     * @param string $templateKey a config('analysis_templates') key
     * @param string $prompt the user's additional prompt (may be '')
     * @param array<string, mixed> $dataProfile the DataProfilingAction output for this AnalysisJob's DataFile
     * @return array{
     *     analysis_template: array{name: string, instruction: string, recommended_derived_metrics: list<array<string, mixed>>},
     *     column_mapping: array<string, string>,
     *     column_mapping_for_storage: array<string, array{column: string|null, confidence: string, status: string}>
     * }
     * @throws InvalidArgumentException if $templateKey does not exist in config('analysis_templates')
     * @throws RuntimeException if a required field (or required_field_group) could not be resolved
     *                           with high enough confidence; the message is business-readable and
     *                           intended to be stored as-is in AnalysisJobDetail.error_message
     */
    public function execute(string $templateKey, string $prompt, array $dataProfile): array
    {
        $template = config("analysis_templates.{$templateKey}");

        if (! is_array($template)) {
            throw new InvalidArgumentException("Unknown analysis template \"{$templateKey}\".");
        }

        /** @var array<string, array{kind: string, label: string}> $fields */
        $fields = $template['fields'] ?? [];
        $requiredFields = $template['required_fields'] ?? [];
        $requiredFieldGroups = $template['required_field_groups'] ?? [];

        $columnCandidates = $this->buildColumnCandidates($fields, $dataProfile);
        $templateFieldsForAi = $this->buildTemplateFieldsForAi($fields);

        $proposedMappings = $this->mapAnalysisTemplateColumnsAction->execute(
            $prompt,
            $templateFieldsForAi,
            $columnCandidates,
        );

        $validated = $this->validateColumnMappingAction->execute(
            $proposedMappings,
            $fields,
            $columnCandidates,
            $requiredFields,
            $requiredFieldGroups,
        );

        if ($validated['missing_required_fields'] !== [] || $validated['missing_required_field_groups'] !== []) {
            throw new RuntimeException($this->missingRequiredFieldsMessage(
                $template,
                $fields,
                $requiredFieldGroups,
                $validated,
            ));
        }

        return [
            'analysis_template' => [
                'name' => $template['name'],
                'instruction' => $template['instruction'],
                'recommended_derived_metrics' => $this->filterRecommendedDerivedMetrics(
                    $template['recommended_derived_metrics'] ?? [],
                    $validated['mapping'],
                ),
            ],
            'column_mapping' => $this->simpleMappingForAi($validated['mapping']),
            'column_mapping_for_storage' => $validated['mapping'],
        ];
    }

    /**
     * Keep only the recommendations whose left_field and right_field both
     * resolved to a usable real column, per the class docblock
     * ("Recommended Derived Metrics Filtering"). A recommendation
     * referencing a field that does not even exist on the Template (which
     * should never happen for a well-formed config, but is not this
     * action's job to assert) is also dropped, since such a field can
     * never appear in $mapping as "mapped".
     *
     * This never inspects operator_hint or "name" — only whether the two
     * semantic fields it references are trustworthy real columns.
     *
     * @param list<array<string, mixed>> $recommendations the Template's own "recommended_derived_metrics"
     * @param array<string, array{column: string|null, confidence: string, status: string}> $mapping ValidateColumnMappingAction's validated mapping
     * @return list<array<string, mixed>>
     */
    private function filterRecommendedDerivedMetrics(array $recommendations, array $mapping): array
    {
        return array_values(array_filter(
            $recommendations,
            fn (array $recommendation): bool => $this->fieldIsUsable($recommendation['left_field'] ?? null, $mapping)
                && $this->fieldIsUsable($recommendation['right_field'] ?? null, $mapping),
        ));
    }

    /**
     * @param array<string, array{column: string|null, confidence: string, status: string}> $mapping
     */
    private function fieldIsUsable(mixed $field, array $mapping): bool
    {
        if (! is_string($field) || ! array_key_exists($field, $mapping)) {
            return false;
        }

        $entry = $mapping[$field];

        return $entry['status'] === 'mapped' && $entry['column'] !== null;
    }

    /**
     * Build type-filtered column candidates for every Template field, per
     * docs/product/ANALYSIS_TEMPLATE_MODULE.md "Column Candidate Filtering":
     *
     *   dimension -> inferred_type 'string'
     *   measure   -> inferred_type 'integer' or 'decimal'
     *   temporal  -> inferred_type 'date' or 'datetime'
     *
     * No column-name heuristics are used — only DataProfilingAction's own
     * type inference. sample_values are drawn from the same Data Profile
     * sample_rows already generated for this DataFile (no new data is
     * read or sent).
     *
     * @param array<string, array{kind: string, label: string}> $fields
     * @param array<string, mixed> $dataProfile
     * @return array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>>
     */
    private function buildColumnCandidates(array $fields, array $dataProfile): array
    {
        $columns = $dataProfile['columns'] ?? [];
        $sampleRows = $dataProfile['sample_rows'] ?? [];

        $candidates = [];

        foreach ($fields as $fieldKey => $field) {
            $candidates[$fieldKey] = [];

            foreach ($columns as $column) {
                if (! $this->inferredTypeMatchesKind($column['inferred_type'] ?? '', $field['kind'] ?? '')) {
                    continue;
                }

                $candidates[$fieldKey][] = [
                    'column' => $column['name'],
                    'inferred_type' => $column['inferred_type'],
                    'sample_values' => $this->sampleValuesForColumn($sampleRows, $column['name']),
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param string $inferredType a DataProfilingAction "columns[].inferred_type" value
     * @param string $kind a Template field's "kind"
     */
    private function inferredTypeMatchesKind(string $inferredType, string $kind): bool
    {
        return match ($kind) {
            'dimension' => $inferredType === 'string',
            'measure' => in_array($inferredType, ['integer', 'decimal'], true),
            'temporal' => in_array($inferredType, ['date', 'datetime'], true),
            default => false,
        };
    }

    /**
     * @param list<array<string, mixed>> $sampleRows
     * @return list<string>
     */
    private function sampleValuesForColumn(array $sampleRows, string $columnName): array
    {
        $values = [];

        foreach ($sampleRows as $row) {
            $value = $row[$columnName] ?? null;

            if (is_string($value) && $value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, array{kind: string, label: string}> $fields
     * @return list<array{field: string, kind: string, label: string}>
     */
    private function buildTemplateFieldsForAi(array $fields): array
    {
        $result = [];

        foreach ($fields as $fieldKey => $field) {
            $result[] = [
                'field' => $fieldKey,
                'kind' => $field['kind'],
                'label' => $field['label'],
            ];
        }

        return $result;
    }

    /**
     * Reduce the rich, storage-oriented mapping down to the simple
     * semantic-field => real-column-name form the AI actually needs, per
     * docs/product/ANALYSIS_TEMPLATE_MODULE.md ("DB保存形式とAI Context
     * 形式は同一でなくてもよい"). Only fields with status "mapped" are
     * included — the AI never needs to know about unmapped/ignored/
     * ambiguous fields.
     *
     * @param array<string, array{column: string|null, confidence: string, status: string}> $mapping
     * @return array<string, string>
     */
    private function simpleMappingForAi(array $mapping): array
    {
        $simple = [];

        foreach ($mapping as $field => $entry) {
            if ($entry['status'] === 'mapped' && $entry['column'] !== null) {
                $simple[$field] = $entry['column'];
            }
        }

        return $simple;
    }

    /**
     * Build a business-readable failure message for AnalysisJobDetail.error_message.
     *
     * @param array<string, mixed> $template
     * @param array<string, array{kind: string, label: string}> $fields
     * @param list<list<string>> $requiredFieldGroups
     * @param array{mapping: array<string, array{column: string|null, confidence: string, status: string}>, missing_required_fields: list<string>, missing_required_field_groups: list<int>} $validated
     */
    private function missingRequiredFieldsMessage(
        array $template,
        array $fields,
        array $requiredFieldGroups,
        array $validated,
    ): string {
        $labels = [];

        foreach ($validated['missing_required_fields'] as $field) {
            $labels[] = $fields[$field]['label'] ?? $field;
        }

        foreach ($validated['missing_required_field_groups'] as $groupIndex) {
            $groupLabels = array_map(
                static fn (string $field): string => $fields[$field]['label'] ?? $field,
                $requiredFieldGroups[$groupIndex] ?? [],
            );

            $labels[] = implode('・', $groupLabels).'のいずれか';
        }

        $labelText = implode('」「', $labels);

        return "テンプレート「{$template['name']}」に必要な項目「{$labelText}」に対応する列を確実に特定できませんでした。CSVの列名と内容をご確認ください。";
    }
}
