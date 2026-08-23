<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\BuildAnalysisContextAction;
use ReflectionClass;
use Tests\TestCase;

class BuildAnalysisContextActionTest extends TestCase
{
    /**
     * A representative DataProfilingAction-shaped output, used as-is
     * across tests. BuildAnalysisContextAction must never recompute or
     * alter this.
     *
     * @return array<string, mixed>
     */
    private function sampleDataProfile(): array
    {
        return [
            'file' => [
                'name' => 'campaign.csv',
                'row_count' => 1200,
                'column_count' => 6,
            ],
            'columns' => [
                ['name' => 'channel', 'inferred_type' => 'string', 'non_null_count' => 1200, 'null_count' => 0, 'unique_count' => 4],
            ],
            'numeric_statistics' => [
                ['column' => 'spend', 'count' => 1200, 'min' => 100, 'max' => 5000, 'mean' => 890.5],
            ],
            'categorical_summaries' => [
                ['column' => 'channel', 'top_values' => [['value' => 'Paid Search', 'count' => 500]]],
            ],
            'sample_rows' => [
                ['channel' => 'Paid Search', 'spend' => '1200'],
            ],
        ];
    }

    /**
     * A representative MetricAggregationAction-shaped output, used as-is
     * across tests. BuildAnalysisContextAction must never recompute or
     * alter this.
     *
     * @return array<string, mixed>
     */
    private function sampleAggregatedMetrics(): array
    {
        return [
            'dimensions' => [
                [
                    'dimension' => 'channel',
                    'group_count' => 1,
                    'groups' => [
                        [
                            'value' => 'Paid Search',
                            'count' => 500,
                            'metrics' => [
                                'spend' => ['sum' => 445000, 'count' => 500, 'avg' => 890.0],
                            ],
                        ],
                    ],
                ],
            ],
            'measures' => ['spend'],
        ];
    }

    /**
     * A representative CalculateDerivedMetricsAction-shaped output, used
     * as-is across tests. BuildAnalysisContextAction must never recompute
     * or alter this.
     *
     * @return array<string, mixed>
     */
    private function sampleDerivedMetrics(): array
    {
        return [
            'metrics' => [
                [
                    'name' => 'ROAS',
                    'operator' => 'divide',
                    'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
                    'right' => ['metric' => 'spend', 'aggregation' => 'sum'],
                    'group_by' => 'channel',
                    'groups' => [
                        ['value' => 'Paid Search', 'result' => 7.94],
                    ],
                ],
            ],
            'rejected' => [],
        ];
    }

    /**
     * Build a context using the sample fixtures, for tests that don't
     * care about the specific input values.
     *
     * @return array<string, mixed>
     */
    private function buildContext(string $prompt = '分析してください'): array
    {
        return (new BuildAnalysisContextAction)->execute(
            $prompt,
            $this->sampleDataProfile(),
            $this->sampleAggregatedMetrics(),
            $this->sampleDerivedMetrics(),
        );
    }

    /**
     * 1. system_instruction がstringで返る
     */
    public function test_system_instruction_is_a_string(): void
    {
        $context = $this->buildContext();

        $this->assertIsString($context['system_instruction']);
        $this->assertNotSame('', $context['system_instruction']);
    }

    /**
     * 2. user_prompt が入力値と完全一致する（trim / rewrite等をしない）
     */
    public function test_user_prompt_matches_the_input_exactly(): void
    {
        $prompt = "  各広告チャネルの成果を比較し、\n効率が悪いチャネルを分析してください。  ";

        $context = $this->buildContext($prompt);

        $this->assertSame($prompt, $context['user_prompt']);
    }

    /**
     * 3. data_profile が入力arrayと完全一致する（再集計/削除等をしない）
     */
    public function test_data_profile_matches_the_input_array_exactly(): void
    {
        $dataProfile = $this->sampleDataProfile();

        $context = (new BuildAnalysisContextAction)->execute(
            '分析してください',
            $dataProfile,
            $this->sampleAggregatedMetrics(),
            $this->sampleDerivedMetrics(),
        );

        $this->assertSame($dataProfile, $context['data_profile']);
    }

    /**
     * 3b. aggregated_metrics が入力arrayと完全一致する（再計算等をしない）
     */
    public function test_aggregated_metrics_matches_the_input_array_exactly(): void
    {
        $aggregatedMetrics = $this->sampleAggregatedMetrics();

        $context = (new BuildAnalysisContextAction)->execute(
            '分析してください',
            $this->sampleDataProfile(),
            $aggregatedMetrics,
            $this->sampleDerivedMetrics(),
        );

        $this->assertSame($aggregatedMetrics, $context['aggregated_metrics']);
    }

    /**
     * 3c. derived_metrics が入力arrayと完全一致する（再計算等をしない）
     */
    public function test_derived_metrics_matches_the_input_array_exactly(): void
    {
        $derivedMetrics = $this->sampleDerivedMetrics();

        $context = (new BuildAnalysisContextAction)->execute(
            '分析してください',
            $this->sampleDataProfile(),
            $this->sampleAggregatedMetrics(),
            $derivedMetrics,
        );

        $this->assertSame($derivedMetrics, $context['derived_metrics']);
    }

    /**
     * 4. output_schema が存在する
     */
    public function test_output_schema_is_present(): void
    {
        $context = $this->buildContext();

        $this->assertArrayHasKey('output_schema', $context);
        $this->assertIsArray($context['output_schema']);
        $this->assertNotEmpty($context['output_schema']);
    }

    /**
     * 5. summary が output_schema に含まれる
     */
    public function test_output_schema_includes_summary(): void
    {
        $schema = $this->outputSchema();

        $this->assertArrayHasKey('summary', $schema);
        $this->assertSame('string', $schema['summary']['type']);
        $this->assertTrue($schema['summary']['required']);
    }

    /**
     * 6. highlights が output_schema に含まれる
     */
    public function test_output_schema_includes_highlights(): void
    {
        $schema = $this->outputSchema();

        $this->assertArrayHasKey('highlights', $schema);
        $this->assertSame('array', $schema['highlights']['type']);
        $this->assertSame('string', $schema['highlights']['items']);
    }

    /**
     * 7. metrics が output_schema に含まれる
     */
    public function test_output_schema_includes_metrics(): void
    {
        $schema = $this->outputSchema();

        $this->assertArrayHasKey('metrics', $schema);
        $this->assertSame(
            ['label', 'value', 'unit', 'change'],
            array_keys($schema['metrics']['items']),
        );
    }

    /**
     * 8. tables が output_schema に含まれる
     */
    public function test_output_schema_includes_tables(): void
    {
        $schema = $this->outputSchema();

        $this->assertArrayHasKey('tables', $schema);
        $this->assertSame(
            ['title', 'columns', 'rows'],
            array_keys($schema['tables']['items']),
        );
    }

    /**
     * 9. insights が output_schema に含まれる
     */
    public function test_output_schema_includes_insights(): void
    {
        $schema = $this->outputSchema();

        $this->assertArrayHasKey('insights', $schema);
        $this->assertSame(
            ['title', 'description', 'evidence'],
            array_keys($schema['insights']['items']),
        );
    }

    /**
     * 10. recommendations が output_schema に含まれる（title/description/priority）
     */
    public function test_output_schema_includes_recommendations(): void
    {
        $schema = $this->outputSchema();

        $this->assertArrayHasKey('recommendations', $schema);
        $this->assertSame(
            ['title', 'description', 'priority'],
            array_keys($schema['recommendations']['items']),
        );
    }

    /**
     * 11. recommendation priority が high / medium / low / null を許容する設計になっている
     */
    public function test_recommendation_priority_allows_high_medium_low_or_null(): void
    {
        $schema = $this->outputSchema();

        $this->assertSame('high|medium|low|null', $schema['recommendations']['items']['priority']);
    }

    /**
     * 12. Action が DataFile / AnalysisJob / AI Provider へ依存していないことを
     *     コード構造上確認する（constructorに依存なし、禁止クラスへの参照なし）
     */
    public function test_action_has_no_dependency_on_data_file_analysis_job_or_ai_provider(): void
    {
        $reflection = new ReflectionClass(BuildAnalysisContextAction::class);

        $constructor = $reflection->getConstructor();
        $this->assertTrue(
            $constructor === null || $constructor->getNumberOfParameters() === 0,
            'BuildAnalysisContextAction must not declare constructor dependencies.',
        );

        $source = file_get_contents($reflection->getFileName());
        $this->assertIsString($source);

        foreach ([
            'App\\Models\\DataFile',
            'App\\Models\\AnalysisJob',
            'App\\AI\\AiAnalysisClient',
            'Illuminate\\Support\\Facades\\Storage',
            'Illuminate\\Support\\Facades\\Http',
            'Illuminate\\Http\\Client',
        ] as $forbiddenReference) {
            $this->assertStringNotContainsString(
                $forbiddenReference,
                $source,
                "BuildAnalysisContextAction must not reference {$forbiddenReference}.",
            );
        }
    }

    /**
     * System Instruction: 重要ルールが含まれることを確認する
     * （全文一致ではなく、キーとなるルール文言の存在確認に限定する）
     */
    public function test_system_instruction_includes_the_key_safety_rules(): void
    {
        $context = $this->buildContext();
        $instruction = $context['system_instruction'];

        // Do not invent unsupported facts.
        $this->assertStringContainsString('Do not invent facts', $instruction);

        // Insufficient context must be stated explicitly.
        $this->assertStringContainsString('insufficient', $instruction);

        // Only the required structured output should be returned.
        $this->assertStringContainsString('structured output', $instruction);

        // Observation vs interpretation distinction.
        $this->assertStringContainsString('distinguish observed facts from interpretations', $instruction);

        // aggregated_metrics is exact, computed ground truth.
        $this->assertStringContainsString('ground truth', $instruction);

        // sample_rows must not be used to recompute figures.
        $this->assertStringContainsString('Do not recompute, re-derive, or re-estimate', $instruction);

        // aggregated_metrics must be preferred over sample_rows for numeric answers.
        $this->assertStringContainsString('use that number instead', $instruction);
    }

    /**
     * System Instruction: derived_metrics固有のルールが含まれることを確認する
     */
    public function test_system_instruction_includes_the_derived_metrics_rules(): void
    {
        $context = $this->buildContext();
        $instruction = $context['system_instruction'];

        // derived_metrics is exact, computed ground truth (same as aggregated_metrics).
        $this->assertStringContainsString(
            'derived_metrics (e.g. ratios such as ROAS) are exact',
            $instruction,
        );

        // Do not recompute a derived metric.
        $this->assertStringContainsString('Do not recompute a derived metric yourself', $instruction);

        // derived_metrics is preferred over self-computed equivalents.
        $this->assertStringContainsString('prefer it over any equivalent figure', $instruction);

        // Never derive a ratio/percentage from sample_rows.
        $this->assertStringContainsString('Never derive a ratio, percentage', $instruction);

        // A null result means "could not be computed", not "assume 0".
        $this->assertStringContainsString('could not be computed', $instruction);
    }

    /**
     * Output contract: 想定される8つのtop-level keyのみを返す
     */
    public function test_execute_returns_exactly_the_expected_top_level_keys(): void
    {
        $context = $this->buildContext();

        $this->assertSame(
            ['system_instruction', 'user_prompt', 'data_profile', 'aggregated_metrics', 'derived_metrics', 'analysis_template', 'column_mapping', 'output_schema'],
            array_keys($context),
        );
    }

    // --- Analysis Template integration -----------------------------------

    /**
     * Free Analysis(template未使用): analysis_templateはnull、
     * column_mappingは[]がデフォルト。
     */
    public function test_free_analysis_defaults_analysis_template_to_null_and_column_mapping_to_empty(): void
    {
        $context = $this->buildContext();

        $this->assertNull($context['analysis_template']);
        $this->assertSame([], $context['column_mapping']);
    }

    public function test_analysis_template_matches_the_input_array_exactly(): void
    {
        $analysisTemplate = [
            'name' => '広告パフォーマンス分析',
            'instruction' => '広告チャネルごとの成果を比較してください。',
            'recommended_derived_metrics' => [
                ['name' => 'return_on_ad_spend', 'left_field' => 'revenue', 'right_field' => 'spend', 'operator_hint' => 'divide'],
            ],
        ];

        $context = (new BuildAnalysisContextAction)->execute(
            '分析してください',
            $this->sampleDataProfile(),
            $this->sampleAggregatedMetrics(),
            $this->sampleDerivedMetrics(),
            $analysisTemplate,
            ['channel' => '媒体'],
        );

        $this->assertSame($analysisTemplate, $context['analysis_template']);
    }

    public function test_column_mapping_matches_the_input_array_exactly(): void
    {
        $columnMapping = ['channel' => '媒体', 'spend' => '広告コスト', 'revenue' => '売上金額'];

        $context = (new BuildAnalysisContextAction)->execute(
            '分析してください',
            $this->sampleDataProfile(),
            $this->sampleAggregatedMetrics(),
            $this->sampleDerivedMetrics(),
            null,
            $columnMapping,
        );

        $this->assertSame($columnMapping, $context['column_mapping']);
    }

    public function test_system_instruction_treats_analysis_template_as_the_primary_objective(): void
    {
        $instruction = $this->buildContext()['system_instruction'];

        $this->assertStringContainsString('analysis_template is not null', $instruction);
        $this->assertStringContainsString('primary analysis objective', $instruction);
    }

    public function test_system_instruction_treats_column_mapping_as_a_validated_fact(): void
    {
        $instruction = $this->buildContext()['system_instruction'];

        $this->assertStringContainsString('is a Fact', $instruction);
        $this->assertStringContainsString('already validated by the application', $instruction);
        $this->assertStringContainsString('prefer a business-friendly term', $instruction);
    }

    /**
     * @return array<string, mixed>
     */
    private function outputSchema(): array
    {
        return $this->buildContext()['output_schema'];
    }
}
