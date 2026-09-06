<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\ActionProposal;

use App\Actions\ActionProposal\NormalizeActionProposalAction;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NormalizeActionProposalActionTest extends TestCase
{
    public function test_it_accepts_canonical_output_for_both_catalog_entries(): void
    {
        [$job, $fact, $package] = $this->context();
        $measurement = $this->validOutput();
        $this->assertSame($measurement, $this->normalize($measurement, $package, $job, $fact));

        [$job, $fact, $package] = $this->context('collect_explanatory_evidence');
        $collection = $this->validOutput('collect_explanatory_evidence');
        $this->assertSame($collection, $this->normalize($collection, $package, $job, $fact));
    }

    #[DataProvider('invalidOutputProvider')]
    public function test_it_rejects_contract_violations(callable $mutate): void
    {
        [$job, $fact, $package] = $this->context();
        $output = $this->validOutput();
        $mutate($output, $package, $job, $fact);

        $this->expectException(InvalidArgumentException::class);
        $this->normalize($output, $package, $job, $fact);
    }

    public static function invalidOutputProvider(): array
    {
        return [
            'unknown catalog' => [fn (&$o) => $o['catalog_key'] = 'other'],
            'unknown check' => [fn (&$o) => $o['selected_checks'] = ['execute_campaign']],
            'duplicate check' => [fn (&$o) => $o['selected_checks'] = ['verify_tag_firing', 'verify_tag_firing']],
            'unknown reference' => [fn (&$o) => $o['evidence_refs'] = ['evaluation_fact:999']],
            'empty references' => [fn (&$o) => $o['evidence_refs'] = []],
            'extra property' => [fn (&$o) => $o['execute'] = true],
            'overlong title' => [fn (&$o) => $o['title'] = str_repeat('あ', 121)],
            'overlong rationale' => [fn (&$o) => $o['rationale_summary'] = str_repeat('a', 1001)],
            'unsupported missing evidence' => [fn (&$o) => $o['missing_evidence'] = ['invented']],
            'mismatched job' => [fn (&$o, &$p) => $p['analysis_job_id'] = 999],
            'mismatched fact' => [fn (&$o, &$p) => $p['trigger_fact']['evaluation_fact_id'] = 999],
        ];
    }

    private function normalize(array $output, array $package, AnalysisJob $job, EvaluationFact $fact): array
    {
        return (new NormalizeActionProposalAction)->execute(
            json_encode($output, JSON_THROW_ON_ERROR),
            $package,
            $job,
            $fact,
        );
    }

    private function context(string $catalogKey = 'verify_measurement_consistency'): array
    {
        $job = new AnalysisJob(['template_key' => 'ad_performance']);
        $job->analysis_job_id = 1;
        $fact = new EvaluationFact(['analysis_job_id' => 1]);
        $fact->evaluation_fact_id = 10;
        $diagnosis = new DiagnosisResult([
            'analysis_job_id' => 1,
            'evaluation_fact_id' => 10,
            'missing_evidence_json' => ['landing page conversion rate'],
        ]);
        $diagnosis->diagnosis_result_id = 20;
        $priority = new PriorityResult(['analysis_job_id' => 1, 'evaluation_fact_id' => 10]);
        $priority->priority_result_id = 30;
        $fact->setRelation('diagnosisResult', $diagnosis);
        $fact->setRelation('priorityResult', $priority);

        return [$job, $fact, [
            'analysis_job_id' => 1,
            'action_catalog_key' => $catalogKey,
            'trigger_fact' => ['evaluation_fact_id' => 10],
            'diagnosis' => ['diagnosis_result_id' => 20, 'missing_evidence' => ['landing page conversion rate']],
            'priority' => ['priority_result_id' => 30],
            'allowed_checks' => $catalogKey === 'verify_measurement_consistency' ? ['verify_tag_firing'] : [],
            'allowed_evidence_refs' => ['evaluation_fact:10', 'diagnosis_result:20', 'priority_result:30'],
        ]];
    }

    private function validOutput(string $catalogKey = 'verify_measurement_consistency'): array
    {
        return [
            'catalog_key' => $catalogKey,
            'title' => $catalogKey === 'verify_measurement_consistency' ? 'Verify measurement' : 'Collect evidence',
            'rationale_summary' => 'Evidence supports a review proposal.',
            'selected_checks' => $catalogKey === 'verify_measurement_consistency' ? ['verify_tag_firing'] : [],
            'evidence_refs' => ['evaluation_fact:10', 'diagnosis_result:20', 'priority_result:30'],
            'missing_evidence' => $catalogKey === 'collect_explanatory_evidence' ? ['landing page conversion rate'] : [],
        ];
    }
}
