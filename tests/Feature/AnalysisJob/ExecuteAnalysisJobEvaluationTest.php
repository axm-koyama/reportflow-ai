<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\BuildAnalysisTemplateColumnCandidatesAction;
use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Actions\AnalysisJob\ResolveEffectiveColumnMappingAction;
use App\Actions\AnalysisJob\UpdateAnalysisJobAction;
use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\Evaluation\EvaluateAnalysisJobAction;
use App\Actions\Evaluation\EvaluateRateMetricAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\EvaluationFact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * End-to-end coverage for Phase 4-A's integration into
 * ExecuteAnalysisJobAction. See docs/product/EVALUATION_ENGINE.md.
 *
 * These exercise the real pipeline (DataProfiling -> Aggregation ->
 * Mapping -> Planning -> Calculation -> Evaluation -> Analyze) with only
 * AiAnalysisClient mocked, mirroring ExecuteAnalysisJobActionTest's own
 * convention.
 */
class ExecuteAnalysisJobEvaluationTest extends TestCase
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
     * channel/clicks/conversions with the same numbers independently
     * verified elsewhere: Social 200/5000 vs peers (Email+Paid+Organic)
     * 610/10000 -> z ≈ -5.3643390485032.
     */
    private const string AD_PERFORMANCE_CSV_EN = <<<'CSV'
        channel,clicks,conversions
        Social,5000,200
        Email,3000,243
        Paid,4000,220
        Organic,3000,147

        CSV;

    private const string AD_PERFORMANCE_CSV_JA = <<<'CSV'
        媒体,クリック数,CV数
        Social,5000,200
        Email,3000,243
        Paid,4000,220
        Organic,3000,147

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

    private function assertSocialFactMatchesTheIndependentlyVerifiedZScore(AnalysisJob $analysisJob): void
    {
        $facts = EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->get();

        $this->assertCount(4, $facts);

        $social = $facts->firstWhere('entity_key', 'Social');
        $this->assertNotNull($social);
        $this->assertSame('conversion_rate', $social->metric_key);
        $this->assertEqualsWithDelta(0.04, $social->metric_value, 1e-9);
        $this->assertEqualsWithDelta(0.054, $social->display_baseline_value, 1e-9);
        $this->assertEqualsWithDelta(0.061, $social->test_baseline_value, 1e-9);
        $this->assertSame(200, $social->numerator_value);
        $this->assertSame(5000, $social->denominator_value);
        $this->assertSame(610, $social->control_numerator_value);
        $this->assertSame(10000, $social->control_denominator_value);
        $this->assertEqualsWithDelta(-5.3643390485032, $social->z_score, 1e-6);
        $this->assertSame('below', $social->direction);
        $this->assertSame('high', $social->evaluation_level);
        $this->assertSame('evaluation_rule_v1.0', $social->rule_version);
    }

    /** A: ad_performance, English CSV column names -> EvaluationFacts generated. */
    public function test_a_ad_performance_english_csv_produces_evaluation_facts(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV_EN, 'ad_performance');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        $this->assertSocialFactMatchesTheIndependentlyVerifiedZScore($analysisJob);
    }

    /** B: ad_performance, Japanese CSV column names -> resolved via Effective Mapping. */
    public function test_b_ad_performance_japanese_csv_produces_evaluation_facts_via_effective_mapping(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV_JA, 'ad_performance');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'CV数', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'クリック数', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail = $analysisJob->analysisJobDetail;
        $this->assertSame('媒体', $detail->effective_column_mapping['channel']['column']);

        $this->assertSocialFactMatchesTheIndependentlyVerifiedZScore($analysisJob);
    }

    /** C: Free Analysis (template_key null) -> zero EvaluationFacts, AI call count unchanged (2). */
    public function test_c_free_analysis_produces_no_evaluation_facts(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob("region,sales\nTokyo,100\nOsaka,200\n", null);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /** D: sales_analysis -> zero EvaluationFacts (no metrics configured for it). */
    public function test_d_sales_analysis_produces_no_evaluation_facts(): void
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
        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * E: a technical Evaluation exception must never fail the
     * AnalysisJob — soft-fail, the pipeline continues to Completed with
     * zero EvaluationFacts (docs/product/EVALUATION_ENGINE.md
     * "Soft-fail").
     */
    public function test_e_a_technical_evaluation_exception_does_not_fail_the_analysis_job(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV_EN, 'ad_performance');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        $this->mock(EvaluateAnalysisJobAction::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('simulated technical failure inside Evaluation'))
            ->shouldReceive('clearForAnalysisJob')
            ->once()
            ->with(Mockery::on(fn (AnalysisJob $job): bool => $job->analysis_job_id === $analysisJob->analysis_job_id));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail = $analysisJob->analysisJobDetail;
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertNotNull($detail->result);
        $this->assertNull($detail->error_message);
        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * E2: the stale-data regression this fix targets — a *previous,
     * successful* attempt already persisted EvaluationFact rows; this
     * attempt's Evaluation fails technically. The soft-fail must not
     * leave those old rows behind describing an attempt that never
     * actually happened. Uses the real EvaluateAnalysisJobAction (not
     * mocked) so clearForAnalysisJob() genuinely deletes from the
     * database, not just satisfies a mock expectation.
     */
    public function test_e2_a_technical_evaluation_exception_clears_stale_evaluation_facts_from_a_previous_attempt(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV_EN, 'ad_performance');

        EvaluationFact::factory()->for($analysisJob)->create(['entity_key' => 'Social']);
        EvaluationFact::factory()->for($analysisJob)->create(['entity_key' => 'Email']);
        $this->assertSame(2, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        // Only EvaluateRateMetricAction (a dependency of the real
        // EvaluateAnalysisJobAction) is forced to fail technically —
        // EvaluateAnalysisJobAction itself, including
        // clearForAnalysisJob(), runs for real.
        $this->mock(EvaluateRateMetricAction::class)
            ->shouldReceive('execute')
            ->andThrow(new RuntimeException('simulated technical failure inside EvaluateRateMetricAction'));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $detail = $analysisJob->analysisJobDetail;
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertNotNull($detail->result);
        $this->assertNull($detail->error_message);
        // The stale facts from the "previous attempt" are gone — not 4
        // (2 stale + 2 new), not 2 (untouched stale) — zero.
        $this->assertSame(0, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    /**
     * E3: idempotency end-to-end — a previous attempt's EvaluationFact
     * rows exist, but this attempt's Evaluation *succeeds*. The normal
     * "delete + recreate" path (not the soft-fail cleanup path) must
     * still replace them with exactly this attempt's facts.
     */
    public function test_e3_a_successful_evaluation_replaces_facts_left_by_a_previous_attempt(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV_EN, 'ad_performance');

        EvaluationFact::factory()->for($analysisJob)->create([
            'entity_key' => 'StaleChannelFromAPreviousAttempt',
        ]);
        $this->assertSame(1, EvaluationFact::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        $this->assertSocialFactMatchesTheIndependentlyVerifiedZScore($analysisJob);
        $this->assertFalse(
            EvaluationFact::query()
                ->where('analysis_job_id', $analysisJob->analysis_job_id)
                ->where('entity_key', 'StaleChannelFromAPreviousAttempt')
                ->exists(),
        );
    }

    /** F: after manual Mapping confirmation, Evaluation uses the resulting Effective Mapping. */
    public function test_f_evaluation_uses_the_effective_mapping_after_manual_confirmation(): void
    {
        [, $analysisJob, $detail] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV_EN, 'ad_performance');

        // Mapping AI proposes conversions/clicks confidently but never
        // proposes "channel" (required) -> AwaitingMappingConfirmation.
        $mapResponse = json_encode(['mappings' => [
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::AwaitingMappingConfirmation, $analysisJob->status);

        // What AnalysisJobController::updateMapping() does, driven directly.
        $template = config('analysis_templates.ad_performance');
        $dataProfile = app(DataProfilingAction::class)->execute($analysisJob->dataFile);
        $columnCandidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)
            ->execute($template['fields'], $dataProfile);

        $effective = app(ResolveEffectiveColumnMappingAction::class)->execute(
            $template['fields'],
            $columnCandidates,
            $detail->fresh()->column_mapping,
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

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        $detail->refresh();
        $this->assertSame('manual', $detail->effective_column_mapping['channel']['source']);
        $this->assertSame('ai', $detail->effective_column_mapping['conversions']['source']);

        $this->assertSocialFactMatchesTheIndependentlyVerifiedZScore($analysisJob);
    }
}
