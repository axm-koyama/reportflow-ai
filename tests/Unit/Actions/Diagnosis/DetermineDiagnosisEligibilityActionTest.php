<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Diagnosis;

use App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction;
use App\Models\EvaluationFact;
use Tests\TestCase;

/**
 * Direct coverage for Diagnosis Eligibility (Phase 4-B v1). See
 * docs/product/DIAGNOSIS_ENGINE.md "Diagnosis Eligibility".
 *
 * Zero AI I/O, zero DB I/O — a fresh (unsaved) EvaluationFact model
 * instance is enough; this action never touches the database.
 */
class DetermineDiagnosisEligibilityActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'evaluation_metrics.ad_performance.metrics' => [
                [
                    'metric_key' => 'conversion_rate',
                    'unfavorable_direction' => 'below',
                ],
            ],
        ]);
    }

    private function action(): DetermineDiagnosisEligibilityAction
    {
        return new DetermineDiagnosisEligibilityAction;
    }

    /**
     * A plain, unsaved model instance (never EvaluationFact::factory(),
     * whose default 'analysis_job_id' => AnalysisJob::factory() attribute
     * would persist a related row even under make() — this test suite is
     * deliberately DB-free).
     */
    private function fact(string $evaluationLevel, ?string $direction, string $metricKey = 'conversion_rate'): EvaluationFact
    {
        return new EvaluationFact([
            'evaluation_level' => $evaluationLevel,
            'direction' => $direction,
            'metric_key' => $metricKey,
        ]);
    }

    public function test_high_unfavorable_is_eligible(): void
    {
        $this->assertTrue($this->action()->execute($this->fact('high', 'below'), 'ad_performance'));
    }

    public function test_medium_unfavorable_is_eligible(): void
    {
        $this->assertTrue($this->action()->execute($this->fact('medium', 'below'), 'ad_performance'));
    }

    public function test_high_favorable_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('high', 'above'), 'ad_performance'));
    }

    public function test_medium_favorable_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('medium', 'above'), 'ad_performance'));
    }

    public function test_low_unfavorable_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('low', 'below'), 'ad_performance'));
    }

    public function test_insufficient_data_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('insufficient_data', null), 'ad_performance'));
    }

    public function test_equal_direction_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('high', 'equal'), 'ad_performance'));
    }

    /**
     * A whitelist, not a blacklist: an evaluation_level this class does
     * not recognize is rejected the same way "low"/"insufficient_data"
     * are — Diagnosis eligibility never silently expands just because
     * EvaluateAnalysisJobAction starts producing a new level value.
     */
    public function test_unknown_future_evaluation_level_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('critical', 'below'), 'ad_performance'));
    }

    public function test_a_template_with_no_evaluation_metrics_entry_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute($this->fact('high', 'below'), 'sales_analysis'));
    }

    public function test_a_metric_key_not_configured_for_this_template_is_not_eligible(): void
    {
        $this->assertFalse($this->action()->execute(
            $this->fact('high', 'below', 'unknown_metric'),
            'ad_performance',
        ));
    }
}
