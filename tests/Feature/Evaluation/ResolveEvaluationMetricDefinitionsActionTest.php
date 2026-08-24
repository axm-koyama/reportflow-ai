<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Actions\Evaluation\ResolveEvaluationMetricDefinitionsAction;
use Tests\TestCase;

/**
 * Direct unit coverage for translating config/evaluation_metrics.php's
 * semantic metric definitions into real-column-level definitions via
 * effective_column_mapping. See docs/product/EVALUATION_ENGINE.md
 * "Effective Mapping" / "Mapping不足時".
 */
class ResolveEvaluationMetricDefinitionsActionTest extends TestCase
{
    private function action(): ResolveEvaluationMetricDefinitionsAction
    {
        return new ResolveEvaluationMetricDefinitionsAction;
    }

    public function test_resolves_ad_performance_conversion_rate_with_english_column_names(): void
    {
        $mapping = [
            'channel' => ['column' => 'channel', 'status' => 'mapped', 'source' => 'ai'],
            'conversions' => ['column' => 'conversions', 'status' => 'mapped', 'source' => 'ai'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped', 'source' => 'ai'],
        ];

        $result = $this->action()->execute('ad_performance', $mapping);

        $this->assertSame([], $result['skipped']);
        $this->assertCount(1, $result['resolved']);

        $definition = $result['resolved'][0];
        $this->assertSame('conversion_rate', $definition['metric_key']);
        $this->assertSame('rate', $definition['metric_type']);
        $this->assertSame('channel', $definition['entity_type']);
        $this->assertSame('channel', $definition['entity_field']);
        $this->assertSame('conversions', $definition['numerator_field']);
        $this->assertSame('clicks', $definition['denominator_field']);
        $this->assertSame('below', $definition['unfavorable_direction']);
        $this->assertSame(0.005, $definition['practical_significance_floor']);
        $this->assertSame(5, $definition['normal_approximation_min_expected']);
        $this->assertSame(1.96, $definition['z_threshold_high']);
        $this->assertSame(1.00, $definition['z_threshold_medium']);
        $this->assertSame('evaluation_rule_v1.0', $definition['rule_version']);
    }

    public function test_resolves_ad_performance_conversion_rate_with_japanese_column_names(): void
    {
        $mapping = [
            'channel' => ['column' => '媒体', 'status' => 'mapped', 'source' => 'ai'],
            'conversions' => ['column' => 'CV数', 'status' => 'mapped', 'source' => 'ai'],
            'clicks' => ['column' => 'クリック数', 'status' => 'mapped', 'source' => 'ai'],
        ];

        $result = $this->action()->execute('ad_performance', $mapping);

        $definition = $result['resolved'][0];
        $this->assertSame('channel', $definition['entity_type']);
        $this->assertSame('媒体', $definition['entity_field']);
        $this->assertSame('CV数', $definition['numerator_field']);
        $this->assertSame('クリック数', $definition['denominator_field']);
    }

    public function test_missing_entity_mapping_skips_the_metric_entirely(): void
    {
        $mapping = [
            'channel' => ['column' => null, 'status' => 'unmapped'],
            'conversions' => ['column' => 'conversions', 'status' => 'mapped'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped'],
        ];

        $result = $this->action()->execute('ad_performance', $mapping);

        $this->assertSame([], $result['resolved']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('conversion_rate', $result['skipped'][0]['metric_key']);
        $this->assertSame('entity_field_unmapped', $result['skipped'][0]['reason']);
    }

    public function test_entity_absent_from_mapping_entirely_also_skips_the_metric(): void
    {
        // "channel" not present as a key at all (e.g. a Template field the
        // AI never even attempted).
        $mapping = [
            'conversions' => ['column' => 'conversions', 'status' => 'mapped'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped'],
        ];

        $result = $this->action()->execute('ad_performance', $mapping);

        $this->assertSame([], $result['resolved']);
        $this->assertSame('entity_field_unmapped', $result['skipped'][0]['reason']);
    }

    public function test_missing_numerator_mapping_still_resolves_with_a_null_numerator_field(): void
    {
        $mapping = [
            'channel' => ['column' => 'channel', 'status' => 'mapped'],
            'conversions' => ['column' => null, 'status' => 'unmapped'],
            'clicks' => ['column' => 'clicks', 'status' => 'mapped'],
        ];

        $result = $this->action()->execute('ad_performance', $mapping);

        $this->assertSame([], $result['skipped']);
        $this->assertCount(1, $result['resolved']);
        $this->assertSame('channel', $result['resolved'][0]['entity_field']);
        $this->assertNull($result['resolved'][0]['numerator_field']);
        $this->assertSame('clicks', $result['resolved'][0]['denominator_field']);
    }

    public function test_missing_denominator_mapping_still_resolves_with_a_null_denominator_field(): void
    {
        $mapping = [
            'channel' => ['column' => 'channel', 'status' => 'mapped'],
            'conversions' => ['column' => 'conversions', 'status' => 'mapped'],
            'clicks' => ['column' => null, 'status' => 'unmapped'],
        ];

        $result = $this->action()->execute('ad_performance', $mapping);

        $this->assertCount(1, $result['resolved']);
        $this->assertSame('conversions', $result['resolved'][0]['numerator_field']);
        $this->assertNull($result['resolved'][0]['denominator_field']);
    }

    public function test_unknown_template_key_resolves_to_nothing(): void
    {
        $mapping = ['channel' => ['column' => 'channel', 'status' => 'mapped']];

        $result = $this->action()->execute('not_a_real_template', $mapping);

        $this->assertSame(['resolved' => [], 'skipped' => []], $result);
    }

    public function test_template_with_no_evaluation_metrics_config_resolves_to_nothing(): void
    {
        // sales_analysis has no entry in config/evaluation_metrics.php.
        $mapping = ['revenue' => ['column' => 'revenue', 'status' => 'mapped']];

        $result = $this->action()->execute('sales_analysis', $mapping);

        $this->assertSame(['resolved' => [], 'skipped' => []], $result);
    }

    public function test_sales_analysis_is_out_of_scope_for_phase_4a_v1(): void
    {
        $result = $this->action()->execute('sales_analysis', [
            'revenue' => ['column' => '売上金額', 'status' => 'mapped'],
            'quantity' => ['column' => '数量', 'status' => 'mapped'],
        ]);

        $this->assertSame([], $result['resolved']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_free_analysis_with_null_template_key_resolves_to_nothing(): void
    {
        $result = $this->action()->execute(null, ['channel' => ['column' => 'channel', 'status' => 'mapped']]);

        $this->assertSame(['resolved' => [], 'skipped' => []], $result);
    }

    public function test_null_effective_column_mapping_resolves_to_nothing(): void
    {
        $result = $this->action()->execute('ad_performance', null);

        $this->assertSame(['resolved' => [], 'skipped' => []], $result);
    }
}
