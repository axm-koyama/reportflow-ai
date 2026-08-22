<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\AI\AiAnalysisClient;
use InvalidArgumentException;
use JsonException;

/**
 * Asks the AI which derived (calculated) metrics — e.g. ROAS — would help
 * answer the user's prompt, without ever showing it the actual numeric
 * values. See docs/product/DERIVED_METRICS.md.
 *
 * Responsibilities:
 * - Build the Metric Planning Context: user_prompt, the list of dimension
 *   names / measure names / aggregation types actually available in
 *   aggregated_metrics (names only — no sums, counts, or averages), and
 *   max_derived_metrics (read from config/derived_metrics.php, the same
 *   Single Source of Truth CalculateDerivedMetricsAction enforces — see
 *   docs/product/DERIVED_METRICS.md "max_derived_metrics")
 * - Call AiAnalysisClient::planMetrics()
 * - Decode the raw response into a list of untrusted, unvalidated
 *   CalculationDefinition arrays
 *
 * This action never validates a CalculationDefinition's semantics (does
 * "revenue" actually exist as a measure? is "channel" an allowed
 * group_by?) — that is entirely CalculateDerivedMetricsAction's
 * responsibility. This mirrors the existing split between
 * AiAnalysisClient::analyze() (returns a raw string) and
 * NormalizeAnalysisResultAction (validates it).
 *
 * Out of scope: computing any derived metric value, reading aggregated_metrics'
 * numeric values, persistence, AnalysisJob status updates.
 */
class PlanDerivedMetricsAction
{
    /**
     * The only aggregation types MetricAggregationAction ever computes per
     * group — see docs/product/METRIC_AGGREGATION.md §8.
     *
     * @var list<string>
     */
    private const array AVAILABLE_AGGREGATIONS = ['sum', 'count', 'avg'];

    public function __construct(
        private readonly AiAnalysisClient $aiAnalysisClient,
    ) {}

    /**
     * Propose CalculationDefinitions for one analysis request.
     *
     * If aggregated_metrics has no dimensions or no measures at all, there
     * is nothing meaningful to plan against, so the AI is never called.
     *
     * @param string $prompt
     * @param array<string, mixed> $aggregatedMetrics the MetricAggregationAction output for this AnalysisJob
     * @return list<array<string, mixed>> raw, untrusted CalculationDefinitions
     * @throws InvalidArgumentException if the AI response is not valid JSON
     *                                   or does not contain a "derived_metrics" array.
     */
    public function execute(string $prompt, array $aggregatedMetrics): array
    {
        $availableDimensions = array_column($aggregatedMetrics['dimensions'] ?? [], 'dimension');
        $availableMeasures = $aggregatedMetrics['measures'] ?? [];

        if ($availableDimensions === [] || $availableMeasures === []) {
            return [];
        }

        $context = [
            'system_instruction' => $this->systemInstruction(),
            'user_prompt' => $prompt,
            'available_dimensions' => $availableDimensions,
            'available_measures' => $availableMeasures,
            'available_aggregations' => self::AVAILABLE_AGGREGATIONS,
            'max_derived_metrics' => (int) config('derived_metrics.max_derived_metrics', 5),
        ];

        $rawResponse = $this->aiAnalysisClient->planMetrics($context);

        return $this->decode($rawResponse);
    }

    /**
     * The Metric Planning System Instruction.
     *
     * Distinct from BuildAnalysisContextAction's analysis System
     * Instruction: this one governs a narrow planning decision (which
     * derived metrics, if any, are worth computing), not the final
     * business analysis.
     */
    private function systemInstruction(): string
    {
        return <<<'TEXT'
            You are a metrics planning assistant for a business data analysis system.

            Your only task is to decide which derived (calculated) metrics would help
            answer the user's request, using only the available dimensions, measures,
            and aggregation types listed in the supplied context. You are not shown the
            actual data values, so you cannot and must not reason about specific numbers
            here — that happens in a separate, later step.

            Rules:

            1. Propose no more than max_derived_metrics derived metrics (the exact
            number is given to you in the supplied context — do not assume any fixed
            number).

            2. Only propose a derived metric if it is clearly useful for answering the
            user's specific request. Do not propose a derived metric the user did not
            ask about or that is unrelated to their request.

            3. Do not exhaustively propose every possible combination of measures. Prefer
            a small number of business-meaningful derived metrics (e.g. a cost-efficiency
            ratio) over generating combinations for their own sake.

            4. Each operand's "metric" must come only from available_measures. Each
            operand's "aggregation" must come only from available_aggregations.
            "group_by" must come only from available_dimensions. Never invent a
            referenced metric, aggregation, or group_by value that is not present in
            its corresponding available_* list.

            5. Give each derived metric a "name" that is a unique, meaningful
            identifier describing what it represents — not just the name of one of
            its input measures. This "name" is a new identifier you create; it is not
            looked up in any available_* list. Good names: "revenue_per_spend",
            "conversions_per_spend", "revenue_per_conversion", "conversion_rate",
            "click_through_rate". Bad names (do not do this): "revenue", "spend",
            "conversions", "clicks" — reusing a bare measure name as the derived
            metric's name is not allowed, and every derived metric in your response
            must have a different "name" from every other one. "name" must still
            match ^[A-Za-z][A-Za-z0-9_]{0,63}$ (letters, digits, underscore; must
            start with a letter).

            6. Choose "operator" according to what the derived metric semantically
            represents, not by name pattern alone:

            - Use "percentage" when the derived metric represents a rate or a
            percentage. "percentage" computes (left / right) * 100. Examples:
            conversion_rate = conversions / clicks, expressed as "percentage"; and
            click_through_rate = clicks / impressions, expressed as "percentage".

            - Use "divide" when the derived metric represents a ratio or a per-unit
            efficiency value rather than a percentage. "divide" computes left / right
            with no scaling. Examples: revenue_per_spend = revenue / spend, as
            "divide"; conversions_per_spend = conversions / spend, as "divide"; and
            revenue_per_conversion = revenue / conversions, as "divide".

            7. If no derived metric would meaningfully help answer the user's request,
            return an empty list. An empty list is a valid and often correct answer.

            8. Return only the required structured output.
            TEXT;
    }

    /**
     * Decode the raw planMetrics() response into a list of untrusted
     * CalculationDefinition arrays. This only checks the outer JSON shape
     * (valid JSON, "derived_metrics" is an array); it does not validate
     * any individual definition's fields.
     *
     * @param string $rawResponse
     * @return list<array<string, mixed>>
     * @throws InvalidArgumentException
     */
    private function decode(string $rawResponse): array
    {
        try {
            $decoded = json_decode($rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Metric Plan response is not valid JSON.', previous: $e);
        }

        if (! is_array($decoded) || ! isset($decoded['derived_metrics']) || ! is_array($decoded['derived_metrics'])) {
            throw new InvalidArgumentException('Metric Plan response must contain a "derived_metrics" array.');
        }

        // Defensively drop any entry that is not itself an object/array —
        // CalculateDerivedMetricsAction still validates every remaining
        // field, this just avoids passing a scalar where an array is
        // expected.
        return array_values(array_filter($decoded['derived_metrics'], 'is_array'));
    }
}
