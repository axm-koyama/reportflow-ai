<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Validates the shape of config/analysis_templates.php. This is a plain
 * config array (no Template class hierarchy, no DTO — see
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md), so its structural integrity
 * is guarded by this test rather than by PHP type declarations.
 */
class AnalysisTemplatesTest extends TestCase
{
    /**
     * The operators CalculateDerivedMetricsAction allows. Kept in sync
     * manually; see docs/product/DERIVED_METRICS.md "Allowed Operators".
     *
     * @var list<string>
     */
    private const array ALLOWED_OPERATORS = ['divide', 'multiply', 'add', 'subtract', 'percentage'];

    /**
     * @var list<string>
     */
    private const array ALLOWED_KINDS = ['dimension', 'measure', 'temporal'];

    public function test_ad_performance_template_exists(): void
    {
        $this->assertIsArray(config('analysis_templates.ad_performance'));
    }

    public function test_every_template_has_the_required_top_level_keys(): void
    {
        foreach (config('analysis_templates') as $key => $template) {
            $this->assertIsString($template['name'] ?? null, "Template \"{$key}\" is missing a string \"name\".");
            $this->assertNotSame('', $template['name'], "Template \"{$key}\" has an empty \"name\".");

            $this->assertIsString($template['description'] ?? null, "Template \"{$key}\" is missing a string \"description\".");
            $this->assertNotSame('', $template['description'], "Template \"{$key}\" has an empty \"description\".");

            $this->assertIsString($template['instruction'] ?? null, "Template \"{$key}\" is missing a string \"instruction\".");
            $this->assertNotSame('', $template['instruction'], "Template \"{$key}\" has an empty \"instruction\".");

            $this->assertIsArray($template['fields'] ?? null, "Template \"{$key}\" is missing a \"fields\" array.");
            $this->assertNotSame([], $template['fields'], "Template \"{$key}\" has an empty \"fields\" array.");
        }
    }

    public function test_every_field_has_a_valid_kind_and_label(): void
    {
        foreach (config('analysis_templates') as $templateKey => $template) {
            foreach ($template['fields'] as $fieldKey => $field) {
                $this->assertContains(
                    $field['kind'] ?? null,
                    self::ALLOWED_KINDS,
                    "Template \"{$templateKey}\" field \"{$fieldKey}\" has an invalid \"kind\".",
                );

                $this->assertIsString($field['label'] ?? null, "Template \"{$templateKey}\" field \"{$fieldKey}\" is missing a \"label\".");
                $this->assertNotSame('', $field['label'], "Template \"{$templateKey}\" field \"{$fieldKey}\" has an empty \"label\".");
            }
        }
    }

    public function test_required_fields_exist_in_the_fields_list(): void
    {
        foreach (config('analysis_templates') as $templateKey => $template) {
            $fieldKeys = array_keys($template['fields']);

            foreach ($template['required_fields'] ?? [] as $requiredField) {
                $this->assertContains(
                    $requiredField,
                    $fieldKeys,
                    "Template \"{$templateKey}\" required_fields references unknown field \"{$requiredField}\".",
                );
            }
        }
    }

    public function test_required_field_group_members_exist_in_the_fields_list(): void
    {
        foreach (config('analysis_templates') as $templateKey => $template) {
            $fieldKeys = array_keys($template['fields']);

            foreach ($template['required_field_groups'] ?? [] as $groupIndex => $group) {
                $this->assertIsArray($group, "Template \"{$templateKey}\" required_field_groups[{$groupIndex}] must be an array.");
                $this->assertNotSame([], $group, "Template \"{$templateKey}\" required_field_groups[{$groupIndex}] must not be empty.");

                foreach ($group as $member) {
                    $this->assertContains(
                        $member,
                        $fieldKeys,
                        "Template \"{$templateKey}\" required_field_groups[{$groupIndex}] references unknown field \"{$member}\".",
                    );
                }
            }
        }
    }

    public function test_recommended_derived_metrics_reference_known_fields_and_allowed_operators(): void
    {
        foreach (config('analysis_templates') as $templateKey => $template) {
            $fieldKeys = array_keys($template['fields']);

            foreach ($template['recommended_derived_metrics'] ?? [] as $index => $recommendation) {
                $this->assertIsString($recommendation['name'] ?? null, "Template \"{$templateKey}\" recommended_derived_metrics[{$index}] is missing \"name\".");
                $this->assertNotSame('', $recommendation['name'], "Template \"{$templateKey}\" recommended_derived_metrics[{$index}] has an empty \"name\".");

                $this->assertContains(
                    $recommendation['left_field'] ?? null,
                    $fieldKeys,
                    "Template \"{$templateKey}\" recommended_derived_metrics[{$index}] references unknown left_field.",
                );

                $this->assertContains(
                    $recommendation['right_field'] ?? null,
                    $fieldKeys,
                    "Template \"{$templateKey}\" recommended_derived_metrics[{$index}] references unknown right_field.",
                );

                $this->assertContains(
                    $recommendation['operator_hint'] ?? null,
                    self::ALLOWED_OPERATORS,
                    "Template \"{$templateKey}\" recommended_derived_metrics[{$index}] has an invalid operator_hint.",
                );
            }
        }
    }

    public function test_ad_performance_requires_only_channel_directly(): void
    {
        $this->assertSame(['channel'], config('analysis_templates.ad_performance.required_fields'));
    }

    public function test_ad_performance_requires_at_least_one_numeric_measure(): void
    {
        $groups = config('analysis_templates.ad_performance.required_field_groups');

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing(
            ['spend', 'revenue', 'conversions', 'clicks', 'impressions'],
            $groups[0],
        );
    }

    // --- sales_analysis (Phase 3-B) ------------------------------------

    public function test_sales_analysis_template_exists(): void
    {
        $this->assertIsArray(config('analysis_templates.sales_analysis'));
    }

    /**
     * "revenue" is the only required field — every other semantic field
     * (quantity/orders/product/category/store/region/customer/date) is
     * optional, per docs/product/ANALYSIS_TEMPLATE_MODULE.md §14: Sales
     * CSVs vary widely in shape, and whichever optional field actually
     * resolves simply becomes an available cut for the analysis.
     */
    public function test_sales_analysis_requires_only_revenue(): void
    {
        $this->assertSame(['revenue'], config('analysis_templates.sales_analysis.required_fields'));
        $this->assertSame([], config('analysis_templates.sales_analysis.required_field_groups'));
    }

    /**
     * "date" is declared as a "temporal" field like ad_performance's own
     * "date" field — Column Mapping candidate filtering (kind -> inferred_type)
     * is identical regardless of Template, so this is a config-shape
     * assertion, not new Framework behavior.
     */
    public function test_sales_analysis_date_field_is_temporal(): void
    {
        $this->assertSame('temporal', config('analysis_templates.sales_analysis.fields.date.kind'));
    }

    /**
     * average_unit_price = revenue/quantity, average_order_value =
     * revenue/orders — these must never be swapped (see the Phase 3-B
     * design discussion distinguishing the two).
     */
    public function test_sales_analysis_recommended_derived_metrics_use_the_correct_field_pairs(): void
    {
        $recommendations = collect(config('analysis_templates.sales_analysis.recommended_derived_metrics'))
            ->keyBy('name');

        $this->assertSame('revenue', $recommendations['average_unit_price']['left_field']);
        $this->assertSame('quantity', $recommendations['average_unit_price']['right_field']);

        $this->assertSame('revenue', $recommendations['average_order_value']['left_field']);
        $this->assertSame('orders', $recommendations['average_order_value']['right_field']);
    }
}
