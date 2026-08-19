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
