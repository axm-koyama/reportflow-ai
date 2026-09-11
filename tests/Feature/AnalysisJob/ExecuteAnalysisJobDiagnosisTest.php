<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\ActionProposal\RunActionProposalForAnalysisJobAction;
use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\Actions\Diagnosis\RunDiagnosisForAnalysisJobAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage for Phase 4-B's integration into
 * ExecuteAnalysisJobAction. See docs/product/DIAGNOSIS_ENGINE.md.
 *
 * Mirrors ExecuteAnalysisJobEvaluationTest's own convention: the real
 * pipeline runs (DataProfiling -> Aggregation -> Mapping -> Planning ->
 * Calculation -> Evaluation -> Final Analyze -> Diagnosis), with only
 * AiAnalysisClient mocked.
 */
class ExecuteAnalysisJobDiagnosisTest extends TestCase
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
     * @param  list<string>  $evidenceRefs  must contain at least one
     *                                      supplied evidence identifier (see
     *                                      NormalizeDiagnosisResultAction).
     */
    private function diagnoseResponse(array $evidenceRefs): string
    {
        return json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'The evidence does not distinguish a specific cause.',
                'evidence_refs' => $evidenceRefs,
                'missing_evidence' => ['landing-page-level conversion rate'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Social (200/5000) vs peers (Email+Paid+Organic, 610/10000) ->
     * z ≈ -5.3643390485032, evaluation_level high, direction below —
     * independently verified elsewhere (see
     * ExecuteAnalysisJobEvaluationTest / EVALUATION_ENGINE.md). This
     * makes Social Diagnosis-eligible.
     */
    private const string AD_PERFORMANCE_CSV = <<<'CSV'
        channel,clicks,conversions
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

    /**
     * Diagnosis runs for the eligible EvaluationFact(s) before
     * markCompleted() — verified functionally: after execute() returns,
     * both the AnalysisJob is Completed *and* the DiagnosisResult row
     * already exists (no separate "resume" step ever populates it later).
     * Final Analyze's System Instruction is Decision-enabled for
     * ad_performance (has a config/evaluation_metrics.php entry).
     */
    public function test_diagnosis_runs_for_the_eligible_fact_before_the_job_is_marked_completed(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV, 'ad_performance');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $capturedAnalyzeContext = null;
        $capturedDiagnosisContexts = [];

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedAnalyzeContext): bool {
                $capturedAnalyzeContext = $context;

                return true;
            })
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR))
            ->shouldReceive('diagnose')
            ->atLeast()->once()
            ->withArgs(function (array $context) use (&$capturedDiagnosisContexts): bool {
                $capturedDiagnosisContexts[] = $context;

                return true;
            })
            ->andReturnUsing(fn (array $context): string => $this->diagnoseResponse([$context['trigger_fact']['evidence_id']]));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);

        $social = EvaluationFact::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->where('entity_key', 'Social')
            ->firstOrFail();
        $this->assertSame('high', $social->evaluation_level);
        $this->assertSame('below', $social->direction);

        $diagnosisResult = DiagnosisResult::query()->where('evaluation_fact_id', $social->evaluation_fact_id)->first();
        $this->assertNotNull($diagnosisResult, 'Social is Diagnosis-eligible and must have a DiagnosisResult row.');
        $this->assertSame('insufficient_explanatory_evidence', $diagnosisResult->category_key);

        // Final Analyze's System Instruction gained the Decision-enabled
        // block for this AnalysisJob (see BuildAnalysisContextAction §5).
        $this->assertStringContainsString('Decision-enabled', $capturedAnalyzeContext['system_instruction']);
        $this->assertStringContainsString('"recommendations" must be an empty array', $capturedAnalyzeContext['system_instruction']);
        $this->assertStringContainsString('Do not assign priority', $capturedAnalyzeContext['system_instruction']);

        // Diagnosis never receives user_prompt, a Data Profile, or
        // sample_rows — only the Evidence Package.
        foreach ($capturedDiagnosisContexts as $diagnosisContext) {
            $this->assertArrayNotHasKey('user_prompt', $diagnosisContext);
            $this->assertArrayNotHasKey('data_profile', $diagnosisContext);
            $this->assertArrayHasKey('trigger_fact', $diagnosisContext);
            $this->assertArrayHasKey('allowed_categories', $diagnosisContext);
        }
    }

    /**
     * Free Analysis (template_key === null): Diagnosis never runs, and
     * Final Analyze's System Instruction is never Decision-enabled — see
     * docs/product/DIAGNOSIS_ENGINE.md "Free Analysisへの影響".
     */
    public function test_free_analysis_never_runs_diagnosis_and_keeps_the_legacy_system_instruction(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob("region,sales\nTokyo,100\nOsaka,200\n", null);

        $capturedAnalyzeContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedAnalyzeContext): bool {
                $capturedAnalyzeContext = $context;

                return true;
            })
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR))
            ->shouldNotReceive('diagnose');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame(0, DiagnosisResult::query()->count());
        $this->assertStringNotContainsString('Decision-enabled', $capturedAnalyzeContext['system_instruction']);
    }

    /**
     * sales_analysis has no config/evaluation_metrics.php entry (Phase
     * 4-A v1 scope): Diagnosis never runs, and Final Analyze is not
     * Decision-enabled either — see docs/product/DIAGNOSIS_ENGINE.md
     * "Decision-enabled Analysisの定義".
     */
    public function test_sales_analysis_never_runs_diagnosis_and_keeps_the_legacy_system_instruction(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(
            "product,revenue\nWidget,1000\nGadget,2000\n",
            'sales_analysis',
        );

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $capturedAnalyzeContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')
            ->once()
            ->withArgs(function (array $context) use (&$capturedAnalyzeContext): bool {
                $capturedAnalyzeContext = $context;

                return true;
            })
            ->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR))
            ->shouldNotReceive('diagnose');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertSame(0, DiagnosisResult::query()->count());
        $this->assertStringNotContainsString('Decision-enabled', $capturedAnalyzeContext['system_instruction']);
    }

    /**
     * A technical Diagnosis exception must never fail the AnalysisJob —
     * soft-fail, the pipeline continues to Completed with zero
     * DiagnosisResults. See docs/product/DIAGNOSIS_ENGINE.md "soft-fail".
     */
    public function test_a_technical_diagnosis_exception_does_not_fail_the_analysis_job(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV, 'ad_performance');

        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR))
            ->shouldReceive('diagnose')
            ->atLeast()->once()
            ->andThrow(new \RuntimeException('OpenAI API request failed with HTTP status 500.'));

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $analysisJob->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->status);
        $this->assertNull($analysisJob->analysisJobDetail->error_message);
        $this->assertSame(0, DiagnosisResult::query()->where('analysis_job_id', $analysisJob->analysis_job_id)->count());
    }

    public function test_an_orchestration_level_diagnosis_failure_skips_controlled_action(): void
    {
        [, $analysisJob] = $this->createPendingAnalysisJob(self::AD_PERFORMANCE_CSV, 'ad_performance');
        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn($this->emptyPlanResponse())
            ->shouldReceive('analyze')->once()->andReturn(json_encode($this->structuredResult(), JSON_THROW_ON_ERROR));
        $this->mock(RunDiagnosisForAnalysisJobAction::class)
            ->shouldReceive('execute')->once()->andThrow(new \RuntimeException('diagnosis query failed'));
        $this->mock(RunActionProposalForAnalysisJobAction::class)->shouldNotReceive('execute');

        app(ExecuteAnalysisJobAction::class)->execute($analysisJob->analysis_job_id);

        $this->assertSame(AnalysisJobStatus::Completed, $analysisJob->refresh()->status);
    }
}
