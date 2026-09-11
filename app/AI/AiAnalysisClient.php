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
 *   aggregated_metrics / derived_metrics / analysis_template /
 *   column_mapping / output_schema) onto an OpenAI Responses API request
 * - Request strict Structured Outputs (text.format.type = json_schema,
 *   strict = true) so the response conforms to ReportFlow AI's V1 Result
 *   Schema
 * - Extract and return only the structured output JSON text
 * - Also expose planMetrics(): a second, independent request/response
 *   pair used for Metric Planning (see PlanDerivedMetricsAction /
 *   docs/product/DERIVED_METRICS.md). It targets a different, much
 *   smaller Structured Output schema and never receives aggregated_metrics'
 *   actual numeric values.
 * - Also expose mapColumns(): a third, independent request/response pair
 *   used for Analysis Template Column Mapping (see
 *   MapAnalysisTemplateColumnsAction / docs/product/ANALYSIS_TEMPLATE_MODULE.md).
 *   Only reached when an AnalysisJob specifies a template_key; free-form
 *   analysis never calls this method.
 * - Also expose diagnose(): a fourth, independent request/response pair
 *   used for Phase 4-B Controlled Diagnosis (see
 *   RunDiagnosisForAnalysisJobAction / docs/product/DIAGNOSIS_ENGINE.md).
 *   Only reached per already-eligible EvaluationFact (see
 *   DetermineDiagnosisEligibilityAction); it never receives a DataFile,
 *   sample_rows, a full Data Profile, or user_prompt — only the minimal
 *   Evidence Package (trigger_fact / supporting_facts / allowed_categories).
 *
 * Out of scope:
 * - Reading DataFile / CSV content (that is DataProfilingAction's
 *   responsibility; this client never receives a DataFile)
 * - Persisting anything to the database
 * - Normalizing/validating the returned JSON against the Result Schema,
 *   the Metric Plan, or the Column Mapping (that is
 *   NormalizeAnalysisResultAction's / CalculateDerivedMetricsAction's /
 *   ValidateColumnMappingAction's responsibility, respectively)
 * - Retrying failed requests (a single HTTP attempt is made per method
 *   call; retrying a failed AnalysisJob attempt is Laravel Queue's
 *   responsibility, see ExecuteAnalysisJob)
 * - Tool calling / function calling / any multi-turn conversation state
 *   (every method sends a single, stateless `store: false` request; see
 *   docs/product/DERIVED_METRICS.md "AIを2回呼ぶ理由" for why Metric
 *   Planning — and, by the same reasoning, Column Mapping — is an
 *   independent request rather than a tool call)
 *
 * All application-level failures are raised as RuntimeException.
 */
class AiAnalysisClient
{
    /**
     * Send an analysis context to OpenAI and return its structured output JSON.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws RuntimeException if the API key is not configured, the AI
     *                          Context cannot be encoded as JSON, the
     *                          request fails (connection error or
     *                          non-2xx status), or the response does not
     *                          contain a usable structured output.
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
                'derived_metrics' => $context['derived_metrics'],
                'analysis_template' => $context['analysis_template'],
                'column_mapping' => $context['column_mapping'],
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
     * Send a Metric Planning context to OpenAI and return its structured
     * output JSON: a proposed, untrusted list of CalculationDefinition
     * objects (see PlanDerivedMetricsAction, which is solely responsible
     * for decoding this string, and CalculateDerivedMetricsAction, which
     * is solely responsible for validating and computing from it).
     *
     * $context never carries aggregated_metrics' actual numeric values —
     * only the names of available dimensions / measures / aggregations —
     * so a Metric Plan request is deliberately far smaller than an
     * analyze() request. See docs/product/DERIVED_METRICS.md.
     *
     * This method's HTTP mechanics (auth check, timeout, status/output
     * extraction, error messages) intentionally mirror analyze() rather
     * than sharing implementation with it. analyze() has extensive
     * existing test coverage; factoring out shared internals risked
     * introducing a subtle regression there for a modest amount of shared
     * code. See docs/product/DERIVED_METRICS.md "AIを2回呼ぶ理由" for the
     * full rationale. The one piece of logic that is safely reused as-is
     * is nonCompletedStatusMessage(), which analyze() does not need to
     * change to share.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws RuntimeException if the API key is not configured, the
     *                          Metric Planning Context cannot be encoded
     *                          as JSON, the request fails (connection
     *                          error or non-2xx status), or the response
     *                          does not contain a usable structured
     *                          output.
     */
    public function planMetrics(array $context): string
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        try {
            $inputText = json_encode([
                'user_prompt' => $context['user_prompt'],
                'available_dimensions' => $context['available_dimensions'],
                'available_measures' => $context['available_measures'],
                'available_aggregations' => $context['available_aggregations'],
                'max_derived_metrics' => $context['max_derived_metrics'],
                'analysis_template' => $context['analysis_template'],
                'column_mapping' => $context['column_mapping'],
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Failed to encode Metric Planning Context as JSON.',
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
                            'name' => 'reportflow_derived_metrics_plan',
                            'strict' => true,
                            'schema' => $this->derivedMetricsPlanSchema($context['available_dimensions']),
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
                    throw new RuntimeException('OpenAI refused the metric planning request.');
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
     * Send an Analysis Template Column Mapping context to OpenAI and
     * return its structured output JSON: a proposed, untrusted list of
     * {field, column, confidence} mappings (see
     * MapAnalysisTemplateColumnsAction, which is solely responsible for
     * decoding this string, and ValidateColumnMappingAction, which is
     * solely responsible for validating it — confidence tiering,
     * ambiguous/duplicate detection, required-field gating).
     *
     * $context's "column_candidates" are pre-filtered by
     * ResolveAnalysisTemplateAction using each semantic field's declared
     * "kind" against DataProfilingAction's inferred_type, so this request
     * only ever asks the AI to choose among type-plausible candidates —
     * never the full column list. See
     * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
     *
     * This method's HTTP mechanics intentionally mirror analyze() /
     * planMetrics() rather than sharing implementation with them, for the
     * same reason documented on planMetrics(): existing methods have
     * extensive test coverage, and a shared-internals refactor risks a
     * subtle regression there for a modest amount of shared code.
     * nonCompletedStatusMessage() is the one exception, reused as-is.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws RuntimeException if the API key is not configured, the
     *                          Column Mapping Context cannot be encoded
     *                          as JSON, the request fails (connection
     *                          error or non-2xx status), or the response
     *                          does not contain a usable structured
     *                          output.
     */
    public function mapColumns(array $context): string
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        try {
            $inputText = json_encode([
                'user_prompt' => $context['user_prompt'],
                'template_fields' => $context['template_fields'],
                'column_candidates' => $context['column_candidates'],
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Failed to encode Column Mapping Context as JSON.',
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
                            'name' => 'reportflow_column_mapping',
                            'strict' => true,
                            'schema' => $this->columnMappingSchema(),
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
                    throw new RuntimeException('OpenAI refused the column mapping request.');
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
     * Send a Phase 4-B Diagnosis context to OpenAI and return its
     * structured output JSON: exactly one primary_diagnosis (category_key
     * / self_reported_confidence / rationale_summary / evidence_refs /
     * missing_evidence — see NormalizeDiagnosisResultAction, which is
     * solely responsible for decoding and re-validating this string).
     *
     * $context's "allowed_categories" is this specific EvaluationFact's
     * Evidence-gated category set (see BuildDiagnosisEvidencePackageAction)
     * — never the full config/diagnosis_categories.php catalog. It drives
     * diagnosisResultSchema()'s dynamic "enum", exactly like
     * derivedMetricsPlanSchema()'s "group_by" enum is driven by a specific
     * request's available_dimensions (see that schema's docblock for the
     * general rationale: an API-level "enum" is a stronger guarantee than
     * asking nicely in the prompt, but it only ever narrows an
     * already-non-nullable string field to real, offered values — it
     * never widens anything).
     *
     * Unlike analyze()/planMetrics()/mapColumns(), $context never carries
     * a user_prompt or any Data Profile/sample_rows — see
     * docs/product/DIAGNOSIS_ENGINE.md "raw sample_rows禁止" /
     * "user_prompt禁止". The Evidence Package (trigger_fact /
     * supporting_facts) is the entire numeric input.
     *
     * This method's HTTP mechanics intentionally mirror analyze() /
     * planMetrics() / mapColumns() rather than sharing implementation with
     * them, for the same reason documented on planMetrics(): existing
     * methods have extensive test coverage, and a shared-internals
     * refactor risks a subtle regression there for a modest amount of
     * shared code. nonCompletedStatusMessage() is the one exception,
     * reused as-is.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws RuntimeException if the API key is not configured, the
     *                          Diagnosis Context cannot be encoded as
     *                          JSON, the request fails (connection error
     *                          or non-2xx status), or the response does
     *                          not contain a usable structured output.
     */
    public function diagnose(array $context): string
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        try {
            $inputText = json_encode([
                'trigger_fact' => $context['trigger_fact'],
                'supporting_facts' => $context['supporting_facts'],
                'allowed_categories' => $context['allowed_categories'],
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Failed to encode Diagnosis Context as JSON.',
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
                            'name' => 'reportflow_diagnosis_result',
                            'strict' => true,
                            'schema' => $this->diagnosisResultSchema($context['allowed_categories']),
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
                    throw new RuntimeException('OpenAI refused the diagnosis request.');
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
     * Send one controlled Action Evidence Package to OpenAI.
     *
     * @param  array{system_instruction: string, evidence_package: array<string, mixed>}  $context
     */
    public function proposeAction(array $context): string
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $package = $context['evidence_package'];

        try {
            $inputText = json_encode($package, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode Action Evidence Package as JSON.', previous: $exception);
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('services.openai.timeout'))
                ->post((string) config('services.openai.responses_url'), [
                    'model' => config('services.openai.model'),
                    'store' => false,
                    'instructions' => $context['system_instruction'],
                    'input' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $inputText,
                        ]],
                    ]],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'reportflow_action_proposal',
                            'strict' => true,
                            'schema' => $this->actionProposalSchema($package),
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenAI API request failed due to a connection error.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("OpenAI API request failed with HTTP status {$response->status()}.");
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
                    throw new RuntimeException('OpenAI refused the action proposal request.');
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
     * @param  array<string, mixed>  $responseBody
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

    /**
     * The Structured Output schema for planMetrics(): a list of proposed
     * CalculationDefinition objects. The enum constraints on "operator"
     * and "left"/"right".aggregation give a first line of defense (the
     * API itself refuses to return a value outside these lists), but
     * CalculateDerivedMetricsAction still independently validates every
     * field — this schema cannot know which measures/dimensions actually
     * exist in a given AnalysisJob's data, only their allowed *shape*.
     *
     * "group_by" is a plain string (`{"type": "string"}`, never
     * `{"type": ["string", "null"]}`) — Phase 2 requires group_by on
     * every CalculationDefinition and never allowed null/grand-total (see
     * docs/product/DERIVED_METRICS.md §10 "group_by必須(nullを許可し
     * ない)"; CalculateDerivedMetricsAction rejects a null/missing
     * group_by as "missing_group_by", unconditionally, independent of
     * this schema).
     *
     * "group_by" additionally carries an "enum" constrained to this exact
     * request's $availableDimensions, so the OpenAI Responses API itself
     * refuses to return any group_by value other than one of the exact
     * strings Planning Context offered — this is a second, API-enforced
     * line of defense on top of PlanDerivedMetricsAction's System
     * Instruction (which separately tells the AI to copy the string
     * verbatim, never translate/paraphrase it), added after a real
     * response was observed proposing a Template field *label*
     * ("カテゴリ") instead of the real column name ("分類") that was
     * actually present in available_dimensions —
     * CalculateDerivedMetricsAction correctly rejected it as
     * "unknown_group_by", but the derived metric was lost rather than
     * computed. This "enum" only ever *narrows* an already-non-nullable
     * string field to a fixed set of real column-name values — it never
     * widens "type" to admit null, and $availableDimensions being empty
     * (see the @param doc below) omits "enum" entirely rather than ever
     * falling back to allowing null. "metric"/"aggregation" are
     * deliberately NOT similarly constrained to available_measures here:
     * only group_by's failure mode has been observed in practice, and
     * constraining every operand field would meaningfully increase this
     * schema's complexity for a risk that has not been demonstrated —
     * CalculateDerivedMetricsAction remains the actual safety net for
     * every field regardless.
     *
     * This schema deliberately has no "maxItems" on the "derived_metrics"
     * array. config('derived_metrics.max_derived_metrics') is the Single
     * Source of Truth for that limit — it flows into the Planning
     * Context's "max_derived_metrics" (PlanDerivedMetricsAction) as
     * guidance to the AI, and CalculateDerivedMetricsAction enforces it
     * as the actual application-side limit regardless of what the AI
     * returns. Hardcoding a matching "maxItems" here would create a
     * second, easily-forgotten place to keep in sync with the config
     * value, for a constraint Laravel already enforces safely after the
     * fact. See docs/product/DERIVED_METRICS.md "max_derived_metrics".
     *
     * @param  list<string>  $availableDimensions  this request's Planning
     *                                             Context "available_dimensions" (always non-empty in
     *                                             practice — PlanDerivedMetricsAction never calls planMetrics()
     *                                             when aggregated_metrics has no dimensions at all). Left
     *                                             unconstrained (no "enum") if empty, defensively, so this
     *                                             never produces a JSON Schema no response could ever satisfy.
     * @return array<string, mixed>
     */
    private function derivedMetricsPlanSchema(array $availableDimensions): array
    {
        $operand = [
            'type' => 'object',
            'properties' => [
                'metric' => ['type' => 'string'],
                'aggregation' => [
                    'type' => 'string',
                    'enum' => ['sum', 'count', 'avg'],
                ],
            ],
            'required' => ['metric', 'aggregation'],
            'additionalProperties' => false,
        ];

        $groupBy = ['type' => 'string'];

        if ($availableDimensions !== []) {
            $groupBy['enum'] = array_values($availableDimensions);
        }

        return [
            'type' => 'object',
            'properties' => [
                'derived_metrics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'operator' => [
                                'type' => 'string',
                                'enum' => ['divide', 'multiply', 'add', 'subtract', 'percentage'],
                            ],
                            'left' => $operand,
                            'right' => $operand,
                            'group_by' => $groupBy,
                        ],
                        'required' => ['name', 'operator', 'left', 'right', 'group_by'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['derived_metrics'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The Structured Output schema for mapColumns(): a list of proposed
     * {field, column, confidence} mappings. As with derivedMetricsPlanSchema(),
     * the enum constraint on "confidence" is a first line of defense only —
     * ValidateColumnMappingAction independently re-validates every field
     * (does "column" actually appear in this field's column_candidates?
     * is a column claimed by more than one field?), since this schema
     * cannot know that.
     *
     * "column" is nullable: the AI must be able to say "no candidate
     * fits" rather than being forced to name one.
     *
     * @return array<string, mixed>
     */
    private function columnMappingSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mappings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'column' => ['type' => ['string', 'null']],
                            'confidence' => [
                                'type' => 'string',
                                'enum' => ['high', 'low', 'unmapped'],
                            ],
                        ],
                        'required' => ['field', 'column', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['mappings'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The Structured Output schema for diagnose(): exactly one
     * primary_diagnosis. No "alternatives" (Phase 4-B v1 deliberately
     * returns a single diagnosis — see docs/product/DIAGNOSIS_ENGINE.md
     * "Structured Output"), and no priority/action field of any kind
     * exists in this schema at all — a stronger guarantee than a prompt
     * instruction not to produce one, since the API itself cannot return
     * a field this schema never declares.
     *
     * "category_key"'s "enum" is built from this specific request's
     * $allowedCategories (the Evidence Gate's output — see
     * BuildDiagnosisEvidencePackageAction), never the full
     * config/diagnosis_categories.php catalog. $allowedCategories always
     * contains at least "insufficient_explanatory_evidence" in practice
     * (see that Action's docblock), but this defensively omits "enum"
     * entirely if it is ever empty, mirroring
     * derivedMetricsPlanSchema()'s $availableDimensions handling — never
     * producing a JSON Schema no response could ever satisfy.
     *
     * "evidence_refs"/"missing_evidence" remain plain string arrays in
     * the legacy Diagnosis contract. Phase 4-D's separate Action schema
     * uses items.enum for request-specific reference membership; changing
     * this older Diagnosis schema is outside that implementation scope.
     * evidence_refs
     * does carry "minItems: 1" — confirmed (via a standalone probe
     * request) to be honored by the OpenAI Responses API in strict mode,
     * so this is a genuine first line of defense against an empty
     * evidence_refs, not a documentation-only hint. It is still not
     * sufficient on its own (a model satisfying "minItems: 1" the cheap
     * way, e.g. a single empty-string element, was observed during that
     * same probe), so Laravel post-validation
     * (NormalizeDiagnosisResultAction) remains the actual enforcement for
     * both "non-empty" and "every element is a real supplied identifier"
     * — see that class's docblock and docs/product/DIAGNOSIS_ENGINE.md
     * "Structured Output != Semantic Correctness".
     *
     * @param  list<string>  $allowedCategories
     * @return array<string, mixed>
     */
    private function diagnosisResultSchema(array $allowedCategories): array
    {
        $categoryKey = ['type' => 'string'];

        if ($allowedCategories !== []) {
            $categoryKey['enum'] = array_values($allowedCategories);
        }

        return [
            'type' => 'object',
            'properties' => [
                'primary_diagnosis' => [
                    'type' => 'object',
                    'properties' => [
                        'category_key' => $categoryKey,
                        'self_reported_confidence' => ['type' => 'number'],
                        'rationale_summary' => ['type' => 'string'],
                        'evidence_refs' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'minItems' => 1,
                        ],
                        'missing_evidence' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => [
                        'category_key',
                        'self_reported_confidence',
                        'rationale_summary',
                        'evidence_refs',
                        'missing_evidence',
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['primary_diagnosis'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function actionProposalSchema(array $package): array
    {
        $selectedChecks = ['type' => 'string'];
        $allowedChecks = $package['allowed_checks'] ?? [];

        if (is_array($allowedChecks) && $allowedChecks !== []) {
            $selectedChecks['enum'] = array_values($allowedChecks);
        }

        $evidenceRef = ['type' => 'string'];
        $allowedEvidenceRefs = $package['allowed_evidence_refs'] ?? [];

        if (is_array($allowedEvidenceRefs) && $allowedEvidenceRefs !== []) {
            $evidenceRef['enum'] = array_values($allowedEvidenceRefs);
        }

        return [
            'type' => 'object',
            'properties' => [
                'catalog_key' => [
                    'type' => 'string',
                    'enum' => [(string) ($package['action_catalog_key'] ?? '')],
                ],
                'title' => ['type' => 'string'],
                'rationale_summary' => ['type' => 'string'],
                'selected_checks' => [
                    'type' => 'array',
                    'items' => $selectedChecks,
                ],
                'evidence_refs' => [
                    'type' => 'array',
                    'items' => $evidenceRef,
                    'minItems' => 1,
                ],
                'missing_evidence' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'catalog_key',
                'title',
                'rationale_summary',
                'selected_checks',
                'evidence_refs',
                'missing_evidence',
            ],
            'additionalProperties' => false,
        ];
    }
}
