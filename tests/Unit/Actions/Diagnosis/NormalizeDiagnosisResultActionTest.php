<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Diagnosis;

use App\Actions\Diagnosis\NormalizeDiagnosisResultAction;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Direct coverage for Diagnosis AI response validation — the Laravel
 * post-validation layer of "Structured Output + Laravel post-validation +
 * Evidence gating" (see docs/product/DIAGNOSIS_ENGINE.md). Zero AI I/O,
 * zero DB I/O.
 */
class NormalizeDiagnosisResultActionTest extends TestCase
{
    private function action(): NormalizeDiagnosisResultAction
    {
        return new NormalizeDiagnosisResultAction;
    }

    private const array ALLOWED_CATEGORIES = ['measurement_consistency_risk', 'insufficient_explanatory_evidence'];

    private const array SUPPLIED_EVIDENCE_IDS = [
        'trigger:evaluation_fact:123',
        'supporting:spend',
        'supporting:revenue',
    ];

    public function test_a_valid_response_is_normalized(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'measurement_consistency_risk',
                'self_reported_confidence' => 0.35,
                'rationale_summary' => 'Zero conversions despite meaningful traffic.',
                'evidence_refs' => ['trigger:evaluation_fact:123'],
                'missing_evidence' => ['landing-page-level conversion rate'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);

        $this->assertSame('measurement_consistency_risk', $result['category_key']);
        $this->assertSame(0.35, $result['self_reported_confidence']);
        $this->assertSame('Zero conversions despite meaningful traffic.', $result['rationale_summary']);
        $this->assertSame(['trigger:evaluation_fact:123'], $result['evidence_refs']);
        $this->assertSame(['landing-page-level conversion rate'], $result['missing_evidence']);
    }

    public function test_a_category_not_in_allowed_categories_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'landing_page_mismatch',
                'self_reported_confidence' => 0.5,
                'rationale_summary' => 'x',
                'evidence_refs' => [],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    /**
     * Evidence-grounding minimum: every DiagnosisResult must trace back to
     * at least one supplied Fact — an empty evidence_refs is rejected even
     * though every other field is otherwise valid. See
     * docs/product/DIAGNOSIS_ENGINE.md "evidence_refs".
     */
    public function test_an_empty_evidence_refs_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'The evidence does not distinguish a specific cause.',
                'evidence_refs' => [],
                'missing_evidence' => ['landing-page-level conversion rate'],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Diagnosis AI response "primary_diagnosis.evidence_refs" must contain at least one supplied evidence identifier.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Citing only the trigger Fact is sufficient and valid — Diagnosis
     * never requires a supporting_facts citation, only at least one
     * evidence_ref total.
     */
    public function test_citing_only_the_trigger_fact_is_valid(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'measurement_consistency_risk',
                'self_reported_confidence' => 0.5,
                'rationale_summary' => 'Zero conversions despite meaningful traffic.',
                'evidence_refs' => ['trigger:evaluation_fact:123'],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);

        $this->assertSame(['trigger:evaluation_fact:123'], $result['evidence_refs']);
    }

    /**
     * Abstention (insufficient_explanatory_evidence) still requires the
     * same evidence-grounding minimum — citing only the trigger Fact (the
     * thing that was actually evaluated) is valid and expected. See
     * docs/product/DIAGNOSIS_ENGINE.md "evidence_refs".
     */
    public function test_abstention_with_only_a_trigger_evidence_ref_is_valid(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'The evidence does not distinguish a specific cause.',
                'evidence_refs' => ['trigger:evaluation_fact:123'],
                'missing_evidence' => ['landing-page-level conversion rate'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);

        $this->assertSame('insufficient_explanatory_evidence', $result['category_key']);
        $this->assertSame(['trigger:evaluation_fact:123'], $result['evidence_refs']);
    }

    /**
     * Hallucinated Evidence Rate: an evidence_ref outside this exact
     * request's supplied evidence identifiers is rejected — the DiagnosisResult
     * is never persisted for this response. See
     * docs/product/DIAGNOSIS_ENGINE.md "Hallucinated Evidence Rate".
     */
    public function test_an_unknown_evidence_ref_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'x',
                'evidence_refs' => ['supporting:not_exists'],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_confidence_below_zero_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => -0.1,
                'rationale_summary' => 'x',
                'evidence_refs' => [],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_confidence_above_one_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 1.1,
                'rationale_summary' => 'x',
                'evidence_refs' => [],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_a_missing_rationale_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'evidence_refs' => [],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_an_empty_rationale_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => '   ',
                'evidence_refs' => [],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_a_non_array_missing_evidence_is_rejected(): void
    {
        $raw = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'x',
                'evidence_refs' => ['trigger:evaluation_fact:123'],
                'missing_evidence' => 'landing page data',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_a_missing_primary_diagnosis_is_rejected(): void
    {
        $raw = json_encode(['something_else' => true], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute($raw, self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action()->execute('{not valid json', self::ALLOWED_CATEGORIES, self::SUPPLIED_EVIDENCE_IDS);
    }
}
