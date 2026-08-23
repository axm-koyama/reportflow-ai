<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\AiAnalysisClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AiAnalysisClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key' => 'test-openai-key',
            'services.openai.model' => 'gpt-5.4-mini',
            'services.openai.timeout' => 60,
            'services.openai.responses_url' => 'https://api.openai.com/v1/responses',
        ]);
    }

    /**
     * The Responses API URL is read from config (services.openai.responses_url),
     * not hardcoded, so overriding it actually changes where the client posts.
     */
    public function test_it_posts_to_the_configured_responses_url(): void
    {
        config(['services.openai.responses_url' => 'https://openai.example.test/v1/responses']);

        Http::fake([
            'https://openai.example.test/v1/responses' => Http::response($this->completedResponse(
                json_encode($this->structuredResult(), JSON_THROW_ON_ERROR),
            )),
        ]);

        (new AiAnalysisClient)->analyze($this->context());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openai.example.test/v1/responses');
    }

    public function test_it_throws_before_sending_a_request_when_the_api_key_is_not_configured(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        try {
            (new AiAnalysisClient)->analyze($this->context());

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI API key is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_sends_the_openai_responses_request_and_returns_structured_output(): void
    {
        $payload = null;
        $structuredOutput = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $structuredOutput) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($structuredOutput));
        });

        $result = (new AiAnalysisClient)->analyze($this->context());

        $this->assertSame($structuredOutput, $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key');
        });

        $this->assertIsArray($payload);
        $this->assertSame('gpt-5.4-mini', $payload['model']);
        $this->assertFalse($payload['store']);
        $this->assertSame($this->context()['system_instruction'], $payload['instructions']);
        $this->assertArrayNotHasKey('data_file', $payload);
        $this->assertArrayNotHasKey('stored_path', $payload);

        $input = json_decode(
            $payload['input'][0]['content'][0]['text'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame([
            'user_prompt' => $this->context()['user_prompt'],
            'data_profile' => $this->context()['data_profile'],
            'aggregated_metrics' => $this->context()['aggregated_metrics'],
            'derived_metrics' => $this->context()['derived_metrics'],
            'analysis_template' => $this->context()['analysis_template'],
            'column_mapping' => $this->context()['column_mapping'],
        ], $input);

        $format = $payload['text']['format'];
        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('reportflow_analysis_result', $format['name']);
        $this->assertTrue($format['strict']);

        $schema = $format['schema'];
        $this->assertSame([
            'summary',
            'highlights',
            'metrics',
            'tables',
            'insights',
            'recommendations',
        ], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['metrics']['items']['additionalProperties']);
        $this->assertFalse($schema['properties']['tables']['items']['additionalProperties']);
        $this->assertFalse($schema['properties']['insights']['items']['additionalProperties']);
        $this->assertFalse($schema['properties']['recommendations']['items']['additionalProperties']);
        $this->assertSame(['string', 'null'], $schema['properties']['metrics']['items']['properties']['unit']['type']);
        $this->assertSame(['string', 'null'], $schema['properties']['metrics']['items']['properties']['change']['type']);
        $this->assertSame(['string', 'null'], $schema['properties']['insights']['items']['properties']['evidence']['type']);
        $this->assertSame(['string', 'null'], $schema['properties']['recommendations']['items']['properties']['priority']['type']);
        $this->assertSame(
            ['high', 'medium', 'low', null],
            $schema['properties']['recommendations']['items']['properties']['priority']['enum'],
        );
    }

    #[DataProvider('unsuccessfulResponseStatusProvider')]
    public function test_it_throws_for_unsuccessful_http_responses(int $status): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([], $status),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("OpenAI API request failed with HTTP status {$status}.");

        (new AiAnalysisClient)->analyze($this->context());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unsuccessfulResponseStatusProvider(): array
    {
        return [
            'unauthorized' => [401],
            'rate limited' => [429],
            'server error' => [500],
        ];
    }

    public function test_it_throws_for_connection_errors_without_retrying(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::failedConnection(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API request failed due to a connection error.');

        try {
            (new AiAnalysisClient)->analyze($this->context());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_it_throws_when_the_openai_response_is_incomplete(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI response was incomplete.');

        (new AiAnalysisClient)->analyze($this->context());
    }

    public function test_it_includes_status_and_error_code_when_response_failed_with_an_error_code(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'failed',
                'error' => [
                    'code' => 'server_error',
                    'message' => 'Internal detail that must not leak into the exception message.',
                ],
            ]),
        ]);

        try {
            (new AiAnalysisClient)->analyze($this->context());

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'OpenAI response did not complete with status failed. Error code: server_error.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('Internal detail', $exception->getMessage());
        }
    }

    public function test_it_includes_only_status_when_response_failed_without_an_error_code(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'failed',
            ]),
        ]);

        try {
            (new AiAnalysisClient)->analyze($this->context());

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'OpenAI response did not complete with status failed.',
                $exception->getMessage(),
            );
        }
    }

    public function test_it_extracts_output_text_from_the_message_item_when_a_reasoning_item_is_also_present(): void
    {
        $structuredOutput = json_encode($this->structuredResult(), JSON_THROW_ON_ERROR);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [
                    ['type' => 'reasoning', 'summary' => []],
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => $structuredOutput],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new AiAnalysisClient)->analyze($this->context());

        $this->assertSame($structuredOutput, $result);
    }

    public function test_it_throws_when_an_output_item_is_not_an_array(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => ['not-an-array-item'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI response had an unexpected shape.');

        (new AiAnalysisClient)->analyze($this->context());
    }

    public function test_it_wraps_ai_context_json_encode_failures_in_a_runtime_exception(): void
    {
        $context = $this->context();
        // Invalid UTF-8 byte sequence: json_encode(..., JSON_THROW_ON_ERROR)
        // cannot encode this and throws \JsonException.
        $context['data_profile']['file']['name'] = "\xB1\x31";

        Http::fake();

        try {
            (new AiAnalysisClient)->analyze($context);

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failed to encode AI Context as JSON.', $exception->getMessage());
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }

        Http::assertNothingSent();
    }

    public function test_it_throws_when_openai_refuses_the_request(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'refusal',
                        'refusal' => 'Sensitive request.',
                    ]],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI refused the analysis request.');

        (new AiAnalysisClient)->analyze($this->context());
    }

    public function test_it_throws_when_completed_response_has_no_structured_output(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI response did not contain structured output.');

        (new AiAnalysisClient)->analyze($this->context());
    }

    public function test_it_throws_when_the_response_shape_is_unexpected(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => 'invalid',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI response had an unexpected shape.');

        (new AiAnalysisClient)->analyze($this->context());
    }

    /**
     * planMetrics(): request shape, and — critically — that aggregated_metrics'
     * actual numeric values are never sent to the Metric Planning call.
     */
    public function test_plan_metrics_sends_the_planning_request_and_returns_structured_output(): void
    {
        $payload = null;
        $planResponse = json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $planResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($planResponse));
        });

        $result = (new AiAnalysisClient)->planMetrics($this->planningContext());

        $this->assertSame($planResponse, $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key');
        });

        $this->assertIsArray($payload);
        $this->assertSame('gpt-5.4-mini', $payload['model']);
        $this->assertFalse($payload['store']);
        $this->assertSame($this->planningContext()['system_instruction'], $payload['instructions']);

        $input = json_decode(
            $payload['input'][0]['content'][0]['text'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // user_prompt is included.
        $this->assertSame($this->planningContext()['user_prompt'], $input['user_prompt']);

        // Available dimension / measure / aggregation *names* are included...
        $this->assertSame($this->planningContext()['available_dimensions'], $input['available_dimensions']);
        $this->assertSame($this->planningContext()['available_measures'], $input['available_measures']);
        $this->assertSame($this->planningContext()['available_aggregations'], $input['available_aggregations']);

        // ...and so is max_derived_metrics, config-driven and forwarded
        // verbatim — this is the only channel through which the AI learns
        // the actual limit; the System Instruction never hardcodes it.
        $this->assertSame($this->planningContext()['max_derived_metrics'], $input['max_derived_metrics']);

        // analysis_template / column_mapping are included (structured, not
        // embedded into user_prompt — see docs/product/ANALYSIS_TEMPLATE_MODULE.md).
        $this->assertSame($this->planningContext()['analysis_template'], $input['analysis_template']);
        $this->assertSame($this->planningContext()['column_mapping'], $input['column_mapping']);

        // ...but no aggregated_metrics numeric values, data_profile, or
        // derived_metrics are present anywhere in the planning payload.
        $this->assertArrayNotHasKey('aggregated_metrics', $input);
        $this->assertArrayNotHasKey('data_profile', $input);
        $this->assertArrayNotHasKey('derived_metrics', $input);

        // Only the 7 expected top-level keys are sent — no numeric
        // aggregated_metrics values (sums/counts/averages) sneak in
        // through an unexpected key.
        $this->assertSame(
            ['user_prompt', 'available_dimensions', 'available_measures', 'available_aggregations', 'max_derived_metrics', 'analysis_template', 'column_mapping'],
            array_keys($input),
        );

        $format = $payload['text']['format'];
        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('reportflow_derived_metrics_plan', $format['name']);
        $this->assertTrue($format['strict']);

        $schema = $format['schema'];
        $this->assertSame(['derived_metrics'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);

        // No hardcoded "maxItems" on the derived_metrics array: the limit
        // is enforced by config-driven Planning Context guidance plus
        // CalculateDerivedMetricsAction's application-side validation, not
        // by the Structured Output schema itself (see
        // docs/product/DERIVED_METRICS.md "max_derived_metrics").
        $this->assertArrayNotHasKey('maxItems', $schema['properties']['derived_metrics']);

        $item = $schema['properties']['derived_metrics']['items'];
        $this->assertSame(['name', 'operator', 'left', 'right', 'group_by'], $item['required']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertSame(
            ['divide', 'multiply', 'add', 'subtract', 'percentage'],
            $item['properties']['operator']['enum'],
        );
        $this->assertSame('string', $item['properties']['group_by']['type']);

        $operand = $item['properties']['left'];
        $this->assertSame(['metric', 'aggregation'], $operand['required']);
        $this->assertFalse($operand['additionalProperties']);
        $this->assertSame(['sum', 'count', 'avg'], $operand['properties']['aggregation']['enum']);
        $this->assertSame($operand, $item['properties']['right']);
    }

    public function test_plan_metrics_returns_a_non_empty_derived_metrics_plan_when_the_ai_proposes_one(): void
    {
        $plan = [
            'derived_metrics' => [
                [
                    'name' => 'ROAS',
                    'operator' => 'divide',
                    'left' => ['metric' => 'revenue', 'aggregation' => 'sum'],
                    'right' => ['metric' => 'spend', 'aggregation' => 'sum'],
                    'group_by' => 'channel',
                ],
            ],
        ];
        $planResponse = json_encode($plan, JSON_THROW_ON_ERROR);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->completedResponse($planResponse)),
        ]);

        $result = (new AiAnalysisClient)->planMetrics($this->planningContext());

        $this->assertSame($planResponse, $result);
    }

    public function test_plan_metrics_throws_when_the_api_key_is_not_configured(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        try {
            (new AiAnalysisClient)->planMetrics($this->planningContext());

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI API key is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_plan_metrics_throws_for_unsuccessful_http_responses(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API request failed with HTTP status 500.');

        (new AiAnalysisClient)->planMetrics($this->planningContext());
    }

    public function test_plan_metrics_throws_when_openai_refuses_the_request(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'refusal',
                        'refusal' => 'Sensitive request.',
                    ]],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI refused the metric planning request.');

        (new AiAnalysisClient)->planMetrics($this->planningContext());
    }

    /**
     * mapColumns(): request shape, and that only type-filtered column
     * candidates (never the full Data Profile) are sent.
     */
    public function test_map_columns_sends_the_mapping_request_and_returns_structured_output(): void
    {
        $payload = null;
        $mapResponse = json_encode(['mappings' => []], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $mapResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($mapResponse));
        });

        $result = (new AiAnalysisClient)->mapColumns($this->mappingContext());

        $this->assertSame($mapResponse, $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key');
        });

        $this->assertIsArray($payload);
        $this->assertSame('gpt-5.4-mini', $payload['model']);
        $this->assertFalse($payload['store']);
        $this->assertSame($this->mappingContext()['system_instruction'], $payload['instructions']);

        $input = json_decode(
            $payload['input'][0]['content'][0]['text'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame($this->mappingContext()['user_prompt'], $input['user_prompt']);
        $this->assertSame($this->mappingContext()['template_fields'], $input['template_fields']);
        $this->assertSame($this->mappingContext()['column_candidates'], $input['column_candidates']);

        // Only the 3 expected top-level keys are sent.
        $this->assertSame(['user_prompt', 'template_fields', 'column_candidates'], array_keys($input));

        $format = $payload['text']['format'];
        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('reportflow_column_mapping', $format['name']);
        $this->assertTrue($format['strict']);

        $schema = $format['schema'];
        $this->assertSame(['mappings'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);

        $item = $schema['properties']['mappings']['items'];
        $this->assertSame(['field', 'column', 'confidence'], $item['required']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertSame(['string', 'null'], $item['properties']['column']['type']);
        $this->assertSame(['high', 'low', 'unmapped'], $item['properties']['confidence']['enum']);
    }

    public function test_map_columns_returns_proposed_mappings_when_the_ai_proposes_some(): void
    {
        $plan = [
            'mappings' => [
                ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
                ['field' => 'clicks', 'column' => null, 'confidence' => 'unmapped'],
            ],
        ];
        $mapResponse = json_encode($plan, JSON_THROW_ON_ERROR);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->completedResponse($mapResponse)),
        ]);

        $result = (new AiAnalysisClient)->mapColumns($this->mappingContext());

        $this->assertSame($mapResponse, $result);
    }

    public function test_map_columns_throws_when_the_api_key_is_not_configured(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        try {
            (new AiAnalysisClient)->mapColumns($this->mappingContext());

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI API key is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_map_columns_throws_for_unsuccessful_http_responses(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API request failed with HTTP status 500.');

        (new AiAnalysisClient)->mapColumns($this->mappingContext());
    }

    public function test_map_columns_throws_when_openai_refuses_the_request(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'refusal',
                        'refusal' => 'Sensitive request.',
                    ]],
                ]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI refused the column mapping request.');

        (new AiAnalysisClient)->mapColumns($this->mappingContext());
    }

    /**
     * @return array<string, mixed>
     */
    private function mappingContext(): array
    {
        return [
            'system_instruction' => 'Map each semantic field to the best-fitting CSV column.',
            'user_prompt' => '',
            'template_fields' => [
                ['field' => 'channel', 'kind' => 'dimension', 'label' => 'チャネル'],
                ['field' => 'spend', 'kind' => 'measure', 'label' => '広告費'],
            ],
            'column_candidates' => [
                'channel' => [
                    ['column' => '媒体', 'inferred_type' => 'string', 'sample_values' => ['Email', 'Paid Search']],
                ],
                'spend' => [
                    ['column' => '広告コスト', 'inferred_type' => 'integer', 'sample_values' => ['120000', '150000']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planningContext(): array
    {
        return [
            'system_instruction' => 'Propose only derived metrics relevant to the user request.',
            'user_prompt' => 'Compare channel efficiency.',
            'available_dimensions' => ['channel', 'region'],
            'available_measures' => ['spend', 'revenue', 'conversions'],
            'available_aggregations' => ['sum', 'count', 'avg'],
            'max_derived_metrics' => 5,
            'analysis_template' => null,
            'column_mapping' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'system_instruction' => 'Use only supplied data and return structured output.',
            'user_prompt' => 'Analyze regional sales.',
            'data_profile' => [
                'file' => [
                    'name' => 'sales.csv',
                    'row_count' => 3,
                    'column_count' => 2,
                ],
                'columns' => [
                    ['name' => 'region'],
                    ['name' => 'sales'],
                ],
            ],
            'aggregated_metrics' => [
                'dimensions' => [],
                'measures' => [],
            ],
            'derived_metrics' => [
                'metrics' => [],
                'rejected' => [],
            ],
            'analysis_template' => null,
            'column_mapping' => [],
            'output_schema' => [
                'summary' => 'string',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completedResponse(string $structuredOutput): array
    {
        return [
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $structuredOutput,
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredResult(): array
    {
        return [
            'summary' => 'Sales are concentrated in Tokyo.',
            'highlights' => [],
            'metrics' => [],
            'tables' => [],
            'insights' => [],
            'recommendations' => [],
        ];
    }
}
