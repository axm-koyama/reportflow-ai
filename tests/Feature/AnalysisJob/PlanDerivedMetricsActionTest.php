<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\PlanDerivedMetricsAction;
use App\AI\AiAnalysisClient;
use InvalidArgumentException;
use Tests\TestCase;

class PlanDerivedMetricsActionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function aggregatedMetrics(): array
    {
        return [
            'dimensions' => [
                ['dimension' => 'channel', 'group_count' => 4, 'groups' => []],
                ['dimension' => 'region', 'group_count' => 3, 'groups' => []],
            ],
            'measures' => ['spend', 'revenue', 'conversions'],
        ];
    }

    /**
     * Run PlanDerivedMetricsAction with a stubbed AiAnalysisClient and
     * return the Planning Context it actually built (i.e. what would be
     * sent to the AI), without needing to repeat the mock/capture
     * boilerplate in every test.
     *
     * @param array<string, mixed>|null $aggregatedMetrics
     * @param array<string, mixed>|null $analysisTemplate
     * @param array<string, string> $columnMapping
     * @return array<string, mixed>
     */
    private function capturePlanningContext(
        string $prompt = '分析してください',
        ?array $aggregatedMetrics = null,
        ?array $analysisTemplate = null,
        array $columnMapping = [],
    ): array {
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR));

        app(PlanDerivedMetricsAction::class)->execute(
            $prompt,
            $aggregatedMetrics ?? $this->aggregatedMetrics(),
            $analysisTemplate,
            $columnMapping,
        );

        $this->assertIsArray($capturedContext);

        return $capturedContext;
    }

    /**
     * @return array{name: string, instruction: string, recommended_derived_metrics: list<array<string, mixed>>}
     */
    private function sampleAnalysisTemplate(): array
    {
        return [
            'name' => '広告パフォーマンス分析',
            'instruction' => '広告チャネルごとの成果を比較し、効率が良いチャネルと改善が必要なチャネルを特定してください。',
            'recommended_derived_metrics' => [
                ['name' => 'return_on_ad_spend', 'left_field' => 'revenue', 'right_field' => 'spend', 'operator_hint' => 'divide'],
            ],
        ];
    }

    public function test_it_sends_user_prompt_and_available_names_to_ai_analysis_client(): void
    {
        $context = $this->capturePlanningContext('Compare channel efficiency.');

        $this->assertSame('Compare channel efficiency.', $context['user_prompt']);
        $this->assertSame(['channel', 'region'], $context['available_dimensions']);
        $this->assertSame(['spend', 'revenue', 'conversions'], $context['available_measures']);
        $this->assertSame(['sum', 'count', 'avg'], $context['available_aggregations']);
        $this->assertIsString($context['system_instruction']);
        $this->assertNotSame('', $context['system_instruction']);
    }

    /**
     * Free analysis (no template): analysis_template is null and
     * column_mapping is empty by default — the exact same Planning
     * Context shape Phase 2 already sent.
     */
    public function test_free_analysis_sends_a_null_analysis_template_and_empty_column_mapping(): void
    {
        $context = $this->capturePlanningContext();

        $this->assertNull($context['analysis_template']);
        $this->assertSame([], $context['column_mapping']);
    }

    // --- Analysis Template integration -----------------------------------

    public function test_planning_context_includes_the_resolved_analysis_template_and_column_mapping(): void
    {
        $columnMapping = ['channel' => '媒体', 'spend' => '広告コスト', 'revenue' => '売上金額'];

        $context = $this->capturePlanningContext(
            analysisTemplate: $this->sampleAnalysisTemplate(),
            columnMapping: $columnMapping,
        );

        $this->assertSame($this->sampleAnalysisTemplate(), $context['analysis_template']);
        $this->assertSame($columnMapping, $context['column_mapping']);
    }

    public function test_system_instruction_treats_analysis_template_instruction_alongside_user_prompt(): void
    {
        $instruction = $this->capturePlanningContext(analysisTemplate: $this->sampleAnalysisTemplate())['system_instruction'];

        $this->assertStringContainsString('analysis_template is not null', $instruction);
        $this->assertStringContainsString('business analysis goal', $instruction);
        $this->assertStringContainsString('even when user_prompt is empty', $instruction);
    }

    public function test_system_instruction_explains_how_to_translate_recommended_derived_metrics_via_column_mapping(): void
    {
        $instruction = $this->capturePlanningContext(analysisTemplate: $this->sampleAnalysisTemplate())['system_instruction'];

        $this->assertStringContainsString('recommended_derived_metrics', $instruction);
        $this->assertStringContainsString('hints, not requirements', $instruction);
        $this->assertStringContainsString('translate', $instruction);
        $this->assertStringContainsString('column_mapping', $instruction);
        $this->assertStringContainsString('ignore that hint', $instruction);
    }

    // --- max_derived_metrics: config as Single Source of Truth -----------

    public function test_planning_context_includes_max_derived_metrics(): void
    {
        $context = $this->capturePlanningContext();

        $this->assertArrayHasKey('max_derived_metrics', $context);
        $this->assertIsInt($context['max_derived_metrics']);
    }

    public function test_max_derived_metrics_in_the_planning_context_comes_from_config(): void
    {
        $context = $this->capturePlanningContext();

        $this->assertSame(config('derived_metrics.max_derived_metrics'), $context['max_derived_metrics']);
        $this->assertSame(5, $context['max_derived_metrics']); // the shipped default
    }

    public function test_changing_the_config_value_changes_the_planning_context(): void
    {
        config(['derived_metrics.max_derived_metrics' => 3]);

        $context = $this->capturePlanningContext();

        $this->assertSame(3, $context['max_derived_metrics']);
    }

    /**
     * System Instruction must not hardcode "5" (or any other fixed number)
     * as the proposal limit — it must defer to the max_derived_metrics
     * value supplied in the Planning Context, which config drives.
     */
    public function test_system_instruction_has_no_hardcoded_proposal_count(): void
    {
        config(['derived_metrics.max_derived_metrics' => 3]);

        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringNotContainsString('at most 5', $instruction);
        $this->assertStringNotContainsString('at most 3', $instruction);
        $this->assertStringContainsString('max_derived_metrics', $instruction);
        $this->assertStringContainsString('no more than max_derived_metrics', $instruction);
    }

    public function test_system_instruction_discourages_exhaustive_unrelated_proposals(): void
    {
        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringContainsString('clearly useful for answering', $instruction);
        $this->assertStringContainsString('Do not exhaustively propose every possible combination', $instruction);
    }

    // --- Rule 4 / Rule 5 no longer contradict each other -------------------

    /**
     * Rule 4 must scope "never invent a ... value not in available_*" to
     * *referenced* operand/group_by values only — never phrased in a way
     * that could be read as applying to the derived metric's own "name"
     * (which Rule 5 explicitly requires the AI to invent).
     */
    public function test_rule_about_available_lists_is_scoped_to_operand_values_not_to_name(): void
    {
        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringContainsString("operand's \"metric\" must come only from", $instruction);
        $this->assertStringContainsString("operand's \"aggregation\" must come only from", $instruction);
        $this->assertStringContainsString('"group_by" must come only from', $instruction);
        $this->assertStringContainsString('referenced metric, aggregation, or group_by value', $instruction);

        // The old, ambiguous phrasing ("Never invent a name that is not in
        // these lists") must be gone: it read as if it also constrained
        // the derived metric's own "name", contradicting Rule 5.
        $this->assertStringNotContainsString('Never invent a name that is not in these lists', $instruction);
    }

    /**
     * System Instruction requires a unique, meaningful "name" per derived
     * metric — not a bare input measure name — since name is the
     * identifier CalculateDerivedMetricsAction and the final analysis AI
     * use to refer to each derived metric. It must also be explicit that
     * "name" is a newly-invented identifier, not looked up in available_*.
     */
    public function test_system_instruction_requires_unique_meaningful_names(): void
    {
        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringContainsString('unique, meaningful', $instruction);
        $this->assertStringContainsString('revenue_per_spend', $instruction);
        $this->assertStringContainsString('reusing a bare measure name', $instruction);
        $this->assertStringContainsString('every other one', $instruction);
        $this->assertStringContainsString('^[A-Za-z][A-Za-z0-9_]{0,63}$', $instruction);
        $this->assertStringContainsString('new identifier you create', $instruction);
    }

    // --- rate/percentage vs ratio/divide operator guidance ----------------

    public function test_system_instruction_says_to_use_percentage_for_rate_style_metrics(): void
    {
        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringContainsString('Use "percentage" when the derived metric represents a rate', $instruction);
        $this->assertStringContainsString('conversion_rate = conversions / clicks', $instruction);
        $this->assertStringContainsString('click_through_rate = clicks / impressions', $instruction);
    }

    public function test_system_instruction_says_to_use_divide_for_ratio_style_metrics(): void
    {
        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringContainsString('Use "divide" when the derived metric represents a ratio', $instruction);
        $this->assertStringContainsString('revenue_per_spend = revenue / spend', $instruction);
        $this->assertStringContainsString('revenue_per_conversion = revenue / conversions', $instruction);
    }

    public function test_system_instruction_explains_operator_semantics_not_just_by_name_pattern(): void
    {
        $instruction = $this->capturePlanningContext()['system_instruction'];

        $this->assertStringContainsString('not by name pattern alone', $instruction);
    }

    // --- Plan decoding -----------------------------------------------------

    public function test_it_returns_the_proposed_definitions_when_the_ai_proposes_some(): void
    {
        $plan = [
            'derived_metrics' => [
                [
                    'name' => 'revenue_per_spend',
                    'operator' => 'divide',
                    'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
                    'right' => ['metric' => 'spend', 'aggregation' => 'sum'],
                    'group_by' => 'channel',
                ],
            ],
        ];

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn(json_encode($plan, JSON_THROW_ON_ERROR));

        $result = app(PlanDerivedMetricsAction::class)->execute('分析してください', $this->aggregatedMetrics());

        $this->assertSame($plan['derived_metrics'], $result);
    }

    /**
     * An empty derived_metrics plan is a normal, valid outcome — not an error.
     */
    public function test_an_empty_derived_metrics_plan_is_handled_normally(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn(json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR));

        $result = app(PlanDerivedMetricsAction::class)->execute('分析してください', $this->aggregatedMetrics());

        $this->assertSame([], $result);
    }

    /**
     * When aggregated_metrics has no dimensions, there is nothing to plan
     * against: the AI is never called.
     */
    public function test_the_ai_is_never_called_when_there_are_no_dimensions(): void
    {
        $this->mock(AiAnalysisClient::class)->shouldNotReceive('planMetrics');

        $result = app(PlanDerivedMetricsAction::class)->execute('分析してください', [
            'dimensions' => [],
            'measures' => ['spend'],
        ]);

        $this->assertSame([], $result);
    }

    /**
     * When aggregated_metrics has no measures, there is nothing to plan
     * against: the AI is never called.
     */
    public function test_the_ai_is_never_called_when_there_are_no_measures(): void
    {
        $this->mock(AiAnalysisClient::class)->shouldNotReceive('planMetrics');

        $result = app(PlanDerivedMetricsAction::class)->execute('分析してください', [
            'dimensions' => [['dimension' => 'channel', 'group_count' => 2, 'groups' => []]],
            'measures' => [],
        ]);

        $this->assertSame([], $result);
    }

    public function test_it_throws_when_the_response_is_not_valid_json(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn('{not valid json');

        $this->expectException(InvalidArgumentException::class);

        app(PlanDerivedMetricsAction::class)->execute('分析してください', $this->aggregatedMetrics());
    }

    public function test_it_throws_when_the_response_has_no_derived_metrics_key(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn(json_encode(['something_else' => []], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);

        app(PlanDerivedMetricsAction::class)->execute('分析してください', $this->aggregatedMetrics());
    }

    /**
     * Non-object entries in the AI's derived_metrics list are dropped
     * defensively rather than crashing this action; CalculateDerivedMetricsAction
     * still validates every remaining field.
     */
    public function test_non_array_entries_in_the_plan_are_dropped_defensively(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('planMetrics')
            ->once()
            ->andReturn(json_encode(['derived_metrics' => ['not-an-object', 123, null]], JSON_THROW_ON_ERROR));

        $result = app(PlanDerivedMetricsAction::class)->execute('分析してください', $this->aggregatedMetrics());

        $this->assertSame([], $result);
    }
}
