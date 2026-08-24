<?php

declare(strict_types=1);

namespace App\Actions\Evaluation;

/**
 * Resolves config/evaluation_metrics.php's semantic metric definitions
 * for one Template into real, CSV-column-level definitions, using the
 * AnalysisJob's own effective_column_mapping as the only Mapping Fact.
 * See docs/product/EVALUATION_ENGINE.md.
 *
 * This action never calls the AI and never re-resolves Column Mapping —
 * effective_column_mapping is trusted exactly as-is (Manual > Validated
 * AI > Unmapped, already decided by ResolveEffectiveColumnMappingAction
 * earlier in the pipeline). It also never reads aggregated_metrics: it
 * only translates semantic field names (e.g. "channel") into real column
 * names (e.g. "媒体") via the mapping. Whether those real columns
 * actually made it into aggregated_metrics (see
 * MetricAggregationAction's cardinality/measure/row limits) is
 * EvaluateRateMetricAction's concern, not this one's.
 *
 * Two distinct "cannot evaluate" outcomes, matching
 * docs/product/EVALUATION_ENGINE.md "Mapping不足時":
 *
 * - entity_field itself unmapped: there is no way to enumerate which
 *   entities (e.g. which channels) even exist, so the metric cannot
 *   produce any EvaluationFact at all. This definition is placed in
 *   "skipped" with a reason, and never appears in "resolved".
 * - numerator_field and/or denominator_field unmapped, but entity_field
 *   *is* mapped: entities can still be enumerated. This definition still
 *   appears in "resolved", with numerator_field/denominator_field left
 *   null — EvaluateRateMetricAction turns this into one
 *   evaluation_level=insufficient_data EvaluationFact per entity rather
 *   than silently producing nothing (see that Action's docblock).
 *
 * A Template with no entry in config/evaluation_metrics.php (including
 * an unknown template_key) simply resolves to no metrics at all — never
 * an error. A null template_key (Free Analysis) or null
 * effectiveColumnMapping (mapping not yet confirmed) does the same.
 */
class ResolveEvaluationMetricDefinitionsAction
{
    /**
     * @param string|null $templateKey
     * @param array<string, array{column: string|null, status: string, source?: string}>|null $effectiveColumnMapping
     * @return array{
     *     resolved: list<array{
     *         metric_key: string,
     *         metric_type: string,
     *         entity_type: string,
     *         entity_field: string,
     *         numerator_field: string|null,
     *         denominator_field: string|null,
     *         unfavorable_direction: string|null,
     *         practical_significance_floor: float,
     *         normal_approximation_min_expected: int|float,
     *         z_threshold_high: float,
     *         z_threshold_medium: float,
     *         rule_version: string,
     *     }>,
     *     skipped: list<array{metric_key: string, reason: string}>,
     * }
     */
    public function execute(?string $templateKey, ?array $effectiveColumnMapping): array
    {
        if ($templateKey === null || $effectiveColumnMapping === null) {
            return ['resolved' => [], 'skipped' => []];
        }

        $metricConfigs = config("evaluation_metrics.{$templateKey}.metrics", []);

        if (! is_array($metricConfigs)) {
            return ['resolved' => [], 'skipped' => []];
        }

        $resolved = [];
        $skipped = [];

        foreach ($metricConfigs as $metricConfig) {
            if (! is_array($metricConfig)) {
                continue;
            }

            $metricKey = is_string($metricConfig['metric_key'] ?? null) ? $metricConfig['metric_key'] : '(unknown)';

            // entity_type is the semantic field key itself (e.g. "channel")
            // — stable across CSVs/languages, and what EvaluationFact
            // persists. entity_field below is the *resolved real column*
            // (e.g. "媒体"), used to look the dimension up in
            // aggregated_metrics.
            $entityType = is_string($metricConfig['entity_field'] ?? null) ? $metricConfig['entity_field'] : null;
            $entityColumn = $this->resolveMappedColumn($effectiveColumnMapping, $entityType);

            if ($entityType === null || $entityColumn === null) {
                $skipped[] = ['metric_key' => $metricKey, 'reason' => 'entity_field_unmapped'];

                continue;
            }

            $resolved[] = [
                'metric_key' => $metricKey,
                'metric_type' => is_string($metricConfig['metric_type'] ?? null) ? $metricConfig['metric_type'] : 'rate',
                'entity_type' => $entityType,
                'entity_field' => $entityColumn,
                'numerator_field' => $this->resolveMappedColumn($effectiveColumnMapping, $metricConfig['numerator_field'] ?? null),
                'denominator_field' => $this->resolveMappedColumn($effectiveColumnMapping, $metricConfig['denominator_field'] ?? null),
                'unfavorable_direction' => is_string($metricConfig['unfavorable_direction'] ?? null) ? $metricConfig['unfavorable_direction'] : null,
                'practical_significance_floor' => is_numeric($metricConfig['practical_significance_floor'] ?? null) ? (float) $metricConfig['practical_significance_floor'] : 0.0,
                'normal_approximation_min_expected' => is_numeric($metricConfig['normal_approximation_min_expected'] ?? null) ? $metricConfig['normal_approximation_min_expected'] : 5,
                'z_threshold_high' => is_numeric($metricConfig['z_threshold_high'] ?? null) ? (float) $metricConfig['z_threshold_high'] : 1.96,
                'z_threshold_medium' => is_numeric($metricConfig['z_threshold_medium'] ?? null) ? (float) $metricConfig['z_threshold_medium'] : 1.00,
                'rule_version' => is_string($metricConfig['rule_version'] ?? null) ? $metricConfig['rule_version'] : 'unversioned',
            ];
        }

        return ['resolved' => $resolved, 'skipped' => $skipped];
    }

    /**
     * Resolve one semantic field key to its real, "mapped" column name —
     * or null if the field is missing, not a string, absent from the
     * mapping, or present but not status "mapped".
     *
     * @param array<string, array{column: string|null, status: string, source?: string}> $effectiveColumnMapping
     */
    private function resolveMappedColumn(array $effectiveColumnMapping, mixed $semanticField): ?string
    {
        if (! is_string($semanticField) || $semanticField === '') {
            return null;
        }

        $entry = $effectiveColumnMapping[$semanticField] ?? null;

        if (! is_array($entry) || ($entry['status'] ?? null) !== 'mapped') {
            return null;
        }

        $column = $entry['column'] ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }
}
