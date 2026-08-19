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
     * 1. system_instruction がstringで返る
     */
    public function test_system_instruction_is_a_string(): void
    {
        $context = (new BuildAnalysisContextAction)->execute('分析してください', $this->sampleDataProfile(), $this->sampleAggregatedMetrics());

        $this->assertIsString($context['system_instruction']);
        $this->assertNotSame('', $context['system_instruction']);
    }

    /**
     * 2. user_prompt が入力値と完全一致する（trim / rewrite等をしない）
     */
    public function test_user_prompt_matches_the_input_exactly(): void
    {
        $prompt = "  各広告チャネルの成果を比較し、\n効率が悪いチャネルを分析してください。  ";

        $context = (new BuildAnalysisContextAction)->execute($prompt, $this->sampleDataProfile(), $this->sampleAggregatedMetrics());

        $this->assertSame($prompt, $context['user_prompt']);
    }

    /**
     * 3. data_profile が入力arrayと完全一致する（再集計/削除等をしない）
     */
    public function test_data_profile_matches_the_input_array_exactly(): void
    {
        $dataProfile = $this->sampleDataProfile();

        $context = (new BuildAnalysisContextAction)->execute('分析してください', $dataProfile, $this->sampleAggregatedMetrics());

        $this->assertSame($dataProfile, $context['data_profile']);
    }

    /**
     * 3b. aggregated_metrics が入力arrayと完全一致する（再計算等をしない）
     */
    public function test_aggregated_metrics_matches_the_input_array_exactly(): void
    {
        $aggregatedMetrics = $this->sampleAggregatedMetrics();

        $context = (new BuildAnalysisContextAction)->execute('分析してください', $this->sampleDataProfile(), $aggregatedMetrics);

        $this->assertSame($aggregatedMetrics, $context['aggregated_metrics']);
    }

    /**
     * 4. output_schema が存在する
     */
    public function test_output_schema_is_present(): void
    {
        $context = (new BuildAnalysisContextAction)->execute('分析してください', $this->sampleDataProfile(), $this->sampleAggregatedMetrics());

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
        $context = (new BuildAnalysisContextAction)->execute('分析してください', $this->sampleDataProfile(), $this->sampleAggregatedMetrics());
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
     * Output contract: 想定される5つのtop-level keyのみを返す
     */
    public function test_execute_returns_exactly_the_expected_top_level_keys(): void
    {
        $context = (new BuildAnalysisContextAction)->execute('分析してください', $this->sampleDataProfile(), $this->sampleAggregatedMetrics());

        $this->assertSame(
            ['system_instruction', 'user_prompt', 'data_profile', 'aggregated_metrics', 'output_schema'],
            array_keys($context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function outputSchema(): array
    {
        $context = (new BuildAnalysisContextAction)->execute('分析してください', $this->sampleDataProfile(), $this->sampleAggregatedMetrics());

        return $context['output_schema'];
    }
}
