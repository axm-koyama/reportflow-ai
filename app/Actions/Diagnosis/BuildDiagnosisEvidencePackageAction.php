<?php

declare(strict_types=1);

namespace App\Actions\Diagnosis;

use App\Models\EvaluationFact;

/**
 * Builds the minimal Evidence Package sent to Diagnosis AI for one
 * already-eligible EvaluationFact. See docs/product/DIAGNOSIS_ENGINE.md
 * "Evidence Package" / "Evidence Gate".
 *
 * Three responsibilities, kept together because they are all "what is the
 * AI even allowed to see" decisions:
 *
 * 1. trigger_fact: a selected snapshot of the already-computed
 *    EvaluationFact fields Diagnosis needs, plus one field
 *    (evidence_id) that does not exist on EvaluationFact at all — this is
 *    not a verbatim/full copy of the DB row (rule_version, computed_at,
 *    and the timestamps are never sent, for instance). The Evaluation
 *    values themselves are copied without recomputation or
 *    reinterpretation — Diagnosis AI receives them as a fixed Fact for
 *    this step (see the Diagnosis System Instruction built by
 *    RunDiagnosisForAnalysisJobAction) — but the shape sent to the AI is
 *    Diagnosis's own, not EvaluationFact's.
 * 2. supporting_facts: deterministic, raw measure values for the same
 *    entity, read directly from aggregated_metrics — never from
 *    derived_metrics (Planning AI's non-deterministic proposals; see
 *    docs/product/EVALUATION_ENGINE.md "AI非依存" for why Phase 4-A
 *    itself avoids derived_metrics, for the same reason).
 * 3. allowed_categories: the Evidence Gate. Every category key in
 *    config/diagnosis_categories.php is checked against a deterministic
 *    pattern over trigger_fact/supporting_facts; only categories whose
 *    required evidence is actually present are included.
 *    "insufficient_explanatory_evidence" has no required evidence and is
 *    therefore always included — see §"Abstention" in
 *    docs/product/DIAGNOSIS_ENGINE.md. A category excluded here is never
 *    shown to the AI at all (not merely discouraged by prompt wording).
 *
 * Zero AI I/O. Zero DB writes.
 */
class BuildDiagnosisEvidencePackageAction
{
    /**
     * category_key that is always allowed, regardless of evidence.
     */
    private const string ABSTENTION_CATEGORY = 'insufficient_explanatory_evidence';

    /**
     * @param  EvaluationFact  $fact  an already-eligible EvaluationFact (see
     *                                DetermineDiagnosisEligibilityAction — this action does not
     *                                re-check eligibility)
     * @param  array<string, mixed>  $aggregatedMetrics  the MetricAggregationAction
     *                                                   output already computed for this AnalysisJob attempt
     * @param  array<string, array{column: string|null, status: string, source?: string}>  $effectiveColumnMapping
     * @return array{
     *     trigger_fact: array<string, mixed>,
     *     supporting_facts: list<array{evidence_id: string, metric_key: string, value: int|float}>,
     *     allowed_categories: list<string>,
     * }
     */
    public function execute(
        EvaluationFact $fact,
        array $aggregatedMetrics,
        array $effectiveColumnMapping,
        string $templateKey,
    ): array {
        return [
            'trigger_fact' => $this->triggerFact($fact),
            'supporting_facts' => $this->supportingFacts($fact, $aggregatedMetrics, $effectiveColumnMapping, $templateKey),
            'allowed_categories' => $this->allowedCategories($fact),
        ];
    }

    /**
     * A selected snapshot of the EvaluationFact fields Diagnosis needs —
     * not the full DB row, and not renamed/recomputed from what it does
     * select. Each value listed below is copied from the EvaluationFact
     * as-is (never recomputed, never reinterpreted); fields the row also
     * has but that are irrelevant to Diagnosis (rule_version,
     * computed_at, created_at/updated_at) are simply omitted, not
     * "dropped" from some promised complete copy.
     *
     * "evidence_id" is the one field that does not come from
     * EvaluationFact at all — it is a Diagnosis-only reference identifier,
     * attached solely so the AI can cite this Fact deterministically in
     * evidence_refs (see NormalizeDiagnosisResultAction's
     * suppliedEvidenceIds check). It must be sent to the AI explicitly:
     * a real-API E2E run surfaced that, without it, the AI inferred its
     * own id format from "evaluation_fact_id" ("37",
     * "evaluation_fact_41") that never matched what Laravel expected, and
     * every response was rejected. See docs/product/DIAGNOSIS_ENGINE.md
     * "evidence_refs".
     *
     * @return array<string, mixed>
     */
    private function triggerFact(EvaluationFact $fact): array
    {
        return [
            'evidence_id' => 'trigger:evaluation_fact:'.$fact->evaluation_fact_id,
            'evaluation_fact_id' => $fact->evaluation_fact_id,
            'entity_type' => $fact->entity_type,
            'entity_key' => $fact->entity_key,
            'metric_key' => $fact->metric_key,
            'metric_value' => $fact->metric_value,
            'display_baseline_value' => $fact->display_baseline_value,
            'test_baseline_value' => $fact->test_baseline_value,
            'delta_absolute' => $fact->delta_absolute,
            'delta_percent' => $fact->delta_percent,
            'direction' => $fact->direction,
            'evaluation_level' => $fact->evaluation_level,
            'numerator_value' => $fact->numerator_value,
            'denominator_value' => $fact->denominator_value,
            'control_numerator_value' => $fact->control_numerator_value,
            'control_denominator_value' => $fact->control_denominator_value,
            'z_score' => $fact->z_score,
        ];
    }

    /**
     * Deterministic, raw (unevaluated) measure values for the same
     * entity_key, one per semantic "measure" Template field that is both
     * (a) actually mapped for this AnalysisJob and (b) still present in
     * aggregated_metrics (may have been dropped by
     * MetricAggregationAction's max_measures — see
     * docs/product/METRIC_AGGREGATION.md). The metric's own numerator/
     * denominator semantic fields are excluded here since trigger_fact
     * already carries their values as numerator_value/denominator_value —
     * repeating them as a "supporting" fact would be redundant.
     *
     * Iterates config/analysis_templates.php's declared field order, so
     * output order is stable across requests for the same template.
     *
     * @return list<array{evidence_id: string, metric_key: string, value: int|float}>
     */
    private function supportingFacts(
        EvaluationFact $fact,
        array $aggregatedMetrics,
        array $effectiveColumnMapping,
        string $templateKey,
    ): array {
        $entityColumn = $this->mappedColumn($effectiveColumnMapping, $fact->entity_type);

        if ($entityColumn === null) {
            return [];
        }

        $group = $this->findGroup($aggregatedMetrics, $entityColumn, $fact->entity_key);

        if ($group === null) {
            return [];
        }

        $excludedSemanticFields = $this->numeratorDenominatorFields($templateKey, $fact->metric_key);

        $fields = config("analysis_templates.{$templateKey}.fields", []);
        $supportingFacts = [];

        foreach ($fields as $semanticField => $fieldConfig) {
            if (! is_array($fieldConfig) || ($fieldConfig['kind'] ?? null) !== 'measure') {
                continue;
            }

            if (in_array($semanticField, $excludedSemanticFields, true)) {
                continue;
            }

            $realColumn = $this->mappedColumn($effectiveColumnMapping, (string) $semanticField);

            if ($realColumn === null) {
                continue;
            }

            $sum = $group['metrics'][$realColumn]['sum'] ?? null;

            if (! is_int($sum) && ! is_float($sum)) {
                // Measure was dropped by MetricAggregationAction's
                // max_measures, or never present — silently skip rather
                // than fabricate a value.
                continue;
            }

            $supportingFacts[] = [
                'evidence_id' => 'supporting:'.$semanticField,
                'metric_key' => (string) $semanticField,
                'value' => $sum,
            ];
        }

        return $supportingFacts;
    }

    /**
     * The metric's own numerator_field / denominator_field semantic keys
     * from config/evaluation_metrics.php, so supportingFacts() can exclude
     * them (already represented in trigger_fact).
     *
     * @return list<string>
     */
    private function numeratorDenominatorFields(string $templateKey, string $metricKey): array
    {
        $metrics = config("evaluation_metrics.{$templateKey}.metrics", []);

        if (! is_array($metrics)) {
            return [];
        }

        foreach ($metrics as $metric) {
            if (! is_array($metric) || ($metric['metric_key'] ?? null) !== $metricKey) {
                continue;
            }

            return array_values(array_filter([
                is_string($metric['numerator_field'] ?? null) ? $metric['numerator_field'] : null,
                is_string($metric['denominator_field'] ?? null) ? $metric['denominator_field'] : null,
            ]));
        }

        return [];
    }

    /**
     * Evidence Gate: which config/diagnosis_categories.php entries
     * currently have supporting evidence for this exact EvaluationFact.
     * "insufficient_explanatory_evidence" is always included (see class
     * docblock). This never consults supporting_facts — v1's only
     * evidence-gated category (measurement_consistency_risk) is decided
     * entirely from trigger_fact itself.
     *
     * @return list<string>
     */
    private function allowedCategories(EvaluationFact $fact): array
    {
        $allowed = [];

        if ($this->measurementConsistencyRiskApplies($fact)) {
            $allowed[] = 'measurement_consistency_risk';
        }

        $allowed[] = self::ABSTENTION_CATEGORY;

        return $allowed;
    }

    /**
     * measurement_consistency_risk's required evidence (Phase 4-B v1):
     * the entity's own numerator (e.g. conversions) is exactly 0, while
     * its denominator (e.g. clicks) is non-trivially large. This is a
     * qualitatively different, and strictly stronger, signal than "the
     * evaluated rate is merely below baseline" — it is a pattern
     * Laravel can check deterministically, without needing a second
     * evaluated metric. See docs/product/DIAGNOSIS_ENGINE.md
     * "measurement_consistency_risk required evidence".
     */
    private function measurementConsistencyRiskApplies(EvaluationFact $fact): bool
    {
        $applicableMetrics = config('diagnosis_categories.categories.measurement_consistency_risk.applicable_metrics', []);

        if (! is_array($applicableMetrics) || ! in_array($fact->metric_key, $applicableMetrics, true)) {
            return false;
        }

        if ($fact->numerator_value !== 0) {
            return false;
        }

        $threshold = config('diagnosis_categories.thresholds.minimum_denominator_for_zero_conversion_risk', 5);
        $threshold = is_numeric($threshold) ? (int) $threshold : 5;

        return $fact->denominator_value !== null && $fact->denominator_value >= $threshold;
    }

    /**
     * Resolve one semantic field key to its real, "mapped" column name —
     * mirrors ResolveEvaluationMetricDefinitionsAction::resolveMappedColumn().
     * Deliberately duplicated rather than shared: it is a 6-line pure
     * lookup, and each caller belongs to a different Phase (4-A vs 4-B)
     * that should be free to evolve independently.
     *
     * @param  array<string, array{column: string|null, status: string, source?: string}>  $effectiveColumnMapping
     */
    private function mappedColumn(array $effectiveColumnMapping, string $semanticField): ?string
    {
        $entry = $effectiveColumnMapping[$semanticField] ?? null;

        if (! is_array($entry) || ($entry['status'] ?? null) !== 'mapped') {
            return null;
        }

        $column = $entry['column'] ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * @param  array<string, mixed>  $aggregatedMetrics
     * @return array<string, mixed>|null the aggregated_metrics group entry
     *                                   matching $entityColumn/$entityKey, or null if either the
     *                                   dimension or the specific group value is not present.
     */
    private function findGroup(array $aggregatedMetrics, string $entityColumn, string $entityKey): ?array
    {
        foreach ($aggregatedMetrics['dimensions'] ?? [] as $dimension) {
            if (! is_array($dimension) || ($dimension['dimension'] ?? null) !== $entityColumn) {
                continue;
            }

            foreach ($dimension['groups'] ?? [] as $group) {
                if (is_array($group) && ($group['value'] ?? null) === $entityKey) {
                    return $group;
                }
            }

            return null;
        }

        return null;
    }
}
