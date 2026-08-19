<?php

declare(strict_types=1);

namespace App\Actions\DataProfiling;

use App\Models\DataFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Generates a Data Profile from a DataFile's CSV content.
 *
 * A Data Profile is a compact, objective, structured summary of a CSV
 * (file metadata, per-column information, numeric statistics, categorical
 * summaries, and sample rows) used as AI Context. See:
 *
 * - docs/product/DATA_PROFILING.md
 * - docs/product/AI_ANALYSIS.md
 *
 * DataProfilingAction receives only a DataFile. It does not know about
 * AnalysisJob, prompts, or the AI provider, and it never calls the AI.
 *
 * The CSV is streamed (Storage::readStream() + fgetcsv()); the full file
 * content is never loaded into memory at once. Per-column aggregation
 * state is kept to a single frequency map (value => count) per column,
 * from which unique_count, categorical top_values, and the type-inference
 * sample are all derived, rather than keeping separate structures for
 * each.
 *
 * Out of scope: AI API calls, result normalization, report generation,
 * AnalysisJob status updates, and Data Profile persistence.
 */
class DataProfilingAction
{
    /**
     * Matches integers without a leading zero (so id-like values such as
     * "00123" are not misread as numbers and lose information).
     */
    private const string INTEGER_PATTERN = '/^-?(?:0|[1-9]\d*)$/';

    /**
     * Matches plain decimal numbers, e.g. "10.5", "-3.14", "100.00".
     */
    private const string DECIMAL_PATTERN = '/^-?(?:0|[1-9]\d*)\.\d+$/';

    /**
     * Matches YYYY-MM-DD or YYYY/MM/DD, requiring the same separator on
     * both sides (via backreference) so mixed-separator values such as
     * "2026-01/01" are rejected. Calendar validity (e.g. month 13) is
     * checked separately with checkdate().
     */
    private const string DATE_PATTERN = '/^(\d{4})([-\/])(\d{2})\2(\d{2})$/';

    /**
     * Matches "YYYY-MM-DD HH:MM:SS" or ISO-like "YYYY-MM-DDTHH:MM:SS".
     */
    private const string DATETIME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/';

    /**
     * Generate a Data Profile for the given DataFile.
     *
     * @param DataFile $dataFile
     * @return array<string, mixed>
     * @throws RuntimeException if the file cannot be opened/streamed, is
     *                           not valid UTF-8, or is not structurally
     *                           valid CSV (empty, invalid/duplicate/empty
     *                           header column, or a row whose column count
     *                           does not match the header).
     */
    public function execute(DataFile $dataFile): array
    {
        $stream = Storage::disk('local')->readStream($dataFile->stored_path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open data file for streaming: {$dataFile->stored_path}");
        }

        try {
            $header = $this->readHeader($stream);
            $state = $this->initColumnState($header);

            $streamed = $this->streamRows($stream, $header, $state);

            foreach ($state as $columnName => &$columnState) {
                $columnState['inferred_type'] = $this->inferColumnType($columnState['frequency']);
            }
            unset($columnState);

            return [
                'file' => [
                    'name' => $dataFile->original_name,
                    'row_count' => $streamed['row_count'],
                    'column_count' => count($header),
                ],
                'columns' => $this->buildColumns($state),
                'numeric_statistics' => $this->buildNumericStatistics($state),
                'categorical_summaries' => $this->buildCategoricalSummaries($state),
                'sample_rows' => $streamed['sample_rows'],
            ];
        } finally {
            fclose($stream);
        }
    }

    /**
     * Read and validate the CSV header row.
     *
     * @param resource $stream
     * @return list<string>
     * @throws RuntimeException
     */
    private function readHeader($stream): array
    {
        $header = fgetcsv($stream, escape: '\\');

        if ($header === false || $header === [null]) {
            throw new RuntimeException('CSV file is empty or its header row could not be read.');
        }

        $trimmed = [];

        foreach ($header as $index => $name) {
            $name = (string) $name;

            if (! mb_check_encoding($name, 'UTF-8')) {
                throw new RuntimeException('CSV header contains data that is not valid UTF-8.');
            }

            // Remove UTF-8 BOM from the first column name.
            if ($index === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
            }

            $trimmed[] = trim($name);
        }

        if (count($trimmed) === 0) {
            throw new RuntimeException('CSV header must contain at least one column.');
        }

        foreach ($trimmed as $name) {
            if ($name === '') {
                throw new RuntimeException('CSV header must not contain an empty column name.');
            }
        }

        if (count($trimmed) !== count(array_unique($trimmed))) {
            throw new RuntimeException('CSV header must not contain duplicate column names.');
        }

        return $trimmed;
    }

    /**
     * Build the initial per-column aggregation state.
     *
     * @param list<string> $header
     * @return array<string, array<string, mixed>>
     */
    private function initColumnState(array $header): array
    {
        $state = [];

        foreach ($header as $name) {
            $state[$name] = [
                'non_null_count' => 0,
                'null_count' => 0,
                'frequency' => [],
                'numeric_count' => 0,
                'numeric_sum' => 0,
                'numeric_min' => null,
                'numeric_max' => null,
            ];
        }

        return $state;
    }

    /**
     * Stream the CSV data rows, updating per-column state and collecting a
     * reservoir sample of rows as it goes.
     *
     * @param resource $stream
     * @param list<string> $header
     * @param array<string, array<string, mixed>> $state
     * @return array{row_count: int, sample_rows: list<array<string, string|null>>}
     * @throws RuntimeException
     */
    private function streamRows($stream, array $header, array &$state): array
    {
        $columnCount = count($header);
        $sampleSize = (int) config('data_profiling.sample_rows', 10);
        $sampleRows = [];
        $rowCount = 0;

        while (($row = fgetcsv($stream, escape: '\\')) !== false) {
            if ($row === [null]) {
                // Unintentional blank line; not counted as a data row.
                continue;
            }

            if (count($row) !== $columnCount) {
                throw new RuntimeException(sprintf(
                    'CSV row %d has %d column(s), expected %d.',
                    $rowCount + 1,
                    count($row),
                    $columnCount,
                ));
            }

            $rowCount++;

            foreach ($header as $index => $columnName) {
                $value = $row[$index];

                if ($value !== null && ! mb_check_encoding($value, 'UTF-8')) {
                    throw new RuntimeException(sprintf(
                        'CSV row %d, column "%s" contains data that is not valid UTF-8.',
                        $rowCount,
                        $columnName,
                    ));
                }

                $this->recordValue($state[$columnName], $value);
            }

            // Reservoir sampling (Algorithm R): keeps a uniformly random
            // sample of `sampleSize` rows across the whole stream without
            // buffering the entire CSV in memory.
            if ($sampleSize > 0) {
                if ($rowCount <= $sampleSize) {
                    $sampleRows[] = array_combine($header, $row);
                } else {
                    $j = mt_rand(1, $rowCount);

                    if ($j <= $sampleSize) {
                        $sampleRows[$j - 1] = array_combine($header, $row);
                    }
                }
            }
        }

        return ['row_count' => $rowCount, 'sample_rows' => array_values($sampleRows)];
    }

    /**
     * Update one column's aggregation state with a single row's value.
     *
     * @param array<string, mixed> $columnState
     * @param string|null $value
     * @return void
     */
    private function recordValue(array &$columnState, ?string $value): void
    {
        if ($value === null || $value === '') {
            $columnState['null_count']++;

            return;
        }

        $columnState['non_null_count']++;
        $columnState['frequency'][$value] = ($columnState['frequency'][$value] ?? 0) + 1;

        $numeric = $this->toNumeric($value);

        if ($numeric === null) {
            return;
        }

        $columnState['numeric_count']++;
        $columnState['numeric_sum'] += $numeric;
        $columnState['numeric_min'] = $columnState['numeric_min'] === null
            ? $numeric
            : min($columnState['numeric_min'], $numeric);
        $columnState['numeric_max'] = $columnState['numeric_max'] === null
            ? $numeric
            : max($columnState['numeric_max'], $numeric);
    }

    /**
     * Infer a column's overall type from ALL of its distinct non-null
     * values (the frequency map's keys). The frequency map already holds
     * every distinct value seen while streaming, so no separate sampling
     * pass or bound is needed here; a differently-typed value anywhere in
     * the column reliably triggers the string fallback below, matching
     * the "mixed types fall back to string" design principle.
     *
     * @param array<int|string, int> $frequency
     * @return string
     */
    private function inferColumnType(array $frequency): string
    {
        if (count($frequency) === 0) {
            return 'unknown';
        }

        $types = [];

        foreach (array_keys($frequency) as $value) {
            // PHP casts canonical-integer-looking string keys (e.g.
            // "123") to int array keys, so cast back to string before
            // classifying.
            $types[] = $this->classifyValue((string) $value);
        }

        $uniqueTypes = array_values(array_unique($types));
        sort($uniqueTypes);

        return match (true) {
            $uniqueTypes === ['integer'] => 'integer',
            $uniqueTypes === ['decimal'] || $uniqueTypes === ['decimal', 'integer'] => 'decimal',
            $uniqueTypes === ['date'] => 'date',
            $uniqueTypes === ['datetime'] => 'datetime',
            $uniqueTypes === ['boolean'] => 'boolean',
            default => 'string',
        };
    }

    /**
     * Classify a single value's shape. This decides one value's type
     * candidate; the column's overall inferred_type is decided separately
     * by inferColumnType() from many such candidates.
     *
     * @param string $value
     * @return string
     */
    private function classifyValue(string $value): string
    {
        if (preg_match(self::INTEGER_PATTERN, $value) === 1) {
            return 'integer';
        }

        if (preg_match(self::DECIMAL_PATTERN, $value) === 1) {
            return 'decimal';
        }

        if ($this->isDate($value)) {
            return 'date';
        }

        if ($this->isDatetime($value)) {
            return 'datetime';
        }

        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return 'boolean';
        }

        return 'string';
    }

    /**
     * Parse a value as an int/float if (and only if) it matches the
     * strict integer/decimal shapes used for type inference, so numeric
     * aggregation stays consistent with the inferred column type.
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
     * @param string $value
     * @return bool
     */
    private function isDate(string $value): bool
    {
        if (preg_match(self::DATE_PATTERN, $value, $matches) !== 1) {
            return false;
        }

        // Capture groups: 1=year, 2=separator, 3=month, 4=day.
        return checkdate((int) $matches[3], (int) $matches[4], (int) $matches[1]);
    }

    /**
     * @param string $value
     * @return bool
     */
    private function isDatetime(string $value): bool
    {
        if (preg_match(self::DATETIME_PATTERN, $value, $matches) !== 1) {
            return false;
        }

        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return false;
        }

        return (int) $matches[4] <= 23 && (int) $matches[5] <= 59 && (int) $matches[6] <= 59;
    }

    /**
     * @param array<string, array<string, mixed>> $state
     * @return list<array<string, mixed>>
     */
    private function buildColumns(array $state): array
    {
        $columns = [];

        foreach ($state as $name => $columnState) {
            $columns[] = [
                'name' => $name,
                'inferred_type' => $columnState['inferred_type'],
                'non_null_count' => $columnState['non_null_count'],
                'null_count' => $columnState['null_count'],
                'unique_count' => count($columnState['frequency']),
            ];
        }

        return $columns;
    }

    /**
     * @param array<string, array<string, mixed>> $state
     * @return list<array<string, mixed>>
     */
    private function buildNumericStatistics(array $state): array
    {
        $statistics = [];

        foreach ($state as $name => $columnState) {
            if (! in_array($columnState['inferred_type'], ['integer', 'decimal'], true)) {
                continue;
            }

            if ($columnState['numeric_count'] === 0) {
                continue;
            }

            $statistics[] = [
                'column' => $name,
                'count' => $columnState['numeric_count'],
                'min' => $columnState['numeric_min'],
                'max' => $columnState['numeric_max'],
                'mean' => $columnState['numeric_sum'] / $columnState['numeric_count'],
            ];
        }

        return $statistics;
    }

    /**
     * @param array<string, array<string, mixed>> $state
     * @return list<array<string, mixed>>
     */
    private function buildCategoricalSummaries(array $state): array
    {
        $limit = (int) config('data_profiling.categorical_top_values', 10);
        $summaries = [];

        foreach ($state as $name => $columnState) {
            if ($columnState['inferred_type'] !== 'string') {
                continue;
            }

            if (count($columnState['frequency']) === 0) {
                continue;
            }

            $entries = [];

            foreach ($columnState['frequency'] as $value => $count) {
                // See inferColumnType(): frequency map keys may have been
                // cast to int by PHP; restore the original string value.
                $entries[] = ['value' => (string) $value, 'count' => $count];
            }

            usort($entries, static function (array $a, array $b): int {
                return $b['count'] <=> $a['count'] ?: $a['value'] <=> $b['value'];
            });

            $summaries[] = [
                'column' => $name,
                'top_values' => array_slice($entries, 0, max($limit, 0)),
            ];
        }

        return $summaries;
    }
}
