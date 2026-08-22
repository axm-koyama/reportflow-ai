<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\CalculateDerivedMetricsAction;
use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\DataProfiling\MetricAggregationAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ['system_instruction', 'user_prompt', 'data_profile', 'aggregated_metrics', 'derived_metrics', 'output_schema'],
            array_keys($capturedContext),
        );

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
}
