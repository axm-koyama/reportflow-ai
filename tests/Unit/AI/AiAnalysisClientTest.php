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

        // "group_by" is additionally constrained to an enum built from
        // this exact request's available_dimensions — the API itself
        // cannot return a group_by value that isn't one of these two
        // exact strings (see derivedMetricsPlanSchema()'s docblock for
        // the real-API incident this defends against).
        $this->assertSame($this->planningContext()['available_dimensions'], $item['properties']['group_by']['enum']);

        $operand = $item['properties']['left'];
        $this->assertSame(['metric', 'aggregation'], $operand['required']);
        $this->assertFalse($operand['additionalProperties']);
        $this->assertSame(['sum', 'count', 'avg'], $operand['properties']['aggregation']['enum']);
        $this->assertSame($operand, $item['properties']['right']);
    }

    /**
     * The group_by enum is built fresh per request from this exact call's
     * available_dimensions — not hardcoded, and preserves non-ASCII real
     * column names (e.g. Japanese "分類") verbatim, character-for-character,
     * rather than any transliterated or label-like substitute.
     */
    public function test_plan_metrics_constrains_group_by_to_this_requests_real_japanese_dimension_names(): void
    {
        $payload = null;
        $planResponse = json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $planResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($planResponse));
        });

        $context = $this->planningContext();
        $context['available_dimensions'] = ['分類', '地域'];

        (new AiAnalysisClient)->planMetrics($context);

        $groupByEnum = $payload['text']['format']['schema']['properties']['derived_metrics']['items']['properties']['group_by']['enum'];

        $this->assertSame(['分類', '地域'], $groupByEnum);
        // Never a Template field label / English synonym instead of the
        // real column name.
        $this->assertNotContains('category', $groupByEnum);
        $this->assertNotContains('カテゴリ', $groupByEnum);
    }

    /**
     * "group_by" has never been nullable — CalculateDerivedMetricsAction
     * rejects "null"/missing group_by as "missing_group_by" (Phase 2;
     * see docs/product/DERIVED_METRICS.md §10 "group_by必須(nullを
     * 許可しない)"), and the Structured Output schema's "group_by" has
     * always been a plain {"type": "string"} — never
     * {"type": ["string", "null"]}. The dynamic "enum" this schema now
     * adds is a *further* restriction on top of an already-non-nullable
     * string, never a relaxation: adding an enum of exact allowed string
     * values cannot itself introduce null as a valid value, and this test
     * locks that invariant in explicitly (a regression here would mean a
     * future edit accidentally widened "type" to allow null while adding
     * the enum).
     */
    public function test_plan_metrics_group_by_schema_never_allows_null_when_available_dimensions_is_non_empty(): void
    {
        $payload = null;
        $planResponse = json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $planResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($planResponse));
        });

        (new AiAnalysisClient)->planMetrics($this->planningContext());

        $groupBySchema = $payload['text']['format']['schema']['properties']['derived_metrics']['items']['properties']['group_by'];

        $this->assertSame('string', $groupBySchema['type']);
        $this->assertNotContains(null, $groupBySchema['enum']);
    }

    /**
     * When available_dimensions is empty, derivedMetricsPlanSchema()
     * defensively omits "enum" entirely (an empty enum would make the
     * field impossible to satisfy) rather than falling back to allowing
     * null. "group_by" remains a required, non-nullable string exactly as
     * it always was before this schema had an enum at all. In the real
     * pipeline this case never actually occurs — PlanDerivedMetricsAction
     * never calls planMetrics() at all when aggregated_metrics has no
     * dimensions — but planMetrics() is a public method, so this locks in
     * safe behavior regardless of caller.
     */
    public function test_plan_metrics_group_by_schema_has_no_enum_but_still_disallows_null_when_available_dimensions_is_empty(): void
    {
        $payload = null;
        $planResponse = json_encode(['derived_metrics' => []], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $planResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($planResponse));
        });

        $context = $this->planningContext();
        $context['available_dimensions'] = [];

        (new AiAnalysisClient)->planMetrics($context);

        $groupBySchema = $payload['text']['format']['schema']['properties']['derived_metrics']['items']['properties']['group_by'];

        $this->assertSame('string', $groupBySchema['type']);
        $this->assertArrayNotHasKey('enum', $groupBySchema);
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
     * diagnose(): request shape, and that only trigger_fact /
     * supporting_facts / allowed_categories are sent — never user_prompt,
     * a Data Profile, or sample_rows. See
     * docs/product/DIAGNOSIS_ENGINE.md "raw sample_rows禁止" /
     * "user_prompt禁止".
     */
    public function test_diagnose_sends_the_diagnosis_request_and_returns_structured_output(): void
    {
        $payload = null;
        $diagnoseResponse = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.4,
                'rationale_summary' => 'The evidence does not distinguish a specific cause.',
                'evidence_refs' => ['trigger:evaluation_fact:123'],
                'missing_evidence' => ['landing-page-level conversion rate'],
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $diagnoseResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($diagnoseResponse));
        });

        $result = (new AiAnalysisClient)->diagnose($this->diagnosisContext());

        $this->assertSame($diagnoseResponse, $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key');
        });

        $this->assertIsArray($payload);
        $this->assertSame('gpt-5.4-mini', $payload['model']);
        $this->assertFalse($payload['store']);
        $this->assertSame($this->diagnosisContext()['system_instruction'], $payload['instructions']);

        $input = json_decode(
            $payload['input'][0]['content'][0]['text'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame($this->diagnosisContext()['trigger_fact'], $input['trigger_fact']);
        $this->assertSame($this->diagnosisContext()['supporting_facts'], $input['supporting_facts']);
        $this->assertSame($this->diagnosisContext()['allowed_categories'], $input['allowed_categories']);

        // Only these 3 top-level keys are sent — no user_prompt, no
        // Data Profile, no sample_rows.
        $this->assertSame(['trigger_fact', 'supporting_facts', 'allowed_categories'], array_keys($input));

        $format = $payload['text']['format'];
        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('reportflow_diagnosis_result', $format['name']);
        $this->assertTrue($format['strict']);

        $schema = $format['schema'];
        $this->assertSame(['primary_diagnosis'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);

        $properties = $schema['properties']['primary_diagnosis'];
        $this->assertSame(
            ['category_key', 'self_reported_confidence', 'rationale_summary', 'evidence_refs', 'missing_evidence'],
            $properties['required'],
        );
        $this->assertFalse($properties['additionalProperties']);
        $this->assertArrayNotHasKey('priority', $properties['properties']);
        $this->assertArrayNotHasKey('action', $properties['properties']);
        $this->assertArrayNotHasKey('alternatives', $schema['properties']);

        // Evidence-grounding minimum: the schema itself asks for at least
        // one evidence_ref (a first line of defense — see
        // diagnosisResultSchema()'s docblock for why Laravel
        // post-validation remains the actual enforcement).
        $this->assertSame(1, $properties['properties']['evidence_refs']['minItems']);
    }

    /**
     * category_key's "enum" is built dynamically from this specific
     * request's allowed_categories — never a fixed, hardcoded list.
     */
    public function test_diagnose_constrains_category_key_to_this_requests_allowed_categories(): void
    {
        $context = $this->diagnosisContext();
        $context['allowed_categories'] = ['insufficient_explanatory_evidence'];

        $diagnoseResponse = json_encode([
            'primary_diagnosis' => [
                'category_key' => 'insufficient_explanatory_evidence',
                'self_reported_confidence' => 0.5,
                'rationale_summary' => 'No distinguishing evidence.',
                'evidence_refs' => [],
                'missing_evidence' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $payload = null;

        Http::fake(function (Request $request) use (&$payload, $diagnoseResponse) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($diagnoseResponse));
        });

        (new AiAnalysisClient)->diagnose($context);

        $enum = $payload['text']['format']['schema']['properties']['primary_diagnosis']['properties']['category_key']['enum'];
        $this->assertSame(['insufficient_explanatory_evidence'], $enum);
    }

    public function test_diagnose_throws_when_the_api_key_is_not_configured(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        try {
            (new AiAnalysisClient)->diagnose($this->diagnosisContext());

            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI API key is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_diagnose_throws_for_unsuccessful_http_responses(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API request failed with HTTP status 500.');

        (new AiAnalysisClient)->diagnose($this->diagnosisContext());
    }

    public function test_diagnose_throws_when_openai_refuses_the_request(): void
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
        $this->expectExceptionMessage('OpenAI refused the diagnosis request.');

        (new AiAnalysisClient)->diagnose($this->diagnosisContext());
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosisContext(): array
    {
        return [
            'system_instruction' => 'Choose category_key only from allowed_categories.',
            'trigger_fact' => [
                'evaluation_fact_id' => 123,
                'entity_type' => 'channel',
                'entity_key' => 'Social',
                'metric_key' => 'conversion_rate',
                'metric_value' => 0.04,
                'display_baseline_value' => 0.054,
                'test_baseline_value' => 0.061,
                'delta_absolute' => -0.014,
                'delta_percent' => -0.259259,
                'direction' => 'below',
                'evaluation_level' => 'high',
                'numerator_value' => 200,
                'denominator_value' => 5000,
                'control_numerator_value' => 610,
                'control_denominator_value' => 10000,
                'z_score' => -5.36,
            ],
            'supporting_facts' => [
                ['metric_key' => 'spend', 'value' => 150000],
                ['metric_key' => 'revenue', 'value' => 420000],
            ],
            'allowed_categories' => ['measurement_consistency_risk', 'insufficient_explanatory_evidence'],
        ];
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

    public function test_propose_action_sends_only_the_evidence_package_with_request_specific_enums(): void
    {
        $payload = null;
        $output = json_encode([
            'catalog_key' => 'verify_measurement_consistency',
            'title' => 'Verify measurement',
            'rationale_summary' => 'Worth checking.',
            'selected_checks' => ['verify_tag_firing'],
            'evidence_refs' => ['evaluation_fact:10'],
            'missing_evidence' => [],
        ], JSON_THROW_ON_ERROR);

        Http::fake(function (Request $request) use (&$payload, $output) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse($output));
        });

        $package = $this->actionEvidencePackage();
        $result = (new AiAnalysisClient)->proposeAction([
            'system_instruction' => 'Advisory only.',
            'evidence_package' => $package,
        ]);

        $this->assertSame($output, $result);
        $this->assertSame($package, json_decode($payload['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(['verify_measurement_consistency'], $payload['text']['format']['schema']['properties']['catalog_key']['enum']);
        $this->assertSame(['verify_tag_firing'], $payload['text']['format']['schema']['properties']['selected_checks']['items']['enum']);
        $this->assertSame($package['allowed_evidence_refs'], $payload['text']['format']['schema']['properties']['evidence_refs']['items']['enum']);
        $this->assertSame(1, $payload['text']['format']['schema']['properties']['evidence_refs']['minItems']);
        $this->assertArrayNotHasKey('uniqueItems', $payload['text']['format']['schema']['properties']['selected_checks']);
        $this->assertArrayNotHasKey('maxLength', $payload['text']['format']['schema']['properties']['title']);
    }

    public function test_collect_evidence_schema_does_not_emit_an_empty_selected_check_enum(): void
    {
        $payload = null;
        Http::fake(function (Request $request) use (&$payload) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($this->completedResponse('{}'));
        });

        $package = $this->actionEvidencePackage();
        $package['action_catalog_key'] = 'collect_explanatory_evidence';
        $package['allowed_checks'] = [];

        (new AiAnalysisClient)->proposeAction(['system_instruction' => 'Advisory only.', 'evidence_package' => $package]);

        $this->assertArrayNotHasKey('enum', $payload['text']['format']['schema']['properties']['selected_checks']['items']);
    }

    /** @return array<string, mixed> */
    private function actionEvidencePackage(): array
    {
        return [
            'analysis_job_id' => 1,
            'action_catalog_key' => 'verify_measurement_consistency',
            'trigger_fact' => ['evaluation_fact_id' => 10],
            'diagnosis' => ['diagnosis_result_id' => 20, 'missing_evidence' => []],
            'priority' => ['priority_result_id' => 30],
            'allowed_checks' => ['verify_tag_firing'],
            'allowed_evidence_refs' => ['evaluation_fact:10', 'diagnosis_result:20', 'priority_result:30'],
            'contract_version' => 'action_contract_v1',
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
