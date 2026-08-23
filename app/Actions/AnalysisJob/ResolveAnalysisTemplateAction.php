<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use InvalidArgumentException;

/**
 * Thin orchestration for one Analysis Template resolution: loads the
 * Template definition, builds type-filtered column candidates from a
 * Data Profile, delegates to MapAnalysisTemplateColumnsAction (AI I/O)
 * and ValidateColumnMappingAction (deterministic validation), and
 * reports (without throwing — see "Phase 3-C" below) whether a required
 * field could not be resolved. See docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * This action deliberately contains no AI transport logic (that is
 * MapAnalysisTemplateColumnsAction's job), no confidence / duplicate /
 * required-field decision logic (that is ValidateColumnMappingAction's
 * job), and — as of Phase 3-C — no decision about *what to do* when a
 * required field is missing (that is ExecuteAnalysisJobAction's job, see
 * below). This action only wires the AI/validation steps together and
 * reshapes their output into what ExecuteAnalysisJobAction's later steps
 * need.
 *
 * Phase 3-C: previously this action threw a RuntimeException when
 * missing_required_fields/missing_required_field_groups was non-empty,
 * which always meant "fail the AnalysisJob". Phase 3-C introduces a
 * second possible outcome for that same condition — pause for user
 * Mapping confirmation instead of failing (see
 * docs/product/MAPPING_CONTROL.md) — and deciding between those two
 * outcomes is an orchestration/business-flow decision, not something
 * this action (which only resolves and validates one Mapping attempt)
 * should own. This action therefore no longer throws for a missing
 * required field at all: it always returns successfully, and always
 * includes missing_required_fields/missing_required_field_groups in its
 * result so the caller (ExecuteAnalysisJobAction) can decide what to do.
 * A caller that still wants the exact business-readable message the old
 * exception carried can build it via missingRequiredFieldsMessage()
 * below, which is unchanged and now public specifically so
 * AnalysisJobController's Mapping Preview flow can reuse it too (see
 * "still missing after manual override" in docs/product/MAPPING_CONTROL.md).
 *
 * Column candidate building (BuildAnalysisTemplateColumnCandidatesAction)
 * and recommended_derived_metrics filtering
 * (FilterRecommendedDerivedMetricsAction) were extracted out of this
 * action in Phase 3-C so the Mapping Preview screen and the manual
 * Mapping confirmation path can reuse the exact same logic without
 * duplicating it or calling the AI again — see each Action's own
 * docblock.
 *
 * Out of scope: calling the AI directly, confidence/ambiguity decisions,
 * deciding the AnalysisJob's next business step, persistence,
 * AnalysisJob status updates. When template_key is null, this action is
 * never called at all — see ExecuteAnalysisJobAction.
 */
class ResolveAnalysisTemplateAction
{
    public function __construct(
        private readonly MapAnalysisTemplateColumnsAction $mapAnalysisTemplateColumnsAction,
        private readonly ValidateColumnMappingAction $validateColumnMappingAction,
        private readonly BuildAnalysisTemplateColumnCandidatesAction $buildAnalysisTemplateColumnCandidatesAction,
        private readonly FilterRecommendedDerivedMetricsAction $filterRecommendedDerivedMetricsAction,
    ) {}

    /**
     * Resolve one Analysis Template against one DataFile's Data Profile.
     *
     * analysis_template.recommended_derived_metrics in the returned array
     * is already filtered against the resolved column_mapping (see the
     * class docblock) — every remaining entry's left_field/right_field is
     * guaranteed to be a "mapped" field with a non-null column. This is
     * only correct for the caller's *auto-confident* path (Effective
     * Mapping === Validated AI Mapping): a caller that goes on to run
     * Phase 3-C's manual Mapping confirmation must re-run
     * FilterRecommendedDerivedMetricsAction itself against the confirmed
     * Effective Mapping — this action has no way to know that will
     * happen, so it always computes this field against the AI mapping
     * it just validated.
     *
     * missing_required_fields/missing_required_field_groups being
     * non-empty no longer throws (Phase 3-C) — see the class docblock.
     * column_mapping/column_mapping_for_storage are still fully computed
     * and returned even when required fields are missing, so a caller
     * can still persist the AI's (partial) mapping for display on a
     * Mapping Preview screen.
     *
     * @param string $templateKey a config('analysis_templates') key
     * @param string $prompt the user's additional prompt (may be '')
     * @param array<string, mixed> $dataProfile the DataProfilingAction output for this AnalysisJob's DataFile
     * @return array{
     *     analysis_template: array{name: string, instruction: string, recommended_derived_metrics: list<array<string, mixed>>},
     *     column_mapping: array<string, string>,
     *     column_mapping_for_storage: array<string, array{column: string|null, confidence: string, status: string}>,
     *     missing_required_fields: list<string>,
     *     missing_required_field_groups: list<int>
     * }
     * @throws InvalidArgumentException if $templateKey does not exist in config('analysis_templates')
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

        $columnCandidates = $this->buildAnalysisTemplateColumnCandidatesAction->execute($fields, $dataProfile);
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

        return [
            'analysis_template' => [
                'name' => $template['name'],
                'instruction' => $template['instruction'],
                'recommended_derived_metrics' => $this->filterRecommendedDerivedMetricsAction->execute(
                    $template['recommended_derived_metrics'] ?? [],
                    $validated['mapping'],
                ),
            ],
            'column_mapping' => $this->simpleMappingForAi($validated['mapping']),
            'column_mapping_for_storage' => $validated['mapping'],
            'missing_required_fields' => $validated['missing_required_fields'],
            'missing_required_field_groups' => $validated['missing_required_field_groups'],
        ];
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
     * Also reused (Phase 3-C) by ExecuteAnalysisJobAction to reduce a
     * confirmed Effective Mapping the exact same way before handing it to
     * PlanDerivedMetricsAction/BuildAnalysisContextAction — both mapping
     * shapes share the same {column, status} fields, so the same
     * reduction rule applies unchanged.
     *
     * @param array<string, array{column: string|null, status: string}> $mapping
     * @return array<string, string>
     */
    public function simpleMappingForAi(array $mapping): array
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
     * Build a business-readable failure message describing which
     * required fields/groups could not be resolved. Used both for
     * AnalysisJobDetail.error_message (never reached in Phase 3-C for a
     * Template job — a missing required field now leads to
     * AwaitingMappingConfirmation, not Failed, on the very first attempt)
     * and for the Mapping Preview screen's validation error when a user's
     * manual override still leaves a required field unresolved (see
     * docs/product/MAPPING_CONTROL.md).
     *
     * @param array<string, mixed> $template
     * @param array<string, array{kind: string, label: string}> $fields
     * @param list<list<string>> $requiredFieldGroups
     * @param list<string> $missingRequiredFields
     * @param list<int> $missingRequiredFieldGroups
     */
    public function missingRequiredFieldsMessage(
        array $template,
        array $fields,
        array $requiredFieldGroups,
        array $missingRequiredFields,
        array $missingRequiredFieldGroups,
    ): string {
        $labels = [];

        foreach ($missingRequiredFields as $field) {
            $labels[] = $fields[$field]['label'] ?? $field;
        }

        foreach ($missingRequiredFieldGroups as $groupIndex) {
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
