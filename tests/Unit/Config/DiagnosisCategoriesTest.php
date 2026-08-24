<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Validates the shape and — for measurement_consistency_risk specifically
 * — the wording of config/diagnosis_categories.php. This is a plain
 * config array (no Category class hierarchy — mirrors
 * tests/Unit/Config/AnalysisTemplatesTest.php's own convention), so its
 * structural integrity is guarded by this test rather than by PHP type
 * declarations.
 *
 * The wording assertions exist specifically because
 * measurement_consistency_risk is the one category whose description is
 * easy to accidentally overclaim (see docs/product/DIAGNOSIS_ENGINE.md
 * "measurement_consistency_risk semantics") — this test is a regression
 * guard against that description drifting back toward asserting a
 * confirmed problem.
 */
class DiagnosisCategoriesTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const array OVERCLAIMING_PHRASES = [
        'tracking is broken',
        'tracking failure confirmed',
        'confirmed tracking failure',
        'measurement failure confirmed',
        'confirmed measurement failure',
        'is broken',
        'is likely',
        'more likely',
        'probable',
    ];

    public function test_both_v1_categories_exist(): void
    {
        $categories = config('diagnosis_categories.categories');

        $this->assertArrayHasKey('measurement_consistency_risk', $categories);
        $this->assertArrayHasKey('insufficient_explanatory_evidence', $categories);
        $this->assertCount(2, $categories, 'Phase 4-B v1 deliberately keeps the catalog to exactly 2 entries.');
    }

    public function test_every_category_has_label_description_and_applicable_metrics(): void
    {
        foreach (config('diagnosis_categories.categories') as $key => $category) {
            $this->assertIsString($category['label'] ?? null, "Category \"{$key}\" is missing a string \"label\".");
            $this->assertIsString($category['description'] ?? null, "Category \"{$key}\" is missing a string \"description\".");
            $this->assertIsArray($category['applicable_metrics'] ?? null, "Category \"{$key}\" is missing an \"applicable_metrics\" array.");
        }
    }

    /**
     * The description must read as a verification candidate, never as a
     * confirmed finding — see docs/product/DIAGNOSIS_ENGINE.md
     * "measurement_consistency_risk semantics".
     */
    public function test_measurement_consistency_risk_description_does_not_overclaim(): void
    {
        $description = mb_strtolower(config('diagnosis_categories.categories.measurement_consistency_risk.description'));

        foreach (self::OVERCLAIMING_PHRASES as $phrase) {
            $this->assertStringNotContainsString($phrase, $description, "measurement_consistency_risk description must not contain \"{$phrase}\".");
        }

        $this->assertStringContainsString('worth verifying', $description);
        $this->assertStringContainsString('equally possible', $description);
    }
}
