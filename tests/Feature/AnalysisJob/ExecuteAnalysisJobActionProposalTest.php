<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\ExecuteAnalysisJobAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\AnalysisJobDetail;
use App\Models\DataFile;
use App\Models\DiagnosisResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ExecuteAnalysisJobActionProposalTest extends TestCase
{
    use RefreshDatabase;

    private const string CSV = <<<'CSV'
        channel,clicks,conversions
        Social,5000,200
        Email,3000,243
        Paid,4000,220
        Organic,3000,147

        CSV;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_successful_upstream_layers_create_action_before_completion_and_strip_legacy_recommendations(): void
    {
        $job = $this->pendingJob('ad_performance', self::CSV);
        $actionCalls = 0;
        $mapResponse = json_encode(['mappings' => [
            ['field' => 'channel', 'column' => 'channel', 'confidence' => 'high'],
            ['field' => 'conversions', 'column' => 'conversions', 'confidence' => 'high'],
            ['field' => 'clicks', 'column' => 'clicks', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR);
        $analysisResponse = json_encode([
            'summary' => 'Summary',
            'highlights' => [],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
            'recommendations' => [[
                'title' => 'Legacy recommendation',
                'description' => 'Must be stripped for Decision-enabled analysis.',
                'priority' => 'high',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')->once()->andReturn($mapResponse)
            ->shouldReceive('planMetrics')->once()->andReturn(json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR))
            ->shouldReceive('analyze')->once()->andReturn($analysisResponse)
            ->shouldReceive('diagnose')->atLeast()->once()->andReturnUsing(function (array $context): string {
                return json_encode([
                    'primary_diagnosis' => [
                        'category_key' => 'insufficient_explanatory_evidence',
                        'self_reported_confidence' => 0.4,
                        'rationale_summary' => 'The evidence does not distinguish a cause.',
                        'evidence_refs' => [$context['trigger_fact']['evidence_id']],
                        'missing_evidence' => ['landing-page-level conversion rate'],
                    ],
                ], JSON_THROW_ON_ERROR);
            })
            ->shouldReceive('proposeAction')->atLeast()->once()->andReturnUsing(function (array $context) use (&$actionCalls): string {
                $actionCalls++;
                $package = $context['evidence_package'];

                return json_encode([
                    'catalog_key' => 'collect_explanatory_evidence',
                    'title' => 'Collect explanatory evidence',
                    'rationale_summary' => 'Collect the missing evidence before considering optimization.',
                    'selected_checks' => [],
                    'evidence_refs' => $package['allowed_evidence_refs'],
                    'missing_evidence' => ['landing-page-level conversion rate'],
                ], JSON_THROW_ON_ERROR);
            });

        app(ExecuteAnalysisJobAction::class)->execute($job->analysis_job_id);

        $job->refresh();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);
        $this->assertSame([], $job->analysisJobDetail->result['recommendations']);
        $this->assertSame($actionCalls, ActionProposal::query()->count());
        $this->assertSame(DiagnosisResult::query()->count(), $actionCalls);
        foreach (ActionProposal::query()->get() as $proposal) {
            $this->assertDatabaseHas('evaluation_facts', ['evaluation_fact_id' => $proposal->evaluation_fact_id]);
            $this->assertDatabaseHas('diagnosis_results', ['diagnosis_result_id' => $proposal->diagnosis_result_id]);
            $this->assertDatabaseHas('priority_results', ['priority_result_id' => $proposal->priority_result_id]);
        }
    }

    public function test_free_form_and_non_decision_templates_make_zero_action_calls(): void
    {
        $diagnosisCalls = 0;
        $actionCalls = 0;
        $client = Mockery::mock(AiAnalysisClient::class);
        $client->shouldReceive('mapColumns')->once()->andReturn(json_encode(['mappings' => [
            ['field' => 'revenue', 'column' => 'revenue', 'confidence' => 'high'],
        ]], JSON_THROW_ON_ERROR));
        $client->shouldNotReceive('planMetrics');
        $client->shouldReceive('analyze')->twice()->andReturn(json_encode([
            'summary' => 'Summary', 'highlights' => [], 'metrics' => [], 'tables' => [], 'insights' => [], 'recommendations' => [],
        ], JSON_THROW_ON_ERROR));
        $client->shouldReceive('diagnose')->zeroOrMoreTimes()->andReturnUsing(function () use (&$diagnosisCalls): string {
            $diagnosisCalls++;

            return '{}';
        });
        $client->shouldReceive('proposeAction')->zeroOrMoreTimes()->andReturnUsing(function () use (&$actionCalls): string {
            $actionCalls++;

            return '{}';
        });
        $this->app->instance(AiAnalysisClient::class, $client);
        $executor = app(ExecuteAnalysisJobAction::class);

        foreach ([null, 'sales_analysis'] as $templateKey) {
            $csv = $templateKey === null ? "region,revenue\nTokyo,1000\n" : "product,revenue\nWidget,1000\n";
            $job = $this->pendingJob($templateKey, $csv);

            $executor->execute($job->analysis_job_id);
            $this->assertSame(AnalysisJobStatus::Completed, $job->refresh()->status);
        }

        $this->assertSame(0, $diagnosisCalls);
        $this->assertSame(0, $actionCalls);
        $this->assertDatabaseCount('action_proposals', 0);
    }

    private function pendingJob(?string $templateKey, string $csv): AnalysisJob
    {
        $path = 'projects/1/data-files/'.Str::uuid().'.csv';
        Storage::disk('local')->put($path, $csv);
        $dataFile = DataFile::factory()->create(['stored_path' => $path]);
        $job = AnalysisJob::factory()->for($dataFile)->create(['template_key' => $templateKey]);
        AnalysisJobDetail::factory()->for($job)->create();

        return $job;
    }
}
