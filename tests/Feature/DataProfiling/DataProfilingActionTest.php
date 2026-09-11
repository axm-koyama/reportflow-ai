<?php

declare(strict_types=1);

namespace Tests\Feature\DataProfiling;

use App\Actions\DataProfiling\DataProfilingAction;
use App\Models\DataFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DataProfilingActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Build an unpersisted DataFile pointing at CSV content written to the
     * faked 'local' disk. DataProfilingAction never queries the database
     * (it only reads stored_path / original_name attributes), so the
     * model does not need to be persisted and RefreshDatabase is not used
     * in this test class.
     */
    private function createDataFileFromCsv(string $csv, string $originalName = 'sample.csv'): DataFile
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';

        Storage::disk('local')->put($storedPath, $csv);

        return DataFile::factory()->make([
            'project_id' => 1,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
        ]);
    }

    /**
     * 1. Basic Profile
     */
    public function test_it_generates_a_basic_profile_from_a_valid_csv(): void
    {
        $csv = "date,region,sales_amount\n"
            ."2026-01-01,Tokyo,100\n"
            ."2026-01-02,Osaka,200\n"
            ."2026-01-03,Tokyo,300\n";

        $dataFile = $this->createDataFileFromCsv($csv, 'sales.csv');

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('sales.csv', $profile['file']['name']);
        $this->assertSame(3, $profile['file']['row_count']);
        $this->assertSame(3, $profile['file']['column_count']);

        $this->assertSame(
            ['date', 'region', 'sales_amount'],
            array_column($profile['columns'], 'name'),
        );

        $this->assertLessThanOrEqual(10, count($profile['sample_rows']));

        foreach ($profile['sample_rows'] as $row) {
            $this->assertSame(['date', 'region', 'sales_amount'], array_keys($row));
        }
    }

    /**
     * 2. Column Counts
     */
    public function test_it_counts_null_non_null_and_unique_values(): void
    {
        $csv = "name,region\n"
            ."Alice,Tokyo\n"
            ."Bob,\n"
            ."Charlie,Tokyo\n"
            ."Dave,Osaka\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $region = $this->columnByName($profile, 'region');

        $this->assertSame(3, $region['non_null_count']);
        $this->assertSame(1, $region['null_count']);
        $this->assertSame(2, $region['unique_count']);
    }

    /**
     * 3. Null Handling: only '' is missing; NULL / N/A / #N/A / - / null (lowercase) are valid values
     */
    public function test_only_empty_string_is_treated_as_missing(): void
    {
        $csv = "value\n"
            ."\n" // becomes a fully blank physical line; see blank-line test separately.
            ."NULL\n"
            ."N/A\n"
            ."#N/A\n"
            ."-\n"
            ."null\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        // The blank physical line is skipped entirely (not a data row), so
        // only the 5 literal-value rows remain.
        $this->assertSame(5, $profile['file']['row_count']);

        $value = $this->columnByName($profile, 'value');

        $this->assertSame(5, $value['non_null_count']);
        $this->assertSame(0, $value['null_count']);
        $this->assertSame(5, $value['unique_count']);

        $topValues = array_column(
            $profile['categorical_summaries'][0]['top_values'],
            'value',
        );

        sort($topValues);
        $this->assertSame(['#N/A', '-', 'N/A', 'NULL', 'null'], $topValues);
    }

    /**
     * 3b. Null Handling: '' inside an otherwise non-empty column is missing
     */
    public function test_empty_string_field_is_counted_as_missing(): void
    {
        $csv = "name,note\nAlice,\nBob,ok\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $note = $this->columnByName($profile, 'note');

        $this->assertSame(1, $note['non_null_count']);
        $this->assertSame(1, $note['null_count']);
    }

    /**
     * 4. Integer Type
     */
    public function test_integer_column_produces_numeric_statistics(): void
    {
        $csv = "amount\n100\n200\n-50\n0\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('integer', $this->columnByName($profile, 'amount')['inferred_type']);

        $stats = $profile['numeric_statistics'][0];

        $this->assertSame('amount', $stats['column']);
        $this->assertSame(4, $stats['count']);
        $this->assertSame(-50, $stats['min']);
        $this->assertSame(200, $stats['max']);
        $this->assertSame(62.5, $stats['mean']);
    }

    /**
     * 5a. Decimal Type
     */
    public function test_decimal_column_produces_numeric_statistics(): void
    {
        $csv = "price\n10.5\n20.0\n-5.5\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('decimal', $this->columnByName($profile, 'price')['inferred_type']);

        $stats = $profile['numeric_statistics'][0];

        $this->assertSame(3, $stats['count']);
        $this->assertSame(-5.5, $stats['min']);
        $this->assertSame(20.0, $stats['max']);
    }

    /**
     * 5b. integer + decimal mix is inferred as decimal
     */
    public function test_mixed_integer_and_decimal_values_are_inferred_as_decimal(): void
    {
        $csv = "price\n10\n20.5\n30\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('decimal', $this->columnByName($profile, 'price')['inferred_type']);
        $this->assertCount(1, $profile['numeric_statistics']);
        $this->assertSame(3, $profile['numeric_statistics'][0]['count']);
    }

    /**
     * 6. Leading Zero
     */
    public function test_leading_zero_values_are_inferred_as_string_and_kept_unmodified(): void
    {
        $csv = "customer_code\n00123\n00456\n00889\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'customer_code')['inferred_type']);
        $this->assertEmpty($profile['numeric_statistics']);

        $topValues = array_column(
            $profile['categorical_summaries'][0]['top_values'],
            'value',
        );

        sort($topValues);
        $this->assertSame(['00123', '00456', '00889'], $topValues);
    }

    /**
     * 7. Mixed Type (non-numeric value present, within the default sample window)
     */
    public function test_mixed_type_column_falls_back_to_string_and_is_excluded_from_numeric_statistics(): void
    {
        $csv = "value\n100\n200\nABC\n300\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'value')['inferred_type']);
        $this->assertEmpty($profile['numeric_statistics']);
    }

    /**
     * 8a. Date Type (YYYY-MM-DD and YYYY/MM/DD)
     */
    public function test_dash_and_slash_formatted_dates_are_inferred_as_date(): void
    {
        $dashDataFile = $this->createDataFileFromCsv("date\n2026-01-01\n2026-02-10\n");
        $dashProfile = (new DataProfilingAction)->execute($dashDataFile);
        $this->assertSame('date', $this->columnByName($dashProfile, 'date')['inferred_type']);

        $slashDataFile = $this->createDataFileFromCsv("date\n2026/01/01\n2026/02/10\n");
        $slashProfile = (new DataProfilingAction)->execute($slashDataFile);
        $this->assertSame('date', $this->columnByName($slashProfile, 'date')['inferred_type']);
    }

    /**
     * 8b. Invalid calendar date is not treated as a date
     */
    public function test_invalid_calendar_date_is_not_inferred_as_date(): void
    {
        // One valid + one calendar-invalid date: if the invalid one were
        // (incorrectly) accepted as a date, the column would be "date". It
        // is expected to fall back to "string" (mixed), proving the
        // invalid value was excluded from the date candidate.
        $csv = "date\n2026-01-01\n2026-13-01\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'date')['inferred_type']);
    }

    /**
     * 9a. Datetime Type
     */
    public function test_space_and_iso_t_separated_datetimes_are_inferred_as_datetime(): void
    {
        $csv = "created_at\n2026-01-01 10:30:00\n2026-01-02T11:45:30\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('datetime', $this->columnByName($profile, 'created_at')['inferred_type']);
    }

    /**
     * 9b. Invalid time is not treated as datetime
     */
    public function test_invalid_time_is_not_inferred_as_datetime(): void
    {
        $csv = "created_at\n2026-01-01 10:30:00\n2026-01-01 25:00:00\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'created_at')['inferred_type']);
    }

    /**
     * 10a. Boolean Type (case-insensitive true/false)
     */
    public function test_true_false_values_are_inferred_as_boolean(): void
    {
        $csv = "active\ntrue\nfalse\nTRUE\nFALSE\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('boolean', $this->columnByName($profile, 'active')['inferred_type']);
    }

    /**
     * 10b. 0 / 1 are integer, not boolean
     */
    public function test_zero_and_one_are_inferred_as_integer_not_boolean(): void
    {
        $csv = "flag\n0\n1\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('integer', $this->columnByName($profile, 'flag')['inferred_type']);
    }

    /**
     * 11. Unknown Type: a column that is entirely missing across all rows
     */
    public function test_a_column_with_no_non_null_values_is_inferred_as_unknown(): void
    {
        $csv = "name,value\nAlice,\nBob,\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $value = $this->columnByName($profile, 'value');

        $this->assertSame('unknown', $value['inferred_type']);
        $this->assertSame(0, $value['non_null_count']);
        $this->assertSame(2, $value['null_count']);
    }

    /**
     * 12. Categorical Summary: count DESC, value ASC tiebreak
     */
    public function test_categorical_summary_is_ordered_by_count_desc_then_value_asc(): void
    {
        $csv = "region\nTokyo\nOsaka\nTokyo\nNagoya\nTokyo\nOsaka\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame(
            [
                ['value' => 'Tokyo', 'count' => 3],
                ['value' => 'Osaka', 'count' => 2],
                ['value' => 'Nagoya', 'count' => 1],
            ],
            $profile['categorical_summaries'][0]['top_values'],
        );
    }

    /**
     * 12b. Deterministic tiebreak for equal counts (value ASC)
     */
    public function test_categorical_summary_ties_are_broken_by_value_ascending(): void
    {
        $csv = "region\nCharlie\nAlpha\nBravo\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame(
            ['Alpha', 'Bravo', 'Charlie'],
            array_column($profile['categorical_summaries'][0]['top_values'], 'value'),
        );
    }

    /**
     * 13. categorical_top_values limit (runtime config override; file untouched)
     */
    public function test_categorical_top_values_respects_the_configured_limit(): void
    {
        config(['data_profiling.categorical_top_values' => 2]);

        $csv = "region\nTokyo\nOsaka\nNagoya\nFukuoka\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertCount(2, $profile['categorical_summaries'][0]['top_values']);
    }

    /**
     * 14. sample_rows limit (runtime config override; file untouched)
     */
    public function test_sample_rows_respects_the_configured_limit(): void
    {
        config(['data_profiling.sample_rows' => 3]);

        $csv = "id\n".implode("\n", range(1, 20))."\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertCount(3, $profile['sample_rows']);

        foreach ($profile['sample_rows'] as $row) {
            $this->assertSame(['id'], array_keys($row));
        }
    }

    /**
     * 15. sample_rows = 0
     */
    public function test_sample_rows_can_be_disabled_via_config(): void
    {
        config(['data_profiling.sample_rows' => 0]);

        $csv = "id\n1\n2\n3\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame([], $profile['sample_rows']);
    }

    /**
     * 16. UTF-8 BOM Header is stripped from the first column name
     */
    public function test_utf8_bom_is_stripped_from_the_first_header_column(): void
    {
        $csv = "\xEF\xBB\xBFdate,region\n2026-01-01,Tokyo\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame(
            ['date', 'region'],
            array_column($profile['columns'], 'name'),
        );
    }

    /**
     * 17a. UTF-8 Validation: invalid bytes in the header
     */
    public function test_invalid_utf8_in_the_header_throws(): void
    {
        $invalidHeaderName = mb_convert_encoding('日本語', 'SJIS', 'UTF-8');
        $csv = "{$invalidHeaderName}\nfoo\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8');

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 17b. UTF-8 Validation: invalid bytes in a data row
     */
    public function test_invalid_utf8_in_a_data_row_throws(): void
    {
        $invalidValue = mb_convert_encoding('日本語', 'SJIS', 'UTF-8');
        $csv = "name\n{$invalidValue}\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8');

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 18. Empty CSV (0 bytes)
     */
    public function test_a_zero_byte_csv_throws(): void
    {
        $dataFile = $this->createDataFileFromCsv('');

        $this->expectException(RuntimeException::class);

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 19. Empty Header Name
     */
    public function test_an_empty_header_column_name_throws(): void
    {
        $csv = "date,,sales\n2026-01-01,x,100\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty column name');

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 20. Duplicate Header
     */
    public function test_a_duplicate_header_column_name_throws(): void
    {
        $csv = "date,region,region\n2026-01-01,Tokyo,Tokyo\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate column');

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 21. Row Column Shortage
     */
    public function test_a_row_with_fewer_columns_than_the_header_throws(): void
    {
        $csv = "date,region,sales\n2026-01-01,Tokyo\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $this->expectException(RuntimeException::class);

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 22. Row Column Excess
     */
    public function test_a_row_with_more_columns_than_the_header_throws(): void
    {
        $csv = "date,region,sales\n2026-01-01,Tokyo,100,extra\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $this->expectException(RuntimeException::class);

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 23. Blank Lines are not counted as data rows and do not break profiling
     */
    public function test_a_blank_line_in_the_middle_of_the_csv_is_not_counted_as_a_row(): void
    {
        $csv = "date,region\n2026-01-01,Tokyo\n\n2026-01-02,Osaka\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame(2, $profile['file']['row_count']);
        $this->assertSame(2, $this->columnByName($profile, 'region')['non_null_count']);
    }

    /**
     * 24. Missing Storage File
     */
    public function test_a_missing_stored_file_throws(): void
    {
        $dataFile = DataFile::factory()->make([
            'project_id' => 1,
            'stored_path' => 'projects/1/data-files/does-not-exist.csv',
        ]);

        $this->expectException(RuntimeException::class);

        (new DataProfilingAction)->execute($dataFile);
    }

    /**
     * 25. File Metadata: original_name is used, not the stored UUID path
     */
    public function test_file_name_uses_the_original_name_not_the_stored_path(): void
    {
        $csv = "a,b\n1,2\n";

        $dataFile = $this->createDataFileFromCsv($csv, 'customer-list.csv');

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('customer-list.csv', $profile['file']['name']);
        $this->assertStringNotContainsString($dataFile->stored_path, $profile['file']['name']);
    }

    /**
     * 26. Exact unique_count from the frequency map
     */
    public function test_unique_count_is_exact(): void
    {
        $csv = "code\nA\nA\nB\nC\nC\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame(3, $this->columnByName($profile, 'code')['unique_count']);
    }

    /**
     * 27. Numeric column is not included in categorical_summaries
     */
    public function test_numeric_columns_are_excluded_from_categorical_summaries(): void
    {
        $csv = "amount\n100\n200\n300\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertEmpty($profile['categorical_summaries']);
    }

    /**
     * 28. String column is included in categorical_summaries
     */
    public function test_string_columns_are_included_in_categorical_summaries(): void
    {
        $csv = "region\nTokyo\nOsaka\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame(
            ['region'],
            array_column($profile['categorical_summaries'], 'column'),
        );
    }

    /**
     * 29. numeric_statistics is only produced for the final inferred type,
     * not merely because some values happened to parse as numeric.
     */
    public function test_numeric_statistics_are_omitted_when_the_column_falls_back_to_string(): void
    {
        $csv = "value\n100\n200\nABC\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'value')['inferred_type']);
        $this->assertEmpty($profile['numeric_statistics']);
    }

    /**
     * 30. Type inference considers ALL distinct non-null values, not a
     * bounded sample.
     *
     * Regression test: a differently-typed value that appears only after
     * more than 100 distinct numeric values (the old, now-removed
     * type_inference_sample_size default) must still trigger the string
     * fallback, since the frequency map already holds every distinct
     * value and inferColumnType() now examines all of them.
     */
    public function test_a_late_appearing_non_numeric_value_still_falls_back_to_string(): void
    {
        $values = range(1, 101); // 101 distinct integer values
        $values[] = 'ABC';       // a 102nd, non-numeric distinct value

        $csv = "value\n".implode("\n", $values)."\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $column = $this->columnByName($profile, 'value');

        $this->assertSame('string', $column['inferred_type']);
        $this->assertSame(102, $column['non_null_count']);
        $this->assertEmpty($profile['numeric_statistics']);
    }

    /**
     * 8e. Mixed separators within a single date value are rejected
     * (dash then slash): "2026-01/01" is not a date.
     */
    public function test_dash_then_slash_separator_date_is_not_inferred_as_date(): void
    {
        $csv = "date\n2026-01/01\n2026-02/10\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'date')['inferred_type']);
    }

    /**
     * 8f. Mixed separators within a single date value are rejected
     * (slash then dash): "2026/01-01" is not a date.
     */
    public function test_slash_then_dash_separator_date_is_not_inferred_as_date(): void
    {
        $csv = "date\n2026/01-01\n2026/02-10\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        $this->assertSame('string', $this->columnByName($profile, 'date')['inferred_type']);
    }

    /**
     * 31. Config defaults are honored end-to-end without a runtime override
     */
    public function test_default_config_values_are_used_when_not_overridden(): void
    {
        $this->assertSame(10, config('data_profiling.sample_rows'));
        $this->assertSame(10, config('data_profiling.categorical_top_values'));

        $csv = "id\n".implode("\n", range(1, 15))."\n";

        $dataFile = $this->createDataFileFromCsv($csv);

        $profile = (new DataProfilingAction)->execute($dataFile);

        // 15 rows generated, default sample_rows cap is 10.
        $this->assertCount(10, $profile['sample_rows']);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function columnByName(array $profile, string $name): array
    {
        foreach ($profile['columns'] as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        $this->fail("Column \"{$name}\" not found in profile.");
    }
}
