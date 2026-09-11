<?php

declare(strict_types=1);

namespace App\Actions\DataProfiling;

use App\Models\DataFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Aggregates a DataFile's CSV into per-dimension, per-measure summary
 * statistics (sum / count / avg), computed exactly by Laravel rather than
 * estimated by the AI. See:
 *
 * - docs/product/METRIC_AGGREGATION.md
 * - docs/product/DATA_PROFILING.md
 * - docs/product/AI_ANALYSIS.md
 *
 * MetricAggregationAction receives a DataFile together with the Data
 * Profile already produced for it by DataProfilingAction. The Data Profile
 * is used only to *select* which columns are worth aggregating (dimension
 * candidates = categorical columns under the cardinality limit; measure
 * candidates = columns DataProfilingAction already inferred as numeric).
 * Column selection never depends on column names — only on the inferred
 * type / cardinality information already present in the Data Profile — so
 * this action is not specific to marketing data or any other CSV shape.
 *
 * The actual sum/count/avg values are always computed by re-streaming the
 * CSV; they are never derived or approximated from the Data Profile's own
 * (differently-scoped, non-grouped) statistics.
 *
 * Deliberately out of scope for Phase 1 (see METRIC_AGGREGATION.md):
 * - ratio / derived metrics (ROAS, CPA, CVR, ...)
 * - AI-proposed formula execution
 * - budget reallocation
 * - any column-name-based heuristic
 *
 * DataProfilingAction itself is intentionally left unmodified: this class
 * does not add an Aggregation responsibility to it (DATA_PROFILING.md §2
 * explicitly excludes Aggregation from DataProfilingAction's scope).
 *
 * Out of scope for this action: AI API calls, result normalization,
 * AnalysisJob status updates, and persistence of any kind.
 */
class MetricAggregationAction
{
    /**
     * Matches integers without a leading zero, mirroring
     * DataProfilingAction::INTEGER_PATTERN so a measure value is parsed
     * consistently with how DataProfilingAction inferred the column's
     * numeric type in the first place.
     */
    private const string INTEGER_PATTERN = '/^-?(?:0|[1-9]\d*)$/';

    /**
     * Matches plain decimal numbers, mirroring
     * DataProfilingAction::DECIMAL_PATTERN.
     */
    private const string DECIMAL_PATTERN = '/^-?(?:0|[1-9]\d*)\.\d+$/';

    /**
     * Generate Aggregated Metrics for the given DataFile.
     *
     * @param DataFile $dataFile
     * @param array<string, mixed> $dataProfile the DataProfilingAction output for this exact DataFile
     * @return array<string, mixed>
     * @throws RuntimeException if the file cannot be opened/streamed, or a
     *                           selected dimension/measure column from the
     *                           Data Profile is missing from the CSV
     *                           header.
     */
    public function execute(DataFile $dataFile, array $dataProfile): array
    {
        $measures = $this->selectMeasures($dataProfile);
        $dimensions = $this->selectDimensions($dataProfile);

        if ($dimensions === [] || $measures === []) {
            // Nothing to group by, or nothing to measure: skip the CSV
            // read entirely rather than opening a stream for no reason.
            return [
                'dimensions' => [],
                'measures' => $measures,
            ];
        }

        $groups = $this->streamAndAggregate(
            $dataFile,
            array_column($dimensions, 'name'),
            $measures,
        );

        $dimensionResults = $this->buildDimensionResults($dimensions, $measures, $groups);
        $dimensionResults = $this->applyAggregatedRowsLimit($dimensionResults);

        return [
            'dimensions' => $dimensionResults,
            'measures' => $measures,
        ];
    }

    /**
     * Select dimension candidates from the Data Profile's column
     * information: categorical (string-inferred) columns whose
     * unique_count sits in (1, max_cardinality_per_dimension]. A column
     * with fewer than 2 distinct values has no grouping value (it is
     * equivalent to the overall totals), and a column above the
     * cardinality ceiling (e.g. an ID-like column) is excluded outright
     * rather than truncated to its most frequent values.
     *
     * Candidates are ordered by ascending cardinality (ties keep the
     * original column/header order, since PHP's usort is stable) and
     * capped to max_dimensions.
     *
     * @param array<string, mixed> $dataProfile
     * @return list<array{name: string, cardinality: int}>
     */
    private function selectDimensions(array $dataProfile): array
    {
        $maxCardinality = (int) config('metric_aggregation.max_cardinality_per_dimension', 20);
        $maxDimensions = (int) config('metric_aggregation.max_dimensions', 5);

        $candidates = [];

        foreach ($dataProfile['columns'] ?? [] as $column) {
            if (($column['inferred_type'] ?? null) !== 'string') {
                continue;
            }

            $uniqueCount = (int) ($column['unique_count'] ?? 0);

            if ($uniqueCount < 2 || $uniqueCount > $maxCardinality) {
                continue;
            }

            $candidates[] = ['name' => (string) $column['name'], 'cardinality' => $uniqueCount];
        }

        usort($candidates, static fn (array $a, array $b): int => $a['cardinality'] <=> $b['cardinality']);

        return array_slice($candidates, 0, max($maxDimensions, 0));
    }

    /**
     * Select measure candidates from the Data Profile's numeric
     * statistics: columns DataProfilingAction already inferred as
     * integer/decimal. Candidates are taken in CSV column order and
     * capped to max_measures.
     *
     * @param array<string, mixed> $dataProfile
     * @return list<string>
     */
    private function selectMeasures(array $dataProfile): array
    {
        $maxMeasures = (int) config('metric_aggregation.max_measures', 10);

        $measures = array_map(
            static fn (array $statistic): string => (string) $statistic['column'],
            $dataProfile['numeric_statistics'] ?? [],
        );

        return array_slice($measures, 0, max($maxMeasures, 0));
    }

    /**
     * Stream the CSV once, accumulating per-dimension-value, per-measure
     * sum/count state.
     *
     * A row contributes to a dimension's grouping only when that row's
     * value for that dimension column is present (not null / not empty
     * string), mirroring DataProfilingAction's null handling: missing
     * dimension values are excluded from grouping rather than collected
     * into a synthetic "missing" group. Within an included group, each
     * measure independently skips rows where that specific measure's
     * value is missing or not numeric, so one measure's gaps never affect
     * another measure's or another dimension's aggregation for the same
     * row.
     *
     * @param DataFile $dataFile
     * @param list<string> $dimensionNames
     * @param list<string> $measureNames
     * @return array<string, array<string, array{row_count: int, measures: array<string, array{sum: int|float, count: int}>}>>
     * @throws RuntimeException
     */
    private function streamAndAggregate(DataFile $dataFile, array $dimensionNames, array $measureNames): array
    {
        $stream = Storage::disk('local')->readStream($dataFile->stored_path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open data file for streaming: {$dataFile->stored_path}");
        }

        try {
            $columnIndex = $this->readHeaderIndex($stream);

            foreach ([...$dimensionNames, ...$measureNames] as $columnName) {
                if (! array_key_exists($columnName, $columnIndex)) {
                    throw new RuntimeException(
                        "Column \"{$columnName}\" from the Data Profile was not found in the CSV header.",
                    );
                }
            }

            $dimensionPositions = array_intersect_key($columnIndex, array_flip($dimensionNames));
            $measurePositions = array_intersect_key($columnIndex, array_flip($measureNames));

            $groups = array_fill_keys($dimensionNames, []);
            $emptyMeasureState = array_fill_keys($measureNames, ['sum' => 0, 'count' => 0]);

            while (($row = fgetcsv($stream, escape: '\\')) !== false) {
                if ($row === [null]) {
                    // Unintentional blank line; not a data row (mirrors
                    // DataProfilingAction::streamRows()).
                    continue;
                }

                foreach ($dimensionPositions as $dimensionName => $columnPosition) {
                    $dimensionValue = $row[$columnPosition] ?? null;

                    if ($dimensionValue === null || $dimensionValue === '') {
                        continue;
                    }

                    $groups[$dimensionName][$dimensionValue] ??= [
                        'row_count' => 0,
                        'measures' => $emptyMeasureState,
                    ];

                    $groups[$dimensionName][$dimensionValue]['row_count']++;

                    foreach ($measurePositions as $measureName => $measurePosition) {
                        $measureValue = $row[$measurePosition] ?? null;

                        if ($measureValue === null || $measureValue === '') {
                            continue;
                        }

                        $numeric = $this->toNumeric($measureValue);

                        if ($numeric === null) {
                            continue;
                        }

                        $groups[$dimensionName][$dimensionValue]['measures'][$measureName]['sum'] += $numeric;
                        $groups[$dimensionName][$dimensionValue]['measures'][$measureName]['count']++;
                    }
                }
            }

            return $groups;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Read and lightly normalize the CSV header into a column-name =>
     * position map.
     *
     * This intentionally does not repeat DataProfilingAction's full header
     * validation (duplicate / empty column name checks, per-cell UTF-8
     * validation): by the time MetricAggregationAction runs in the
     * ExecuteAnalysisJobAction pipeline, DataProfilingAction has already
     * validated this exact file. Re-validating here would duplicate a
     * substantial amount of DataProfilingAction's logic for no behavioral
     * benefit in the pipeline's normal path; see
     * docs/product/METRIC_AGGREGATION.md ("2-pass CSV reading") for the
     * trade-off this leaves for a future shared reader.
     *
     * @param resource $stream
     * @return array<string, int>
     * @throws RuntimeException
     */
    private function readHeaderIndex($stream): array
    {
        $header = fgetcsv($stream, escape: '\\');

        if ($header === false || $header === [null]) {
            throw new RuntimeException('CSV file is empty or its header row could not be read.');
        }

        $trimmed = [];

        foreach ($header as $index => $name) {
            $name = (string) $name;

            // Remove UTF-8 BOM from the first column name, mirroring
            // DataProfilingAction::readHeader().
            if ($index === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
            }

            $trimmed[] = trim($name);
        }

        return array_flip($trimmed);
    }

    /**
     * Parse a value as an int/float using the same strict shapes
     * DataProfilingAction used to infer the column's numeric type, so a
     * measure's aggregation stays consistent with why that column was
     * selected as numeric in the first place.
     *
     * @param string $value
     * @return int|float|null
     */
    private function toNumeric(string $value): int|float|null
    {
        if (preg_match(self::INTEGER_PATTERN, $value) === 1) {
            return (int) $value;
        }

        if (preg_match(self::DECIMAL_PATTERN, $value) === 1) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Convert the raw per-dimension aggregation state into the public
     * output shape, computing avg and sorting each dimension's groups by
     * row count (descending) then value (ascending) — the same tie-break
     * used by DataProfilingAction's categorical top_values.
     *
     * @param list<array{name: string, cardinality: int}> $dimensions
     * @param list<string> $measures
     * @param array<string, array<string, array{row_count: int, measures: array<string, array{sum: int|float, count: int}>}>> $groups
     * @return list<array{dimension: string, group_count: int, groups: list<array<string, mixed>>}>
     */
    private function buildDimensionResults(array $dimensions, array $measures, array $groups): array
    {
        $results = [];

        foreach ($dimensions as $dimension) {
            $dimensionName = $dimension['name'];
            $groupEntries = [];

            foreach ($groups[$dimensionName] as $value => $state) {
                $metrics = [];

                foreach ($measures as $measureName) {
                    $sum = $state['measures'][$measureName]['sum'];
                    $count = $state['measures'][$measureName]['count'];

                    $metrics[$measureName] = [
                        'sum' => $sum,
                        'count' => $count,
                        'avg' => $count > 0 ? $sum / $count : null,
                    ];
                }

                $groupEntries[] = [
                    // PHP casts canonical-integer-looking string array
                    // keys (e.g. "123") to int; cast back to string so a
                    // purely-numeric dimension value round-trips correctly
                    // (see DataProfilingAction::buildCategoricalSummaries
                    // for the same handling of this quirk).
                    'value' => (string) $value,
                    'count' => $state['row_count'],
                    'metrics' => $metrics,
                ];
            }

            usort($groupEntries, static function (array $a, array $b): int {
                return $b['count'] <=> $a['count'] ?: $a['value'] <=> $b['value'];
            });

            $results[] = [
                'dimension' => $dimensionName,
                'group_count' => count($groupEntries),
                'groups' => $groupEntries,
            ];
        }

        return $results;
    }

    /**
     * Enforce max_aggregated_rows across all included dimensions combined.
     *
     * Dimensions are already ordered by ascending cardinality (see
     * selectDimensions()), so the budget is spent on the cheapest / most
     * broadly useful dimensions first. The first dimension that would
     * overflow the remaining budget has its lowest-volume groups dropped
     * (groups are already sorted by row count descending, so a slice from
     * the front keeps the highest-volume groups); every dimension after
     * that is dropped entirely. This never raises an exception — it
     * always returns a valid, safely-narrowed result.
     *
     * @param list<array{dimension: string, group_count: int, groups: list<array<string, mixed>>}> $dimensionResults
     * @return list<array{dimension: string, group_count: int, groups: list<array<string, mixed>>}>
     */
    private function applyAggregatedRowsLimit(array $dimensionResults): array
    {
        $budget = max((int) config('metric_aggregation.max_aggregated_rows', 100), 0);
        $limited = [];

        foreach ($dimensionResults as $dimensionResult) {
            if ($budget <= 0) {
                break;
            }

            $groupCount = count($dimensionResult['groups']);

            if ($groupCount > $budget) {
                $dimensionResult['groups'] = array_slice($dimensionResult['groups'], 0, $budget);
                $dimensionResult['group_count'] = count($dimensionResult['groups']);
                $budget = 0;
            } else {
                $budget -= $groupCount;
            }

            $limited[] = $dimensionResult;
        }

        return $limited;
    }
}
