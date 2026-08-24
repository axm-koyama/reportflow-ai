<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Actions\Priority\CalculatePriorityAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * End-to-end coverage for Phase 4-C's integration into
 * ExecuteAnalysisJobAction. See docs/product/PRIORITY_ENGINE.md.
 *
 * These exercise the real pipeline (DataProfiling -> Aggregation ->
 * Mapping -> Planning -> Calculation -> Evaluation -> Priority -> Analyze
 * -> Diagnosis) with only AiAnalysisClient mocked, mirroring
 * ExecuteAnalysisJobEvaluationTest's own convention.
 */
class ExecuteAnalysisJobPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

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
            'summary' => '広告チャネルのパフォーマンスを分析しました',
            'highlights' => [],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
            'recommendations' => [],
        ];
    }

    /**
     * Case A (Product Validation, balanced traffic — see
     * docs/product/DIAGNOSIS_ENGINE.md §19): every channel has clicks =
     * 12,000, total = 60,000. Display: Below + Medium (delta -0.4pp).
     * Social: Below + High (delta -1.4pp). Email/Paid Search: Above (not
     * Priority-eligible). Organic: Above + Low (not eligible).
     */
    private const string CASE_A_CSV = <<<'CSV'
        channel,clicks,conversions
        Display,12000,540
        Social,12000,420
        Email,12000,660
        Paid Search,12000,720
        Organic,12000,600

        CSV;

    /**
     * @return array{0: DataFile, 1: AnalysisJob, 2: AnalysisJobDetail}
     */
    private function createPendingAnalysisJob(string $csv, ?string $templateKey): array
    {
        $storedPath = 'projects/1/data-files/'.Str::uuid()->toString().'.csv';
        Storage::disk('local')->put($storedPath, $csv);

        $dataFile = DataFile::factory()->create(['stored_path' => $storedPath]);
        $analysisJob = AnalysisJob::factory()->for($dataFile)->create(['template_key' => $templateKey]);
        $detail = AnalysisJobDetail::factory()->for($analysisJob)->create();

        return [$dataFile, $analysisJob, $detail];
    }

    private function mockAdPerformanceMapping(): void
    {
        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));
    }

    /**
     * A: Case A end-to-end -> Display gets a "low" PriorityResult, Social
     * gets a "medium" one, with Social ranked strictly above Display
     * (matches the Product Validation-derived expectation — see
     * docs/product/PRIORITY_ENGINE.md "Case A Priority結果"). Favorable/low
     * entities (Email, Paid Search, Organic) get none.
     */
    public function test_a_case_a_produces_the_expected_priority_results(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::CASE_A_CSV, 'ad_performance');
        $this->mockAdPerformanceMapping();

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        $results = PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->get();
        $this->assertCount(2, $results, 'Only Display (Below+Medium) and Social (Below+High) are Priority-eligible.');

        $display = $results->first(fn (PriorityResult $r) => $r->evaluationFact->entity_key === 'Display');
        $social = $results->first(fn (PriorityResult $r) => $r->evaluationFact->entity_key === 'Social');

        $this->assertNotNull($display);
        $this->assertNotNull($social);

        // Impact: 12000/60000 = 0.20 for both (all channels have equal clicks).
        $this->assertEqualsWithDelta(0.20, $display->impact_score, 1e-6);
        $this->assertEqualsWithDelta(0.20, $social->impact_score, 1e-6);
        $this->assertEqualsWithDelta(60000.0, $display->impact_total, 1e-6);

        // Gap is measured against the leave-one-out test_baseline_value
        // (Display: metric 540/12000=0.045 vs control 2400/48000=0.05;
        // Social: metric 420/12000=0.035 vs control 2520/48000=0.0525),
        // never the self-diluted display_baseline_value — see
        // CalculatePriorityAction's class docblock.
        $this->assertEqualsWithDelta(0.005, $display->gap_raw_value, 1e-6);
        $this->assertEqualsWithDelta(0.0175, $social->gap_raw_value, 1e-6);
        $this->assertEqualsWithDelta(0.05, $display->priority_score, 1e-6);
        $this->assertEqualsWithDelta(0.175, $social->priority_score, 1e-6);

        $this->assertSame('low', $display->priority_band);
        $this->assertSame('medium', $social->priority_band);
        $this->assertGreaterThan($display->priority_score, $social->priority_score, 'Social (larger gap) must outrank Display (same impact, smaller gap).');
        $this->assertSame('priority_v1.1', $display->formula_version);

        $emailFact = EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->where('entity_key', 'Email')->first();
        $this->assertNotNull($emailFact);
        $this->assertNull($emailFact->priorityResult);
    }

    /**
     * B: Free Analysis (template_key null) -> zero PriorityResults, AI call
     * count unchanged (2).
     */
    public function test_b_free_analysis_produces_no_priority_results(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob("region,sales\nTokyo,100\nOsaka,200\n", null);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame(0, PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * C: sales_analysis (no evaluation_metrics entry) -> zero
     * PriorityResults.
     */
    public function test_c_sales_analysis_produces_no_priority_results(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(
            "product,revenue\nWidget,1000\nGadget,2000\n",
            'sales_analysis',
        );

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame(0, PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * D: a technical Priority exception must never fail the AnalysisJob —
     * soft-fail, the pipeline continues to Completed with zero
     * PriorityResults (docs/product/PRIORITY_ENGINE.md "Soft-fail").
     */
    public function test_d_a_technical_priority_exception_does_not_fail_the_analysis_job(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::CASE_A_CSV, 'ad_performance');
        $this->mockAdPerformanceMapping();

        $this->mock(CalculatePriorityAction::class)
            ->shouldReceive('execute')
            ->andThrow(new RuntimeException('simulated technical failure inside CalculatePriorityAction'));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail = $analysisJob->analysisJobDetail;
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertNotNull($detail->result);
        $this->assertNull($detail->error_message);
        $this->assertSame(0, PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        // Evaluation and Diagnosis (downstream of Priority in the pipeline)
        // must be unaffected by Priority's soft-fail.
        $this->assertSame(5, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * E: the stale-data regression this pattern guards against — a
     * *previous, successful* attempt already persisted PriorityResult
     * rows; this attempt's Priority calculation fails technically. The
     * soft-fail must not leave those old rows behind describing an attempt
     * that never actually happened.
     */
    public function test_e_a_technical_priority_exception_clears_stale_priority_results_from_a_previous_attempt(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::CASE_A_CSV, 'ad_performance');

        $staleFact = EvaluationFact::factory()->for($analysisJob, 'analysisJob')->create(['entity_key' => 'Display']);
        PriorityResult::factory()->for($analysisJob, 'analysisJob')->create(['evaluation_fact_id' => $staleFact->evaluation_fact_id]);
        $this->assertSame(1, PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        $this->mockAdPerformanceMapping();

        $this->mock(CalculatePriorityAction::class)
            ->shouldReceive('execute')
            ->andThrow(new RuntimeException('simulated technical failure inside CalculatePriorityAction'));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame(0, PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * F: idempotency — executing the pipeline twice for the same
     * AnalysisJob never accumulates duplicate PriorityResult rows; only
     * the latest attempt's rows remain.
     */
    public function test_f_running_the_pipeline_twice_does_not_duplicate_priority_results(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::CASE_A_CSV, 'ad_performance');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        // mapColumns is only ever called once per AnalysisJob's lifetime
        // (its Effective Mapping, once confirmed, is reused on every
        // subsequent attempt — see ExecuteAnalysisJobAction's own
        // docblock); planMetrics/analyze are redone from scratch on every
        // attempt.
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->twice()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->twice()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);
        $firstCount = PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count();

        // A second execute() against an already-Completed AnalysisJob is a
        // Queue no-op guard elsewhere in ExecuteAnalysisJobAction — force a
        // genuine second attempt by resetting status back to pending, the
        // same technique other regression tests in this suite would use.
        // $analysisJob's in-memory attributes are still those from before
        // the first execute() call (its status was never re-synced via
        // refresh()), so a plain ->update() would see 'status' as
        // unchanged from its own stale point of view and could omit it
        // from the UPDATE statement entirely — force the write via the
        // query builder instead, which has no such dirty-tracking.
        AnalysisJob::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->update(['status' => AnalysisJobStatus::Pending]);

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);
        $secondCount = PriorityResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertSame(2, $secondCount);
    }
}
