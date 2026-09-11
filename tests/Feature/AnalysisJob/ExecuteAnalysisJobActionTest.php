<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\BuildAnalysisTemplateColumnCandidatesAction;
use App\Actions\AnalysisJob\CalculateDerivedMetricsAction;
use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Actions\AnalysisJob\RecoverFailedAnalysisJobAction;
use App\Actions\AnalysisJob\ResolveEffectiveColumnMappingAction;
use App\Actions\AnalysisJob\UpdateAnalysisJobAction;
use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\DataProfiling\MetricAggregationAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ExecuteAnalysisJobActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Create a Pending AnalysisJob backed by a DataFile with real,
     * DataProfilingAction-readable CSV content on the faked 'local' disk.
     * DataProfilingAction / BuildAnalysisContextAction are exercised for
     * real (not mocked) in this test class — only AiAnalysisClient, the
     * actual external I/O boundary, is mocked. See AI_CONTEXT.md / this
     * sprint's report for why the internal pipeline steps are not
     * individually re-verified here (DataProfilingAction and
     * BuildAnalysisContextAction already have dedicated test coverage).
     *
     * @return array{0: DataFile, 1: AnalysisJob, 2: AnalysisJobDetail}
     */
    private function createPendingAnalysisJob(string $prompt): array
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put($storedPath, "region,sales,cost\nTokyo,100,50\nOsaka,200,80\n");

        $dataFile = DataFile::factory()->create([
            'stored_path' => $storedPath,
            'original_name' => 'sales.csv',
        ]);

        $analysisJob = AnalysisJob::factory()->for($dataFile)->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['prompt' => $prompt]);

        return [$dataFile, $analysisJob, $detail];
    }

    /**
     * A planMetrics() response proposing no derived metrics — a valid,
     * common outcome used by tests that don't care about Derived Metrics
     * content, only that the pipeline reaches the final analysis call.
     */
    private function emptyPlanResponse(): string
    {
        return json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredResult(): array
    {
        return [
            'summary' => '売上が減少しました',
            'highlights' => ['関東で減少'],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
            'recommendations' => [],
        ];
    }

    /**
     * Success flow: Pending -> Processing -> Completed, through the full
     * V2 pipeline (DataProfiling -> Aggregation -> Planning -> Calculation
     * -> BuildAnalysisContext -> AiAnalysisClient::analyze() -> Normalize
     * -> markCompleted).
     */
    public function test_it_completes_a_pending_analysis_job_on_success(): void
    {
        [$dataFile, $analysisJob, $detail] = $this->createPendingAnalysisJob(
            'CSVを分析して傾向を要約してください。',
        );

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $planResponse = json_encode([
            'derived_metrics' => [
                [
                    'name' => 'ROAS',
                    'operator' => 'divide',
                    'left' => ['metric' => 'sales', 'aggregation' => 'sum'],
                    'right' => ['metric' => 'cost', 'aggregation' => 'sum'],
                    'group_by' => 'region',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $capturedContext = null;
        $capturedPlanningContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($planResponse)
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        // --- Metric Planning Context contract ---
        $this->assertIsArray($capturedPlanningContext);
        $this->assertSame($detail->prompt, $capturedPlanningContext['user_prompt']);
        $this->assertSame(['region'], $capturedPlanningContext['available_dimensions']);
        $this->assertEqualsCanonicalizing(['sales', 'cost'], $capturedPlanningContext['available_measures']);
        $this->assertSame(['sum', 'count', 'avg'], $capturedPlanningContext['available_aggregations']);
        $this->assertSame(config('derived_metrics.max_derived_metrics'), $capturedPlanningContext['max_derived_metrics']);

        // --- AI Context handoff contract ---
        $this->assertIsArray($capturedContext);
        $this->assertSame(
            ['system_instruction', 'user_prompt', 'data_profile', 'aggregated_metrics', 'derived_metrics', 'analysis_template', 'column_mapping', 'output_schema'],
            array_keys($capturedContext),
        );

        // Free analysis (no template_key): analysis_template/column_mapping
        // stay at their Phase 2 defaults everywhere they appear.
        $this->assertNull($capturedPlanningContext['analysis_template']);
        $this->assertSame([], $capturedPlanningContext['column_mapping']);
        $this->assertNull($capturedContext['analysis_template']);
        $this->assertSame([], $capturedContext['column_mapping']);

        // 5. user prompt がそのまま渡る
        $this->assertSame($detail->prompt, $capturedContext['user_prompt']);

        // 2/3. DataProfilingAction が実行され、Data Profile が生成される
        //      (同一ファイルへ独立に実行した結果と完全一致することで検証)
        $expectedDataProfile = (new DataProfilingAction)->execute($dataFile->fresh());
        $this->assertSame($expectedDataProfile, $capturedContext['data_profile']);

        // MetricAggregationAction が Data Profiling の直後に実行され、
        // Aggregated Metrics が生成される (同一ファイル・同一Profileへ
        // 独立に実行した結果と完全一致することで検証)
        $expectedAggregatedMetrics = (new MetricAggregationAction)->execute($dataFile->fresh(), $expectedDataProfile);
        $this->assertSame($expectedAggregatedMetrics, $capturedContext['aggregated_metrics']);

        // CalculateDerivedMetricsAction が Planning の直後に実行され、
        // Derived Metrics が生成される (同一のproposed definitionへ
        // 独立に実行した結果と完全一致することで検証)
        $expectedDerivedMetrics = (new CalculateDerivedMetricsAction)->execute(
            json_decode($planResponse, true)['derived_metrics'],
            $expectedAggregatedMetrics,
        );
        $this->assertSame($expectedDerivedMetrics, $capturedContext['derived_metrics']);
        $this->assertSame([], $capturedContext['derived_metrics']['rejected']);
        $this->assertSame('ROAS', $capturedContext['derived_metrics']['metrics'][0]['name']);

        // 4. BuildAnalysisContextAction が呼ばれたことを示す残りの契約
        $this->assertIsString($capturedContext['system_instruction']);
        $this->assertNotSame('', $capturedContext['system_instruction']);
        $this->assertIsArray($capturedContext['output_schema']);
        $this->assertArrayHasKey('summary', $capturedContext['output_schema']);

        // 7/8. AI Context のみが渡り、DataFile / stored_path は含まれない
        $this->assertArrayNotHasKey('data_file', $capturedContext);
        $this->assertArrayNotHasKey('stored_path', $capturedContext);

        $analysisJob->refresh();
        $detail->refresh();

        // 9/10/11/12/13/14: persistence contract
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertNotNull($detail->started_at);
        $this->assertSame($rawResponse, $detail->raw_response);
        $this->assertSame($this->structuredResult(), $detail->result);
        $this->assertNotNull($detail->completed_at);
        $this->assertNull($detail->error_message);

        // Planning's own raw AI response ($planResponse) is never
        // persisted anywhere on AnalysisJobDetail — raw_response holds
        // only the final analysis response, exactly as asserted above.
    }

    /**
     * Retry attempt: AnalysisJob が既に Processing（Attempt 2 / 3 を想定）
     *
     * - Processing 状態からでも execute() できる
     * - started_at は元の値を維持する
     * - 成功すれば Completed になる
     */
    public function test_it_completes_successfully_when_re_executed_from_processing_state(): void
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put($storedPath, "region,sales\nTokyo,100\n");

        $dataFile = DataFile::factory()->create(['stored_path' => $storedPath]);
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['status' => AnalysisJobStatus::Processing]);
        $originalStartedAt = now()->subMinutes(2)->startOfSecond();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['started_at' => $originalStartedAt]);

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('analyze')
            ->once()
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertTrue($detail->started_at->equalTo($originalStartedAt));
    }

    /**
     * AI失敗: 例外を握りつぶさず、そのまま外へ伝播する。
     * ExecuteAnalysisJobAction は最終失敗を所有しないため、
     * この時点では Failed にせず Processing のまま維持する。
     */
    public function test_ai_failure_propagates_and_leaves_the_analysis_job_processing(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAnalysisJob('分析してください。');

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andThrow(new RuntimeException('AI provider unavailable'));

        try {
            app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI provider unavailable', $e->getMessage());
        }

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNull($detail->completed_at);
        $this->assertNull($detail->error_message);
    }

    /**
     * Normalization失敗: NormalizeAnalysisResultAction が invalid JSON を
     * 拒否した場合も同様に例外が伝播し、Processing のまま維持される。
     * Failed への更新は行わない。
     */
    public function test_normalization_failure_propagates_and_leaves_the_analysis_job_processing(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAnalysisJob('分析してください。');

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andReturn('{not valid json');

        try {
            app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNull($detail->completed_at);
        $this->assertNull($detail->error_message);
    }

    /**
     * Data Profiling失敗（例: CSVが不正）も同様に例外が伝播し、
     * Processing 維持・AiAnalysisClientは一切呼ばれない
     * （Planning・最終Analysisいずれも呼ばれない）。
     */
    public function test_data_profiling_failure_propagates_before_calling_the_ai_client(): void
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put($storedPath, ''); // empty CSV: DataProfilingAction must reject this

        $dataFile = DataFile::factory()->create(['stored_path' => $storedPath]);
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create();
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        try {
            app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            // Expected: DataProfilingAction rejects an empty/unreadable CSV.
        }

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNull($detail->completed_at);
    }

    // --- Analysis Template integration -----------------------------------

    /**
     * @return array{0: DataFile, 1: AnalysisJob, 2: AnalysisJobDetail}
     */
    private function createPendingAdPerformanceAnalysisJob(string $additionalPrompt = ''): array
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put(
            $storedPath,
            "channel,spend,revenue,conversions\nEmail,10000,50000,10\nSocial,20000,30000,20\n",
        );

        $dataFile = DataFile::factory()->create([
            'stored_path' => $storedPath,
            'original_name' => 'ads.csv',
        ]);

        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => 'ad_performance']);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['prompt' => $additionalPrompt]);

        return [$dataFile, $analysisJob, $detail];
    }

    public function test_it_completes_a_template_based_analysis_job_on_success(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob('特にEmailを詳しく見たい');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $capturedPlanningContext = null;
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        // Planning received the resolved Template + column_mapping.
        $this->assertSame('広告パフォーマンス分析', $capturedPlanningContext['analysis_template']['name']);
        $this->assertSame([
            'channel' => 'channel',
            'spend' => 'spend',
            'revenue' => 'revenue',
            'conversions' => 'conversions',
        ], $capturedPlanningContext['column_mapping']);

        // "clicks"/"impressions" are unmapped in this CSV, so recommendations
        // referencing them ("conversion_rate", "click_through_rate") must be
        // filtered out before Planning AI ever sees them — only hints whose
        // fields are both mapped survive.
        $recommendedNames = array_column(
            $capturedPlanningContext['analysis_template']['recommended_derived_metrics'],
            'name',
        );
        $this->assertContains('return_on_ad_spend', $recommendedNames);
        $this->assertContains('cost_per_conversion', $recommendedNames);
        $this->assertNotContains('conversion_rate', $recommendedNames);
        $this->assertNotContains('click_through_rate', $recommendedNames);

        // The final analysis Context received the same structured facts.
        $this->assertSame('広告パフォーマンス分析', $capturedContext['analysis_template']['name']);
        $this->assertSame($capturedPlanningContext['column_mapping'], $capturedContext['column_mapping']);

        // user_prompt is exactly the user's own additional prompt — never
        // the Template instruction merged into it.
        $this->assertSame('特にEmailを詳しく見たい', $capturedContext['user_prompt']);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        // The resolved column_mapping was persisted for audit/display.
        $this->assertSame('mapped', $detail->column_mapping['channel']['status']);
        $this->assertSame('channel', $detail->column_mapping['channel']['column']);
        $this->assertSame('high', $detail->column_mapping['channel']['confidence']);
    }

    /**
     * E2E regression for the exact bug reported against a real Japanese
     * column-name CSV (媒体/広告コスト/売上金額/CV数, no clicks/impressions
     * column at all): Planning AI, when handed the unfiltered
     * "click_through_rate" (clicks/impressions) hint, was observed to
     * silently substitute clicks/spend while keeping the "click_through_rate"
     * name — a numerically valid but business-meaningless metric. This
     * drives the full pipeline (real DataProfilingAction/MetricAggregationAction,
     * only AiAnalysisClient mocked) against that same CSV and asserts the
     * misleading hint never reaches Planning AI, while an unrelated,
     * fully-mapped hint still does and the AnalysisJob still completes.
     */
    public function test_it_completes_a_japanese_column_template_analysis_job_without_the_misleading_hint(): void
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put(
            $storedPath,
            "媒体,広告コスト,売上金額,CV数\nEmail,10000,50000,10\nPaid Search,20000,30000,20\n",
        );

        $dataFile = DataFile::factory()->create([
            'stored_path' => $storedPath,
            'original_name' => 'ads_ja.csv',
        ]);

        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => 'ad_performance']);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['prompt' => '']);

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => '売上金額', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'CV数', 'confidence' => 'high'],
            // No candidate column exists in this CSV for "clicks" or
            // "impressions" at all — both stay "unmapped" by default.
        ]], JSON_THROW_ON_ERROR);

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $capturedPlanningContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $recommendedNames = array_column(
            $capturedPlanningContext['analysis_template']['recommended_derived_metrics'],
            'name',
        );

        // The exact hint that was previously mis-substituted is gone.
        $this->assertNotContains('click_through_rate', $recommendedNames);
        $this->assertNotContains('conversion_rate', $recommendedNames);
        // A hint whose fields are both mapped still reaches Planning AI.
        $this->assertContains('return_on_ad_spend', $recommendedNames);
        $this->assertContains('cost_per_conversion', $recommendedNames);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame('unmapped', $detail->column_mapping['clicks']['status']);
        $this->assertSame('unmapped', $detail->column_mapping['impressions']['status']);
    }

    /**
     * "recommended_derived_metrics" is a hint list, never a whitelist:
     * every single hint gets filtered out here (only "channel" and
     * "impressions" resolve — "spend"/"revenue"/"conversions"/"clicks"
     * stay Template-unmapped, which starves all 4 config hints of a
     * usable field), yet the pipeline must still behave completely
     * normally:
     *
     * - Planning AI is still called (an empty recommended_derived_metrics
     *   never short-circuits the call — see PlanDerivedMetricsAction,
     *   which only skips the AI when aggregated_metrics itself has no
     *   dimensions/measures at all)
     * - Planning AI can still freely propose a derived metric from
     *   available_measures/available_dimensions (the real CSV columns,
     *   which exist and are usable regardless of the Template's semantic
     *   mapping status — Template mapping status is bookkeeping for hints
     *   only, never a gate on what Planning AI may compute)
     * - CalculateDerivedMetricsAction still validates and computes that
     *   self-proposed definition, and the AnalysisJob still completes
     */
    public function test_all_recommendations_filtered_out_does_not_prevent_planning_ai_from_proposing_its_own_metric(): void
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put(
            $storedPath,
            "channel,spend,revenue,conversions,clicks,impressions\nEmail,10000,50000,10,500,20000\nSocial,20000,30000,20,800,40000\n",
        );

        $dataFile = DataFile::factory()->create([
            'stored_path' => $storedPath,
            'original_name' => 'ads_all_filtered.csv',
        ]);

        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => 'ad_performance']);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['prompt' => '']);

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'impressions', 'column' => 'impressions', 'confidence' => 'high'],
            // "spend"/"revenue"/"conversions"/"clicks" are never proposed
            // at all, so every field every config hint depends on
            // (besides "impressions") stays "unmapped". "impressions"
            // alone satisfies the required_field_group.
        ]], JSON_THROW_ON_ERROR);

        // Planning AI proposes a derived metric using real measures
        // ("revenue"/"spend") directly by their real column name — it
        // never needed a surviving hint to do so.
        $planResponse = json_encode(['derived_metrics' => [
            [
                'name' => 'revenue_per_spend',
                'operator' => 'divide',
                'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
                'right' => ['metric' => 'spend', 'aggregation' => 'sum'],
                'group_by' => 'channel',
            ],
        ]], JSON_THROW_ON_ERROR);

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $capturedPlanningContext = null;
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($planResponse)
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        // Every config hint was starved of a usable field -> Planning AI
        // received an empty recommended_derived_metrics, not a partial one.
        $this->assertSame([], $capturedPlanningContext['analysis_template']['recommended_derived_metrics']);

        // Planning AI was still called (not skipped) despite the empty hint
        // list, and its self-proposed, non-hint metric was validated and
        // computed for real by CalculateDerivedMetricsAction.
        $this->assertCount(1, $capturedContext['derived_metrics']['metrics']);
        $this->assertSame('revenue_per_spend', $capturedContext['derived_metrics']['metrics'][0]['name']);
        $this->assertSame([], $capturedContext['derived_metrics']['rejected']);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame('unmapped', $detail->column_mapping['spend']['status']);
        $this->assertSame('unmapped', $detail->column_mapping['revenue']['status']);
        $this->assertSame('unmapped', $detail->column_mapping['conversions']['status']);
        $this->assertSame('unmapped', $detail->column_mapping['clicks']['status']);
        $this->assertSame('mapped', $detail->column_mapping['impressions']['status']);
    }

    // --- sales_analysis (Phase 3-B) ----------------------------------

    /**
     * @return array{0: DataFile, 1: AnalysisJob, 2: AnalysisJobDetail}
     */
    private function createPendingSalesAnalysisJob(string $csv, string $additionalPrompt = ''): array
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put($storedPath, $csv);

        $dataFile = DataFile::factory()->create([
            'stored_path' => $storedPath,
            'original_name' => 'sales.csv',
        ]);

        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => 'sales_analysis']);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create(['prompt' => $additionalPrompt]);

        return [$dataFile, $analysisJob, $detail];
    }

    /**
     * Full success path with every optional field mapped: confirms both
     * recommended hints (average_unit_price, average_order_value) survive
     * filtering, both get computed for real by CalculateDerivedMetricsAction,
     * and — the Phase 3-B §15 guarantee — "date" is recorded as "mapped" in
     * column_mapping while never appearing as an aggregated_metrics
     * dimension (MetricAggregationAction only ever selects
     * inferred_type === 'string' columns; "date"'s inferred_type is
     * "date", not "string").
     */
    public function test_it_completes_a_sales_analysis_job_with_all_optional_fields_mapped(): void
    {
        [, $analysisJob, $detail] = $this->createPendingSalesAnalysisJob(
            "date,product,category,quantity,orders,revenue\n"
            ."2026-01-01,ProductA,Food,10,8,50000\n"
            ."2026-01-02,ProductB,Goods,5,4,30000\n"
            ."2026-01-03,ProductA,Food,20,15,80000\n",
        );

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
            ['field' => 'quantity', 'column' => 'quantity', 'confidence' => 'high'],
            ['field' => 'orders', 'column' => 'orders', 'confidence' => 'high'],
            ['field' => 'product', 'column' => 'product', 'confidence' => 'high'],
            ['field' => 'category', 'column' => 'category', 'confidence' => 'high'],
            ['field' => 'date', 'column' => 'date', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $planResponse = json_encode(['derived_metrics' => [
            [
                'name' => 'average_unit_price',
                'operator' => 'divide',
                'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
                'right' => ['metric' => 'quantity', 'aggregation' => 'sum'],
                'group_by' => 'product',
            ],
            [
                'name' => 'average_order_value',
                'operator' => 'divide',
                'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
                'right' => ['metric' => 'orders', 'aggregation' => 'sum'],
                'group_by' => 'category',
            ],
        ]], JSON_THROW_ON_ERROR);

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $capturedPlanningContext = null;
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($planResponse)
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        // Both hints survived filtering (both fields they depend on are mapped).
        $recommendedNames = array_column(
            $capturedPlanningContext['analysis_template']['recommended_derived_metrics'],
            'name',
        );
        $this->assertContains('average_unit_price', $recommendedNames);
        $this->assertContains('average_order_value', $recommendedNames);

        // Both were validated and computed for real.
        $metricNames = array_column($capturedContext['derived_metrics']['metrics'], 'name');
        $this->assertContains('average_unit_price', $metricNames);
        $this->assertContains('average_order_value', $metricNames);
        $this->assertSame([], $capturedContext['derived_metrics']['rejected']);

        // "date" is mapped in column_mapping...
        $analysisJob->refresh();
        $detail->refresh();
        $this->assertSame('mapped', $detail->column_mapping['date']['status']);
        $this->assertSame('date', $detail->column_mapping['date']['column']);

        // ...but is never an aggregated_metrics dimension (inferred_type
        // "date" is not "string", so MetricAggregationAction::selectDimensions()
        // never selects it) — "product"/"category" are, since they are the
        // real string-typed columns.
        $dimensionNames = array_column($capturedContext['aggregated_metrics']['dimensions'], 'dimension');
        $this->assertNotContains('date', $dimensionNames);
        $this->assertContains('product', $dimensionNames);
        $this->assertContains('category', $dimensionNames);

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
    }

    /**
     * Only "revenue" (the sole required field) is mapped — every optional
     * Template field (quantity/orders/product/category/store/region/
     * customer/date) is never proposed at all. Both recommended hints
     * depend on quantity/orders, so recommended_derived_metrics is empty
     * — but Planning AI is still called normally (an empty hint list
     * never skips the call — only an empty aggregated_metrics does — see
     * PlanDerivedMetricsAction), and the AnalysisJob still completes.
     *
     * "memo" is a real CSV column with no corresponding Template semantic
     * field at all; it exists purely so MetricAggregationAction has a
     * dimension candidate to aggregate against (without it,
     * aggregated_metrics.dimensions would be empty and Planning AI would
     * never be called at all — a different, already-covered scenario).
     * This also incidentally demonstrates that aggregation candidate
     * selection is fully independent of Template Column Mapping (Phase
     * 3-A's design — see docs/product/ANALYSIS_TEMPLATE_MODULE.md §9).
     */
    public function test_it_completes_a_sales_analysis_job_when_only_revenue_is_mapped(): void
    {
        [, $analysisJob, $detail] = $this->createPendingSalesAnalysisJob(
            "revenue,memo\n50000,A\n30000,B\n80000,A\n",
        );

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $rawResponse = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        $capturedPlanningContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andReturn($rawResponse);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $this->assertSame([], $capturedPlanningContext['analysis_template']['recommended_derived_metrics']);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame('mapped', $detail->column_mapping['revenue']['status']);

        foreach (['quantity', 'orders', 'product', 'category', 'store', 'region', 'customer', 'date'] as $optionalField) {
            $this->assertSame('unmapped', $detail->column_mapping[$optionalField]['status']);
        }
    }

    /**
     * Phase 3-C: required field ("channel") unresolved no longer throws
     * or fails the AnalysisJob — it transitions Processing ->
     * AwaitingMappingConfirmation and the method returns normally (the
     * Queue job ends "successfully" from Laravel Queue's point of view;
     * no retry is triggered). The AI's partial mapping is still recorded,
     * and Planning/Analyze are never reached.
     */
    public function test_required_field_missing_transitions_to_awaiting_mapping_confirmation(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
        $this->assertNull($detail->completed_at);
        $this->assertNull($detail->effective_column_mapping);
        $this->assertSame('unmapped', $detail->column_mapping['channel']['status']);
        $this->assertSame('mapped', $detail->column_mapping['spend']['status']);
    }

    /**
     * A required field ("channel") that becomes ambiguous also leads to
     * AwaitingMappingConfirmation, not a failure — "ambiguous" is already
     * covered by ValidateColumnMappingAction's existing
     * missing_required_fields computation.
     */
    public function test_ambiguous_required_field_transitions_to_awaiting_mapping_confirmation(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        // Both "channel" and "campaign" claim the same column at high confidence.
        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'campaign', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
        $this->assertSame('ambiguous', $detail->column_mapping['channel']['status']);
    }

    /**
     * Column Mapping AI failure propagates before Planning is ever reached.
     */
    public function test_mapping_ai_failure_propagates_before_calling_planning(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andThrow(new RuntimeException('AI provider unavailable'))
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        try {
            app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('AI provider unavailable', $e->getMessage());
        }

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Processing, $analysisJob->status);
        $this->assertNull($detail->completed_at);
        $this->assertNull($detail->column_mapping);
    }

    // --- Phase 3-C: Mapping confirmation resume ---------------------------

    /**
     * Full recovery cycle for ad_performance (AA): required "channel"
     * unmapped -> AwaitingMappingConfirmation -> (simulating what
     * AnalysisJobController::updateMapping() does) manual override +
     * ResolveEffectiveColumnMappingAction + resumeAfterMappingConfirmation
     * -> re-dispatch -> Completed. Mapping AI is called exactly once for
     * the entire lifetime of this AnalysisJob (never again on resume),
     * so the Template AI call total stays at 3 (Mapping + Planning +
     * Analyze) exactly like the auto-confident path — Phase 3-C's
     * Mapping confirmation flow adds zero AI calls (AD).
     */
    public function test_manual_confirmation_resumes_ad_performance_to_completed_without_calling_mapping_ai_again(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
            // "channel" (required) is never proposed.
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once() // <- never called again on resume
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();
        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);

        // What AnalysisJobController::updateMapping() does, driven directly:
        // manually map "channel", re-validate the Full Mapping Proposal,
        // and resume.
        $template = config('analysis_templates.ad_performance');
        $dataProfile = app(DataProfilingAction::class)->execute($analysisJob->dataFile);
        $columnCandidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)
            ->execute($template['fields'], $dataProfile);

        $effective = app(ResolveEffectiveColumnMappingAction::class)->execute(
            $template['fields'],
            $columnCandidates,
            $detail->column_mapping,
            ['channel' => ['column' => 'channel']],
            $template['required_fields'],
            $template['required_field_groups'],
        );

        $this->assertSame([], $effective['missing_required_fields']);

        app(UpdateAnalysisJobAction::class)->resumeAfterMappingConfirmation(
            $analysisJob,
            ['channel' => ['column' => 'channel']],
            $effective['effective_mapping'],
        );

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Pending, $analysisJob->status);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame('manual', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['spend']['source']);
    }

    /**
     * Full recovery cycle for sales_analysis (AB), also exercising:
     *
     * - V: manually adding "orders" revives the average_order_value hint
     * - W: manually unsetting "quantity" (which the AI *did* map) removes
     *   the average_unit_price hint
     * - X/Y/Z: Planning AND the Final Analysis Context both receive the
     *   confirmed Effective Mapping — "quantity" (present in the AI's own
     *   column_mapping, but manually unset) must be absent from both,
     *   proving neither one fell back to the stale AI mapping.
     */
    public function test_manual_confirmation_revives_and_removes_derived_metric_hints_via_effective_mapping(): void
    {
        [, $analysisJob, $detail] = $this->createPendingSalesAnalysisJob(
            "date,product,category,quantity,orders,revenue\n"
            ."2026-01-01,ProductA,Food,10,8,50000\n"
            ."2026-01-02,ProductB,Goods,5,4,30000\n",
        );

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'quantity', 'column' => 'quantity', 'confidence' => 'high'],
            ['field' => 'product', 'column' => 'product', 'confidence' => 'high'],
            ['field' => 'category', 'column' => 'category', 'confidence' => 'high'],
            // "revenue" (the only required field) and "orders" are never proposed.
        ]], JSON_THROW_ON_ERROR);

        $capturedPlanningContext = null;
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once() // <- never called again on resume
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedPlanningContext): bool {
                $capturedPlanningContext = $context;

                return true;
            })
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();
        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
        $this->assertSame('mapped', $detail->column_mapping['quantity']['status']); // AI did map it

        // Manual override: fix "revenue" (required), add "orders" (V),
        // and explicitly unset "quantity" (W) even though the AI mapped it.
        $manualOverrides = [
            'revenue' => ['column' => 'revenue'],
            'orders' => ['column' => 'orders'],
            'quantity' => ['column' => null],
        ];

        $template = config('analysis_templates.sales_analysis');
        $dataProfile = app(DataProfilingAction::class)->execute($analysisJob->dataFile);
        $columnCandidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)
            ->execute($template['fields'], $dataProfile);

        $effective = app(ResolveEffectiveColumnMappingAction::class)->execute(
            $template['fields'],
            $columnCandidates,
            $detail->column_mapping,
            $manualOverrides,
            $template['required_fields'],
            $template['required_field_groups'],
        );

        $this->assertSame([], $effective['missing_required_fields']);
        $this->assertSame('unmapped', $effective['effective_mapping']['quantity']['status']);
        $this->assertSame('mapped', $effective['effective_mapping']['orders']['status']);

        app(UpdateAnalysisJobAction::class)->resumeAfterMappingConfirmation(
            $analysisJob,
            $manualOverrides,
            $effective['effective_mapping'],
        );

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        // V: average_order_value (revenue/orders) revived...
        $recommendedNames = array_column(
            $capturedPlanningContext['analysis_template']['recommended_derived_metrics'],
            'name',
        );
        $this->assertContains('average_order_value', $recommendedNames);
        // ...W: average_unit_price (revenue/quantity) removed, because
        // "quantity" was manually unset even though the AI had mapped it.
        $this->assertNotContains('average_unit_price', $recommendedNames);

        // X/Y/Z: neither Planning nor the Final Analysis Context ever see
        // "quantity" — if either did, it would mean the stale AI mapping
        // (which had "quantity" mapped) leaked through instead of the
        // confirmed Effective Mapping.
        $this->assertArrayNotHasKey('quantity', $capturedPlanningContext['column_mapping']);
        $this->assertArrayNotHasKey('quantity', $capturedContext['column_mapping']);
        $this->assertSame('orders', $capturedPlanningContext['column_mapping']['orders']);
        $this->assertSame('orders', $capturedContext['column_mapping']['orders']);
        $this->assertSame($capturedPlanningContext['column_mapping'], $capturedContext['column_mapping']);
    }

    /**
     * T: a stale/duplicate Queue message for an already-Completed
     * AnalysisJob is a safe no-op — it must never throw inside
     * markProcessing() and manufacture a spurious failed_jobs entry for a
     * Job that isn't actually failing.
     */
    public function test_a_stray_queue_message_for_an_already_completed_job_is_a_no_op(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAnalysisJob('分析してください。');
        $analysisJob->update(['status' => AnalysisJobStatus::Completed]);
        $detail->update(['completed_at' => now(), 'result' => $this->structuredResult()]);

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
    }

    /**
     * U: a stray Queue message for a Job currently
     * AwaitingMappingConfirmation must never resume Planning on its own —
     * only the confirm-triggered resume dispatch (Awaiting -> Pending)
     * is allowed to do that.
     */
    public function test_a_stray_queue_message_for_an_awaiting_confirmation_job_does_not_start_planning(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();
        $analysisJob->update(['status' => AnalysisJobStatus::AwaitingMappingConfirmation]);
        $detail->update(['column_mapping' => ['channel' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped']]]);

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('mapColumns')
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
    }

    /**
     * A: the auto-confident path (no manual intervention at all) still
     * persists effective_column_mapping, tagged source "ai" for every
     * field — Template Jobs always end up with an effective_column_mapping,
     * whether or not Mapping confirmation was ever needed.
     */
    public function test_auto_confident_path_persists_effective_mapping_tagged_source_ai(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $detail->refresh();

        $this->assertNotNull($detail->effective_column_mapping);
        $this->assertSame('ai', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['spend']['source']);
        $this->assertSame('channel', $detail->effective_column_mapping['channel']['column']);
    }

    // --- Medium 3: column_mapping write-once / retry recovery -------------

    /**
     * Simulates the exact gap the review flagged: column_mapping was
     * already recorded by a previous attempt (e.g. one that failed later,
     * between recordColumnMapping() and the Awaiting/Effective-Mapping
     * write), but effective_column_mapping never got written. A retry
     * must reuse the stored column_mapping rather than calling Mapping AI
     * again (1/2/7 from the review's test list: no extra mapColumns call,
     * the original column_mapping survives unchanged).
     */
    public function test_retry_with_existing_column_mapping_and_no_effective_mapping_does_not_call_mapping_ai_again(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $originalColumnMapping = [
            'channel' => ['column' => 'channel', 'confidence' => 'high', 'status' => 'mapped'],
            'campaign' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'spend' => ['column' => 'spend', 'confidence' => 'high', 'status' => 'mapped'],
            'revenue' => ['column' => 'revenue', 'confidence' => 'high', 'status' => 'mapped'],
            'conversions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'clicks' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'impressions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'date' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
        ];

        // Simulate "a previous attempt already got this far": Processing,
        // column_mapping recorded, effective_column_mapping still null.
        $analysisJob->update(['status' => AnalysisJobStatus::Processing]);
        $detail->update(['column_mapping' => $originalColumnMapping, 'started_at' => now()]);

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('mapColumns') // <- the crux of the fix
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        // column_mapping is byte-for-byte the same value that was already
        // stored — never overwritten by a second (would-be) AI response.
        $this->assertSame($originalColumnMapping, $detail->column_mapping);
        $this->assertSame('ai', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('channel', $detail->effective_column_mapping['channel']['column']);
    }

    public function test_recovery_child_reuses_effective_mapping_but_reruns_planning_and_analysis(): void
    {
        Queue::fake();
        [$dataFile, $source, $sourceDetail] = $this->createPendingAdPerformanceAnalysisJob();
        $effectiveMapping = [
            'channel' => ['column' => 'channel', 'status' => 'mapped', 'source' => 'ai'],
            'spend' => ['column' => 'spend', 'status' => 'mapped', 'source' => 'ai'],
        ];
        $source->update(['status' => AnalysisJobStatus::Failed]);
        $sourceDetail->update(['effective_column_mapping' => $effectiveMapping, 'error_message' => 'old failure']);
        $child = app(RecoverFailedAnalysisJobAction::class)->execute($dataFile->project, $source)['analysis_job'];

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('mapColumns')
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($child->analysis_job_id);

        $this->assertSame(AnalysisJobStatus::Completed, $child->fresh()->status);
        $this->assertSame(AnalysisJobStatus::Failed, $source->fresh()->status);
    }

    public function test_recovery_child_revalidates_copied_column_mapping_without_mapping_ai(): void
    {
        Queue::fake();
        [$dataFile, $source, $sourceDetail] = $this->createPendingAdPerformanceAnalysisJob();
        $columnMapping = [
            'channel' => ['column' => 'channel', 'confidence' => 'high', 'status' => 'mapped'],
            'campaign' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'spend' => ['column' => 'spend', 'confidence' => 'high', 'status' => 'mapped'],
            'revenue' => ['column' => 'revenue', 'confidence' => 'high', 'status' => 'mapped'],
            'conversions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'clicks' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'impressions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'date' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
        ];
        $source->update(['status' => AnalysisJobStatus::Failed]);
        $sourceDetail->update(['column_mapping' => $columnMapping, 'effective_column_mapping' => null]);
        $child = app(RecoverFailedAnalysisJobAction::class)->execute($dataFile->project, $source)['analysis_job'];

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('mapColumns')
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($child->analysis_job_id);

        $childDetail = $child->analysisJobDetail()->firstOrFail();
        $this->assertSame(AnalysisJobStatus::Completed, $child->fresh()->status);
        $this->assertSame($columnMapping, $childDetail->column_mapping);
        $this->assertNotNull($childDetail->effective_column_mapping);
    }

    public function test_recovery_child_without_mapping_runs_mapping_planning_and_analysis_pipeline(): void
    {
        Queue::fake();
        [$dataFile, $source, $sourceDetail] = $this->createPendingAdPerformanceAnalysisJob();
        $source->update(['status' => AnalysisJobStatus::Failed]);
        $sourceDetail->update([
            'column_mapping' => null,
            'manual_column_mapping' => null,
            'effective_column_mapping' => null,
            'error_message' => 'Mapping provider failed before a response was stored.',
        ]);
        $child = app(RecoverFailedAnalysisJobAction::class)->execute($dataFile->project, $source)['analysis_job'];
        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($child->analysis_job_id);

        $this->assertSame(AnalysisJobStatus::Completed, $child->fresh()->status);
        $this->assertSame(AnalysisJobStatus::Failed, $source->fresh()->status);
        $this->assertNotNull($child->analysisJobDetail()->firstOrFail()->effective_column_mapping);
    }

    /**
     * Same retry-recovery scenario, but the stored column_mapping does
     * NOT satisfy required_fields (review test 4): must go to
     * AwaitingMappingConfirmation, still without ever calling Mapping AI
     * again.
     */
    public function test_retry_with_existing_column_mapping_still_missing_required_goes_to_awaiting_without_calling_mapping_ai(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $originalColumnMapping = [
            'channel' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'], // required, missing
            'campaign' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'spend' => ['column' => 'spend', 'confidence' => 'high', 'status' => 'mapped'],
            'revenue' => ['column' => 'revenue', 'confidence' => 'high', 'status' => 'mapped'],
            'conversions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'clicks' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'impressions' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
            'date' => ['column' => null, 'confidence' => 'unmapped', 'status' => 'unmapped'],
        ];

        $analysisJob->update(['status' => AnalysisJobStatus::Processing]);
        $detail->update(['column_mapping' => $originalColumnMapping, 'started_at' => now()]);

        $this->mock(AiAnalysisClient::class)
            ->shouldNotReceive('mapColumns')
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();

        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
        $this->assertSame($originalColumnMapping, $detail->column_mapping);
        $this->assertNull($detail->effective_column_mapping);
    }

    /**
     * Manual confirmation preserves the three-way separation end to end
     * (review test 6): column_mapping stays exactly the AI's first value,
     * manual_column_mapping holds only the user's diff, and
     * effective_column_mapping is the merged final Fact.
     */
    public function test_manual_confirmation_leaves_column_mapping_untouched(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAdPerformanceAnalysisJob();

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'spend', 'column' => 'spend', 'confidence' => 'high'],
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn($mapResponse)
            ->shouldNotReceive('planMetrics')
            ->shouldNotReceive('analyze');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail->refresh();
        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);
        $columnMappingAfterAi = $detail->column_mapping;

        $template = config('analysis_templates.ad_performance');
        $dataProfile = app(DataProfilingAction::class)->execute($analysisJob->dataFile);
        $columnCandidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)
            ->execute($template['fields'], $dataProfile);

        $manualOverrides = ['channel' => ['column' => 'channel']];

        $effective = app(ResolveEffectiveColumnMappingAction::class)->execute(
            $template['fields'],
            $columnCandidates,
            $detail->column_mapping,
            $manualOverrides,
            $template['required_fields'],
            $template['required_field_groups'],
        );

        app(UpdateAnalysisJobAction::class)->resumeAfterMappingConfirmation(
            $analysisJob,
            $manualOverrides,
            $effective['effective_mapping'],
        );

        $detail->refresh();

        // column_mapping: unchanged, still exactly the AI's first result.
        $this->assertSame($columnMappingAfterAi, $detail->column_mapping);
        // manual_column_mapping: only the user's diff.
        $this->assertSame(['channel' => ['column' => 'channel']], $detail->manual_column_mapping);
        // effective_column_mapping: the merged final Fact.
        $this->assertSame('manual', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['spend']['source']);
    }
}
