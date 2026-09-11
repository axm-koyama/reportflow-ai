<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\AI\AiAnalysisClient;
use InvalidArgumentException;
use JsonException;

/**
 * Asks the AI to map an Analysis Template's semantic fields (e.g.
 * "channel", "spend") onto the best-fitting real CSV columns for one
 * DataFile. See docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * Responsibilities:
 * - Build the Column Mapping Context: user_prompt, template_fields, and
 *   column_candidates (already type-filtered per field by
 *   ResolveAnalysisTemplateAction — this action never re-derives
 *   candidates itself)
 * - Call AiAnalysisClient::mapColumns()
 * - Decode the raw response into a list of untrusted, unvalidated
 *   {field, column, confidence} proposals
 *
 * This action never validates a proposal's business correctness (does
 * "column" actually appear in that field's candidates? is a column
 * claimed by more than one field? is a required field left unmapped?) —
 * that is entirely ValidateColumnMappingAction's responsibility. This
 * mirrors the existing PlanDerivedMetricsAction / CalculateDerivedMetricsAction
 * split from Phase 2: AI I/O and raw decode here, deterministic
 * validation there.
 *
 * Out of scope: candidate filtering (ResolveAnalysisTemplateAction),
 * confidence/ambiguity/required-field validation (ValidateColumnMappingAction),
 * persistence, AnalysisJob status updates.
 */
class MapAnalysisTemplateColumnsAction
{
    public function __construct(
        private readonly AiAnalysisClient $aiAnalysisClient,
    ) {}

    /**
     * Propose column mappings for one AnalysisJob's DataFile.
     *
     * If no semantic field has any type-filtered candidate at all, there
     * is nothing meaningful to map, so the AI is never called.
     *
     * @param string $prompt the user's additional prompt (may be '')
     * @param list<array{field: string, kind: string, label: string}> $templateFields
     * @param array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>> $columnCandidates semantic field => its type-filtered column candidates
     * @return list<array<string, mixed>> raw, untrusted {field, column, confidence} proposals
     * @throws InvalidArgumentException if the AI response is not valid JSON
     *                                   or does not contain a "mappings" array.
     */
    public function execute(string $prompt, array $templateFields, array $columnCandidates): array
    {
        $hasAnyCandidate = array_any($columnCandidates, static fn (array $candidates): bool => $candidates !== []);

        if (! $hasAnyCandidate) {
            return [];
        }

        $context = [
            'system_instruction' => $this->systemInstruction(),
            'user_prompt' => $prompt,
            'template_fields' => $templateFields,
            'column_candidates' => $columnCandidates,
        ];

        $rawResponse = $this->aiAnalysisClient->mapColumns($context);

        return $this->decode($rawResponse);
    }

    /**
     * The Column Mapping System Instruction.
     *
     * Distinct from BuildAnalysisContextAction's / PlanDerivedMetricsAction's
     * System Instructions: this one governs a narrow matching decision
     * (which real column, if any, corresponds to each semantic field),
     * not analysis or metric planning.
     */
    private function systemInstruction(): string
    {
        return <<<'TEXT'
            You are a column mapping assistant for a business data analysis system.

            Your only task is to map each semantic field in template_fields to the
            single best-fitting real CSV column, choosing only from that field's own
            column_candidates. You must not analyze or interpret the data itself here
            — that happens in later, separate steps.

            Rules:

            1. For each field in template_fields, choose at most one column from that
            field's entry in column_candidates. Never invent a column name that is not
            present in that field's own candidate list.

            2. Use the candidate's inferred_type and sample_values, together with the
            field's "kind" and "label", to judge which candidate best represents the
            business concept the field describes. Column names may be in Japanese,
            English, or any other language — judge by meaning, not by string pattern.

            3. Set "confidence" to "high" only when you are confident the mapping is
            correct. Set it to "low" when a candidate is plausible but you are not
            fully confident. Set "confidence" to "unmapped" (with "column" set to
            null) when no candidate is a good match, or when a field has no
            candidates at all.

            4. Do not force a mapping just to fill every field. Returning "unmapped"
            for a field is correct and expected when no candidate genuinely fits.

            5. Do not deliberately map two different fields to the same column unless
            you are genuinely uncertain which of them it belongs to. Prefer mapping
            each column to at most one field.

            6. Your confidence is not the final decision — the application
            independently validates every mapping before it is used.

            7. Return only the required structured output.
            TEXT;
    }

    /**
     * Decode the raw mapColumns() response into a list of untrusted
     * {field, column, confidence} proposal arrays. This only checks the
     * outer JSON shape (valid JSON, "mappings" is an array); it does not
     * validate any individual mapping's fields.
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
            throw new InvalidArgumentException('Column Mapping response is not valid JSON.', previous: $e);
        }

        if (! is_array($decoded) || ! isset($decoded['mappings']) || ! is_array($decoded['mappings'])) {
            throw new InvalidArgumentException('Column Mapping response must contain a "mappings" array.');
        }

        // Defensively drop any entry that is not itself an object/array —
        // ValidateColumnMappingAction still validates every remaining
        // field, this just avoids passing a scalar where an array is
        // expected.
        return array_values(array_filter($decoded['mappings'], 'is_array'));
    }
}
