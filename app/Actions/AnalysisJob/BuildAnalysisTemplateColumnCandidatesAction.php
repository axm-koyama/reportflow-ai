<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

/**
 * Builds type-filtered column candidates for every Analysis Template
 * field from a Data Profile. See "Column Candidate Filtering" in
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 *   dimension -> DataProfilingAction inferred_type 'string'
 *   measure   -> DataProfilingAction inferred_type 'integer' or 'decimal'
 *   temporal  -> DataProfilingAction inferred_type 'date' or 'datetime'
 *
 * No column-name heuristics are used — only DataProfilingAction's own
 * type inference. sample_values are drawn from the same Data Profile
 * sample_rows already generated for this DataFile (no new data is read
 * or sent).
 *
 * Extracted (Phase 3-C) from ResolveAnalysisTemplateAction, which owned
 * this logic privately until the Mapping Preview screen
 * (docs/product/MAPPING_CONTROL.md) needed to rebuild the exact same
 * candidates — without calling AiAnalysisClient::mapColumns() again —
 * to render manual-override dropdowns. Both callers now share this one
 * implementation rather than duplicating the type-matching rule.
 *
 * This is a pure, deterministic transformation: no AI calls, no database
 * access, no CSV reading (the Data Profile it reads from must already
 * have been produced by DataProfilingAction).
 */
class BuildAnalysisTemplateColumnCandidatesAction
{
    /**
     * @param array<string, array{kind: string, label: string}> $fields a Template's "fields" definition
     * @param array<string, mixed> $dataProfile the DataProfilingAction output for the relevant DataFile
     * @return array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>>
     */
    public function execute(array $fields, array $dataProfile): array
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
}
