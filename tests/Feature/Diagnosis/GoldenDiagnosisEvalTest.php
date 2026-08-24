<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnosis;

use App\Actions\Diagnosis\BuildDiagnosisEvidencePackageAction;
use App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction;
use App\Models\EvaluationFact;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden Diagnosis Eval Dataset (Phase 4-B v1, deterministic half). See
 * docs/product/DIAGNOSIS_ENGINE.md "Golden AI Eval Plan".
 *
 * Repo-fixture-based, not OpenAI Evals — kept Provider-neutral, matching
 * ReportFlow AI's existing AiAnalysisClient design (see that class's own
 * docblock). Each fixture in tests/fixtures/diagnosis_eval/*.json encodes
 * one EvaluationFact-shaped scenario from the Case A/B/C product
 * validation (see docs/product/DIAGNOSIS_ENGINE.md "Case A/B/C expected
 * Diagnosis") plus a dedicated measurement_consistency_risk scenario.
 *
 * This test exercises only the *deterministic* half of Diagnosis
 * (DetermineDiagnosisEligibilityAction / BuildDiagnosisEvidencePackageAction's
 * Evidence Gate) — the half that must never call the AI to be verified.
 * The AI-quality half (must_abstain / forbidden_phrases against actual
 * model output) is exercised by the Real API E2E run instead — see
 * docs/product/DIAGNOSIS_ENGINE.md "Real API E2E Plan". Each fixture's
 * "expected.must_abstain" / "expected.forbidden_phrases" are carried here
 * only so a human or an E2E script can reuse the same fixture file for
 * that AI-quality check; this test itself does not call the AI.
 */
class GoldenDiagnosisEvalTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        $files = glob(__DIR__.'/../../fixtures/diagnosis_eval/*.json');
        sort($files);

        return array_map(static fn (string $file): array => [$file], $files);
    }

    #[DataProvider('fixtureProvider')]
    public function test_fixture_matches_deterministic_eligibility_and_evidence_gate_expectations(string $fixtureFile): void
    {
        $fixture = json_decode(file_get_contents($fixtureFile), associative: true, flags: JSON_THROW_ON_ERROR);

        $fact = new EvaluationFact($fixture['trigger_fact']);

        $eligible = (new DetermineDiagnosisEligibilityAction)->execute($fact, $fixture['template_key']);

        $this->assertSame(
            $fixture['expected']['eligible'],
            $eligible,
            "Fixture {$fixture['case']}/{$fixture['trigger_fact']['entity_key']}: eligibility mismatch.",
        );

        if (! $eligible) {
            return;
        }

        // Supporting facts are irrelevant to allowed_categories in v1
        // (see BuildDiagnosisEvidencePackageAction::allowedCategories() —
        // it consults trigger_fact only), so empty aggregated_metrics /
        // effective_column_mapping are sufficient here; supporting_facts
        // extraction itself has its own dedicated coverage in
        // BuildDiagnosisEvidencePackageActionTest.
        $package = (new BuildDiagnosisEvidencePackageAction)->execute(
            $fact,
            ['dimensions' => [], 'measures' => []],
            [],
            $fixture['template_key'],
        );

        sort($package['allowed_categories']);
        $expectedAllowed = $fixture['expected']['allowed_categories'];
        sort($expectedAllowed);

        $this->assertSame(
            $expectedAllowed,
            $package['allowed_categories'],
            "Fixture {$fixture['case']}/{$fixture['trigger_fact']['entity_key']}: allowed_categories mismatch.",
        );

        foreach ($fixture['expected']['forbidden_categories'] as $forbiddenCategory) {
            $this->assertNotContains(
                $forbiddenCategory,
                $package['allowed_categories'],
                "Fixture {$fixture['case']}/{$fixture['trigger_fact']['entity_key']}: forbidden category \"{$forbiddenCategory}\" must never be offered to the AI.",
            );
        }

        // Abstention is always available whenever Diagnosis runs at all.
        $this->assertContains('insufficient_explanatory_evidence', $package['allowed_categories']);
    }

    /**
     * Every category referenced by any fixture's expected.allowed_categories
     * must actually exist in config/diagnosis_categories.php — catches a
     * fixture typo or a stale category reference after a Catalog change.
     */
    public function test_every_fixture_expected_category_exists_in_the_catalog(): void
    {
        $catalog = array_keys(config('diagnosis_categories.categories', []));

        foreach (self::fixtureProvider() as [$fixtureFile]) {
            $fixture = json_decode(file_get_contents($fixtureFile), associative: true, flags: JSON_THROW_ON_ERROR);

            foreach ($fixture['expected']['allowed_categories'] as $categoryKey) {
                $this->assertContains(
                    $categoryKey,
                    $catalog,
                    'Fixture '.basename($fixtureFile).": \"{$categoryKey}\" is not a config/diagnosis_categories.php key.",
                );
            }
        }
    }
}
