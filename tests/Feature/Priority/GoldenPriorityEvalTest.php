<?php

declare(strict_types=1);

namespace Tests\Feature\Priority;

use App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction;
use App\Actions\Priority\CalculatePriorityAction;
use App\Actions\Priority\ResolvePriorityRuleAction;
use App\Models\EvaluationFact;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden Priority Eval Dataset (Phase 4-C v1). See
 * docs/product/PRIORITY_ENGINE.md "Golden Priority Eval".
 *
 * Mirrors GoldenDiagnosisEvalTest's own repo-fixture-based convention.
 * Unlike Diagnosis, Priority is entirely AI-free, so — unlike
 * GoldenDiagnosisEvalTest, which only exercises the deterministic half of
 * Diagnosis — this test exercises Priority *completely*: eligibility,
 * Impact/Gap/Score computation, and Band assignment. There is no
 * AI-quality half left over for a separate Real API E2E check.
 *
 * Each fixture in tests/fixtures/priority_eval/*.json encodes one
 * EvaluationFact-shaped scenario from the Case A/B/C Product Validation
 * plus three dedicated Priority synthetic cases (dominant_bad,
 * dominant_bad_90, tiny_severe — see docs/product/PRIORITY_ENGINE.md
 * "追加synthetic Priority cases"). Every fixture's trigger_fact carries
 * metric_value/test_baseline_value (never delta_absolute/display baseline
 * — see CalculatePriorityAction's class docblock for why Gap is measured
 * against the leave-one-out control, not the self-diluting display
 * average).
 */
class GoldenPriorityEvalTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        $files = glob(__DIR__.'/../../fixtures/priority_eval/*.json');
        sort($files);

        return array_map(static fn (string $file): array => [$file], $files);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadFixture(string $fixtureFile): array
    {
        return json_decode(file_get_contents($fixtureFile), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null null when the fixture's own
     *                                   trigger_fact is not Priority-eligible.
     */
    private function computeFixture(array $fixture): ?array
    {
        $fact = new EvaluationFact($fixture['trigger_fact']);

        $eligible = (new DetermineDiagnosisEligibilityAction)->execute($fact, $fixture['template_key']);

        if (! $eligible) {
            return null;
        }

        $rule = (new ResolvePriorityRuleAction)->execute($fixture['template_key'], $fact->metric_key);
        $this->assertNotNull($rule, "Fixture {$fixture['case']}: no Priority rule resolved for {$fixture['template_key']}/{$fact->metric_key}.");

        return (new CalculatePriorityAction)->execute(
            (float) $fixture['trigger_fact']['denominator_value'],
            (float) $fixture['impact_context']['impact_total'],
            (float) $fixture['trigger_fact']['metric_value'],
            (float) $fixture['trigger_fact']['test_baseline_value'],
            $rule,
        );
    }

    #[DataProvider('fixtureProvider')]
    public function test_fixture_matches_expected_eligibility_band_and_score_range(string $fixtureFile): void
    {
        $fixture = self::loadFixture($fixtureFile);
        $fact = new EvaluationFact($fixture['trigger_fact']);

        $eligible = (new DetermineDiagnosisEligibilityAction)->execute($fact, $fixture['template_key']);

        $this->assertSame(
            $fixture['expected']['eligible'],
            $eligible,
            "Fixture {$fixture['case']}: eligibility mismatch.",
        );

        if (! $eligible) {
            return;
        }

        $result = $this->computeFixture($fixture);

        $this->assertSame(
            $fixture['expected']['priority_band'],
            $result['priority_band'],
            "Fixture {$fixture['case']}: priority_band mismatch (score={$result['priority_score']}).",
        );

        $this->assertGreaterThanOrEqual(
            $fixture['expected']['min_score'],
            $result['priority_score'],
            "Fixture {$fixture['case']}: priority_score below expected range.",
        );

        $this->assertLessThanOrEqual(
            $fixture['expected']['max_score'],
            $result['priority_score'],
            "Fixture {$fixture['case']}: priority_score above expected range.",
        );

        $this->assertGreaterThanOrEqual(0.0, $result['priority_score']);
        $this->assertLessThanOrEqual(1.0, $result['priority_score']);
    }

    /**
     * Relative ranking: a fixture's "greater_than" names another fixture's
     * "case" whose priority_score it must strictly exceed — verified even
     * while v1's absolute band thresholds (§23) remain a first Golden
     * Tuning pass, per docs/product/PRIORITY_ENGINE.md "Golden Priority
     * Eval" / "Human ranking validation".
     */
    #[DataProvider('fixtureProvider')]
    public function test_fixture_outranks_its_declared_lower_priority_counterpart(string $fixtureFile): void
    {
        $fixture = self::loadFixture($fixtureFile);

        if (! isset($fixture['expected']['greater_than'])) {
            $this->markTestSkipped("Fixture {$fixture['case']} declares no relative ranking expectation.");
        }

        $thisScore = $this->computeFixture($fixture)['priority_score'];

        $otherCase = $fixture['expected']['greater_than'];
        $otherFile = __DIR__."/../../fixtures/priority_eval/{$otherCase}.json";
        $this->assertFileExists($otherFile, "Fixture {$fixture['case']}: greater_than references unknown case \"{$otherCase}\".");

        $otherScore = $this->computeFixture(self::loadFixture($otherFile))['priority_score'];

        $this->assertGreaterThan(
            $otherScore,
            $thisScore,
            "Fixture {$fixture['case']} ({$thisScore}) must outrank {$otherCase} ({$otherScore}).",
        );
    }

    /**
     * The two new Priority-specific synthetic cases (§40/§50/§51 of the
     * Phase 4-C investigation): a dominant, severely underperforming
     * channel must rank above every Case A/B/C entity, while a
     * statistically valid but negligible-traffic anomaly must rank below
     * all of them — Impact Score is what separates the two, not Gap Score
     * (both have a comparably large gap).
     */
    public function test_dominant_bad_outranks_case_a_social_and_tiny_severe_ranks_below_it(): void
    {
        $dominantBad = $this->computeFixture(self::loadFixture(__DIR__.'/../../fixtures/priority_eval/dominant_bad.json'));
        $caseASocial = $this->computeFixture(self::loadFixture(__DIR__.'/../../fixtures/priority_eval/case_a_social.json'));
        $tinySevere = $this->computeFixture(self::loadFixture(__DIR__.'/../../fixtures/priority_eval/tiny_severe.json'));

        $this->assertGreaterThan($caseASocial['priority_score'], $dominantBad['priority_score']);
        $this->assertLessThan($caseASocial['priority_score'], $tinySevere['priority_score']);
    }

    /**
     * The self-dilution regression (commit-before-review finding): holding
     * the exact same peer gap (CVR 2.0% vs. a 6.0% leave-one-out control),
     * raising the dominant channel's traffic share from 70% to 90% must
     * *increase* priority_score, never decrease it — and gap_raw_value
     * itself must be identical between the two, since test_baseline_value
     * is unaffected by the entity's own share. Under the old
     * display_baseline-based formula this would have failed: a larger
     * share pulls the display average toward the entity's own rate and
     * shrinks its *measured* gap, working directly against Impact's
     * purpose of weighting large-share entities more heavily.
     */
    public function test_raising_traffic_share_from_70_to_90_percent_increases_priority_never_decreases_it(): void
    {
        $dominantBad70 = $this->computeFixture(self::loadFixture(__DIR__.'/../../fixtures/priority_eval/dominant_bad.json'));
        $dominantBad90 = $this->computeFixture(self::loadFixture(__DIR__.'/../../fixtures/priority_eval/dominant_bad_90.json'));

        $this->assertEqualsWithDelta(
            $dominantBad70['gap_raw_value'],
            $dominantBad90['gap_raw_value'],
            1e-9,
            'gap_raw_value must not shrink just because traffic share grew — it depends only on the leave-one-out peer control.',
        );

        $this->assertGreaterThan(
            $dominantBad70['priority_score'],
            $dominantBad90['priority_score'],
            'priority_score must increase, not decrease, as the dominant channel\'s share grows from 70% to 90% with the same peer gap.',
        );

        $this->assertSame('high', $dominantBad70['priority_band']);
        $this->assertSame('high', $dominantBad90['priority_band']);
    }

    /**
     * dominant_bad_90 fixes evaluation_level at "medium", not "high" — a
     * deliberate detail (see PRIORITY_ENGINE.md "§8-2 Known Limitation:
     * dominant-entity self-dilution in Evaluation practical significance"):
     * at 90% traffic share, this entity's own rate pulls
     * display_baseline_value toward itself enough that delta_absolute
     * (0.02 - 0.024 = -0.004pp) falls under Phase 4-A's own practical
     * significance floor and downgrades from high to medium — a Phase 4-A
     * limitation, unrelated to and unfixed by this Priority Gap change.
     * This single assertion pins the concrete "Evaluation Medium, Priority
     * High" pairing that demonstrates Phase 4-C's core design claim
     * (§2): Evaluation strength and Priority importance are not the same
     * axis.
     */
    public function test_dominant_bad_90_pairs_a_medium_evaluation_level_with_a_high_priority(): void
    {
        $fixture = self::loadFixture(__DIR__.'/../../fixtures/priority_eval/dominant_bad_90.json');

        $this->assertSame('medium', $fixture['trigger_fact']['evaluation_level']);
        $this->assertSame('high', $this->computeFixture($fixture)['priority_band']);
    }

    /**
     * Every fixture referenced by any "greater_than" must exist —
     * catches a fixture typo the same way GoldenDiagnosisEvalTest's own
     * catalog-existence check does for allowed_categories.
     */
    public function test_every_greater_than_reference_points_to_an_existing_fixture(): void
    {
        $knownCases = array_map(
            static fn (string $file): string => self::loadFixture($file)['case'],
            array_map(fn (array $row) => $row[0], self::fixtureProvider()),
        );

        foreach (self::fixtureProvider() as [$fixtureFile]) {
            $fixture = self::loadFixture($fixtureFile);
            $reference = $fixture['expected']['greater_than'] ?? null;

            if ($reference === null) {
                continue;
            }

            $this->assertContains(
                $reference,
                $knownCases,
                "Fixture {$fixture['case']}: greater_than references unknown case \"{$reference}\".",
            );
        }
    }
}
