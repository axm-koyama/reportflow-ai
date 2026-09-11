<?php

declare(strict_types=1);

namespace Tests\Feature\DataProfiling;

use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\DataProfiling\MetricAggregationAction;
use App\Models\DataFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetricAggregationActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Build an unpersisted DataFile pointing at CSV content written to the
     * faked 'local' disk, and run the real DataProfilingAction over it so
     * MetricAggregationAction receives a genuine Data Profile (matching
     * how ExecuteAnalysisJobAction actually calls it).
     *
     * @return array{0: DataFile, 1: array<string, mixed>}
     */
    private function profiledDataFile(string $csv, string $originalName = 'sample.csv'): array
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';

        Storage::disk('local')->put($storedPath, $csv);

        $dataFile = DataFile::factory()->make([
            'project_id' => 1,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
        ]);

        $dataProfile = (new DataProfilingAction)->execute($dataFile);

        return [$dataFile, $dataProfile];
    }

    /**
     * @param array<string, mixed> $aggregatedMetrics
     * @return array<string, mixed>
     */
    private function dimension(array $aggregatedMetrics, string $name): array
    {
        foreach ($aggregatedMetrics['dimensions'] as $dimension) {
            if ($dimension['dimension'] === $name) {
                return $dimension;
            }
        }

        $this->fail("Dimension \"{$name}\" not found in aggregated metrics.");
    }

    /**
     * @param array<string, mixed> $dimension
     * @return array<string, mixed>
     */
    private function group(array $dimension, string $value): array
    {
        foreach ($dimension['groups'] as $group) {
            if ($group['value'] === $value) {
                return $group;
            }
        }

        $this->fail("Group \"{$value}\" not found in dimension \"{$dimension['dimension']}\".");
    }

    /**
     * 1. Dimension判定: categorical column (string, cardinality内) が
     *    dimensionとして選ばれる。numeric column は選ばれない。
     */
    public function test_it_selects_categorical_columns_as_dimensions(): void
    {
        $csv = "channel,spend\nEmail,100\nSocial,200\nEmail,150\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertSame(['channel'], array_column($metrics['dimensions'], 'dimension'));
    }

    /**
     * 1b. Dimension判定: unique_count < 2 (定数列) は候補から除外される。
     */
    public function test_a_column_with_a_single_distinct_value_is_not_a_dimension_candidate(): void
    {
        $csv = "channel,region,spend\nEmail,Tokyo,100\nSocial,Tokyo,200\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertNotContains('region', array_column($metrics['dimensions'], 'dimension'));
        $this->assertContains('channel', array_column($metrics['dimensions'], 'dimension'));
    }

    /**
     * 2. Measure判定: numeric column が measure として選ばれる。
     *    non-numeric column は選ばれない。
     */
    public function test_it_selects_numeric_columns_as_measures(): void
    {
        $csv = "channel,spend,label\nEmail,100,x\nSocial,200,y\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertSame(['spend'], $metrics['measures']);
    }

    /**
     * 3/4/5. group-by, sum, count, avg が正しく計算される。
     */
    public function test_it_computes_sum_count_and_avg_per_group(): void
    {
        $csv = "channel,spend\nEmail,100\nEmail,200\nSocial,50\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);
        $dimension = $this->dimension($metrics, 'channel');

        $email = $this->group($dimension, 'Email');
        $this->assertSame(2, $email['count']);
        $this->assertSame(300, $email['metrics']['spend']['sum']);
        $this->assertSame(2, $email['metrics']['spend']['count']);
        // 300 / 2: PHP's "/" returns int when both operands are int and
        // evenly divisible.
        $this->assertSame(150, $email['metrics']['spend']['avg']);

        $social = $this->group($dimension, 'Social');
        $this->assertSame(1, $social['count']);
        $this->assertSame(50, $social['metrics']['spend']['sum']);
        $this->assertSame(1, $social['metrics']['spend']['count']);
        $this->assertSame(50, $social['metrics']['spend']['avg']);
    }

    /**
     * 6. NULL値: measureが空(NULL相当)の行はそのmeasureのsum/countから除外される。
     *    dimensionの行数(count)自体には影響しない。
     */
    public function test_null_measure_values_are_excluded_from_sum_and_count(): void
    {
        // A second distinct channel value is included solely so "channel"
        // clears the >= 2 distinct-values dimension threshold; assertions
        // below only look at the "Email" group.
        $csv = "channel,spend\nEmail,100\nEmail,\nSocial,999\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);
        $dimension = $this->dimension($metrics, 'channel');
        $email = $this->group($dimension, 'Email');

        $this->assertSame(2, $email['count']);
        $this->assertSame(100, $email['metrics']['spend']['sum']);
        $this->assertSame(1, $email['metrics']['spend']['count']);
        $this->assertSame(100, $email['metrics']['spend']['avg']);
    }

    /**
     * 6b. NULL値: dimensionが空(NULL相当)の行はそのdimensionのgroupingから
     *     除外される(擬似的な「不明」groupを作らない)。
     */
    public function test_rows_with_a_missing_dimension_value_are_excluded_from_grouping(): void
    {
        $csv = "channel,spend\nEmail,100\n,200\nSocial,50\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);
        $dimension = $this->dimension($metrics, 'channel');

        $this->assertSame(['Email', 'Social'], array_column($dimension['groups'], 'value'));
    }

    /**
     * 7. 空文字: NULLと同様にmissingとして扱われる。
     */
    public function test_empty_string_measure_values_are_treated_the_same_as_null(): void
    {
        // A quoted empty field ("") parses to '' (not null) from fgetcsv,
        // and must be treated identically to a genuinely missing value. A
        // second distinct channel value is included solely so "channel"
        // clears the >= 2 distinct-values dimension threshold.
        $csv = "channel,spend\nEmail,100\nEmail,\"\"\nSocial,1\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);
        $dimension = $this->dimension($metrics, 'channel');
        $email = $this->group($dimension, 'Email');

        $this->assertSame(1, $email['metrics']['spend']['count']);
        $this->assertSame(100, $email['metrics']['spend']['sum']);
    }

    /**
     * 8. 数値0: falsyな "0" が missing 扱いされず、sum/countに正しく含まれる。
     */
    public function test_a_measure_value_of_zero_is_included_not_treated_as_missing(): void
    {
        // A second distinct channel value is included solely so "channel"
        // clears the >= 2 distinct-values dimension threshold.
        $csv = "channel,spend\nEmail,0\nEmail,100\nSocial,1\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);
        $dimension = $this->dimension($metrics, 'channel');
        $email = $this->group($dimension, 'Email');

        $this->assertSame(2, $email['metrics']['spend']['count']);
        $this->assertSame(100, $email['metrics']['spend']['sum']);
        $this->assertSame(50, $email['metrics']['spend']['avg']);
    }

    /**
     * 9. cardinality上限: unique_count が max_cardinality_per_dimension を
     *    超える列は candidate から除外される(truncateではなく除外)。
     */
    public function test_a_column_above_the_cardinality_limit_is_excluded_entirely(): void
    {
        config(['metric_aggregation.max_cardinality_per_dimension' => 2]);

        $csv = "channel,spend\nA,1\nB,1\nC,1\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertSame([], $metrics['dimensions']);
    }

    /**
     * 10. dimension上限: candidate数が max_dimensions を超える場合、
     *     cardinalityが低い列から優先的に採用される。
     */
    public function test_only_the_lowest_cardinality_dimensions_are_kept_up_to_the_limit(): void
    {
        config(['metric_aggregation.max_dimensions' => 1]);

        // "channel" has 2 distinct values, "region" has 3: channel must win.
        $csv = "channel,region,spend\n"
            ."Email,Tokyo,100\n"
            ."Social,Osaka,100\n"
            ."Email,Nagoya,100\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertSame(['channel'], array_column($metrics['dimensions'], 'dimension'));
    }

    /**
     * 11. measure上限: candidate数が max_measures を超える場合、
     *     CSV列順で先頭から採用される。
     */
    public function test_only_the_first_n_measures_are_kept_up_to_the_limit(): void
    {
        config(['metric_aggregation.max_measures' => 1]);

        $csv = "channel,spend,conversions\nEmail,100,5\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertSame(['spend'], $metrics['measures']);
    }

    /**
     * 12. aggregated rows上限: 全dimension合計のgroup数が
     *     max_aggregated_rows を超える場合、例外を投げず安全に絞り込む。
     */
    public function test_aggregated_rows_are_safely_truncated_instead_of_throwing(): void
    {
        config([
            'metric_aggregation.max_dimensions' => 2,
            'metric_aggregation.max_aggregated_rows' => 3,
        ]);

        // "channel": 2 groups, "region": 3 groups => 5 total > budget of 3.
        // channel (lower cardinality) is prioritized and kept in full (2),
        // leaving a budget of 1 for region, which is truncated to its
        // single highest-row-count group ("Tokyo", with 2 rows).
        $csv = "channel,region,spend\n"
            ."Email,Tokyo,300\n"
            ."Email,Tokyo,150\n"
            ."Email,Osaka,100\n"
            ."Social,Nagoya,200\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $channel = $this->dimension($metrics, 'channel');
        $region = $this->dimension($metrics, 'region');

        $this->assertCount(2, $channel['groups']);
        $this->assertCount(1, $region['groups']);
        $this->assertSame(1, $region['group_count']);

        // The highest-volume region group (by row count) is kept.
        $this->assertSame('Tokyo', $region['groups'][0]['value']);

        $totalGroups = count($channel['groups']) + count($region['groups']);
        $this->assertSame(3, $totalGroups);
    }

    /**
     * Neither dimensions nor measures exist (no numeric column at all, and
     * the only categorical column is constant / unique_count < 2): the CSV
     * is never even opened for a second pass, and an empty-but-valid
     * structure is returned.
     */
    public function test_it_returns_an_empty_structure_when_there_are_no_dimension_or_measure_candidates(): void
    {
        $csv = "label\na\na\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);

        $this->assertSame(['dimensions' => [], 'measures' => []], $metrics);
    }

    /**
     * Groups within a dimension are ordered by row count (descending),
     * then by value (ascending) as a deterministic tiebreak.
     */
    public function test_groups_are_ordered_by_row_count_descending_then_value_ascending(): void
    {
        $csv = "channel,spend\n"
            ."Social,1\n"
            ."Email,1\nEmail,1\nEmail,1\n"
            ."Display,1\n";

        [$dataFile, $profile] = $this->profiledDataFile($csv);

        $metrics = (new MetricAggregationAction)->execute($dataFile, $profile);
        $dimension = $this->dimension($metrics, 'channel');

        $this->assertSame(
            ['Email', 'Display', 'Social'],
            array_column($dimension['groups'], 'value'),
        );
    }
}
