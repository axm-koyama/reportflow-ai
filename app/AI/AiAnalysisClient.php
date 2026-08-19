<?php

declare(strict_types=1);

namespace App\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * Transport client that sends a provider-neutral AI Context
 * (see BuildAnalysisContextAction) to the OpenAI Responses API and
 * returns its Structured Output as a raw JSON string.
 *
 * Responsibilities:
 * - Map the AI Context (system_instruction / user_prompt / data_profile /
 *   aggregated_metrics / output_schema) onto an OpenAI Responses API request
 * - Request strict Structured Outputs (text.format.type = json_schema,
 *   strict = true) so the response conforms to ReportFlow AI's V1 Result
 *   Schema
 * - Extract and return only the structured output JSON text
 *
 * Out of scope:
 * - Reading DataFile / CSV content (that is DataProfilingAction's
 *   responsibility; this client never receives a DataFile)
 * - Persisting anything to the database
 * - Normalizing/validating the returned JSON against the Result Schema
 *   (that is NormalizeAnalysisResultAction's responsibility)
 * - Retrying failed requests (a single HTTP attempt is made; retrying a
 *   failed AnalysisJob attempt is Laravel Queue's responsibility, see
 *   ExecuteAnalysisJob)
 *
 * All application-level failures are raised as RuntimeException.
 */
class AiAnalysisClient
{
    /**
     * Send an analysis context to OpenAI and return its structured output JSON.
     *
     * @param array<string, mixed> $context
     * @return string
     * @throws RuntimeException if the API key is not configured, the AI
     *                           Context cannot be encoded as JSON, the
     *                           request fails (connection error or
     *                           non-2xx status), or the response does not
     *                           contain a usable structured output.
     */
    public function analyze(array $context): string
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        try {
            $inputText = json_encode([
                'user_prompt' => $context['user_prompt'],
                'data_profile' => $context['data_profile'],
                'aggregated_metrics' => $context['aggregated_metrics'],
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Failed to encode AI Context as JSON.',
                previous: $exception,
            );
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('services.openai.timeout'))
                ->post((string) config('services.openai.responses_url'), [
                    'model' => config('services.openai.model'),
                    'store' => false,
                    'instructions' => $context['system_instruction'],
                    'input' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'input_text',
                                    'text' => $inputText,
                                ],
                            ],
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'reportflow_analysis_result',
                            'strict' => true,
                            'schema' => $this->openAiResultSchema(),
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'OpenAI API request failed due to a connection error.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "OpenAI API request failed with HTTP status {$response->status()}.",
            );
        }

        $responseBody = $response->json();

        if (! is_array($responseBody)) {
            throw new RuntimeException('OpenAI response had an unexpected shape.');
        }

        $status = $responseBody['status'] ?? null;

        if ($status === 'incomplete') {
            throw new RuntimeException('OpenAI response was incomplete.');
        }

        if ($status !== 'completed') {
            throw new RuntimeException($this->nonCompletedStatusMessage($responseBody, $status));
        }

        $output = $responseBody['output'] ?? null;

        if (! is_array($output)) {
            throw new RuntimeException('OpenAI response had an unexpected shape.');
        }

        foreach ($output as $outputItem) {
            if (! is_array($outputItem)) {
                throw new RuntimeException('OpenAI response had an unexpected shape.');
            }

            // Only "message" output items carry Structured Output content.
            // Other item types (e.g. "reasoning") are skipped rather than
            // rejected, so their differently-shaped (or absent) "content"
            // never causes a false "unexpected shape" failure.
            if (($outputItem['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $outputItem['content'] ?? null;

            if (! is_array($content)) {
                throw new RuntimeException('OpenAI response had an unexpected shape.');
            }

            foreach ($content as $contentItem) {
                if (! is_array($contentItem)) {
                    throw new RuntimeException('OpenAI response had an unexpected shape.');
                }

                if (($contentItem['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('OpenAI refused the analysis request.');
                }

                if (($contentItem['type'] ?? null) === 'output_text'
                    && is_string($contentItem['text'] ?? null)) {
                    return $contentItem['text'];
                }
            }
        }

        throw new RuntimeException('OpenAI response did not contain structured output.');
    }

    /**
     * Build the diagnostic message for a non-completed Responses API
     * status. Only `status` and `error.code` are included — never
     * `error.message`, since a provider-authored error message is not
     * guaranteed to exclude echoed request content (User Prompt / Data
     * Profile), and this message may end up in exception traces or logs.
     *
     * @param array<string, mixed> $responseBody
     * @param mixed $status
     * @return string
     */
    private function nonCompletedStatusMessage(array $responseBody, mixed $status): string
    {
        $statusLabel = is_string($status) && $status !== '' ? $status : 'unknown';
        $message = "OpenAI response did not complete with status {$statusLabel}.";

        $error = $responseBody['error'] ?? null;
        $errorCode = is_array($error) ? ($error['code'] ?? null) : null;

        if (is_string($errorCode) && $errorCode !== '') {
            $message .= " Error code: {$errorCode}.";
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiResultSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                ],
                'highlights' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'metrics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'unit' => ['type' => ['string', 'null']],
                            'change' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['label', 'value', 'unit', 'change'],
                        'additionalProperties' => false,
                    ],
                ],
                'tables' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'columns' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'rows' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'required' => ['title', 'columns', 'rows'],
                        'additionalProperties' => false,
                    ],
                ],
                'insights' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'evidence' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['title', 'description', 'evidence'],
                        'additionalProperties' => false,
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'priority' => [
                                'type' => ['string', 'null'],
                                'enum' => ['high', 'medium', 'low', null],
                            ],
                        ],
                        'required' => ['title', 'description', 'priority'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'summary',
                'highlights',
                'metrics',
                'tables',
                'insights',
                'recommendations',
            ],
            'additionalProperties' => false,
        ];
    }
}
