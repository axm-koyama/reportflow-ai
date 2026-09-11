<?php

declare(strict_types=1);

namespace App\Actions\Diagnosis;

use InvalidArgumentException;
use JsonException;

/**
 * Converts a raw Diagnosis AI response into ReportFlow AI's Diagnosis
 * Result shape, and independently re-validates it against the exact
 * allowed_categories / supplied evidence identifiers this specific
 * request offered — the third of the "Structured Output + Laravel
 * post-validation + Evidence gating" three-layer defense described in
 * docs/product/DIAGNOSIS_ENGINE.md.
 *
 * Structured Output's dynamic enum (see
 * AiAnalysisClient::diagnosisResultSchema()) already constrains
 * category_key at the API level, but this action re-checks it anyway —
 * exactly as NormalizeAnalysisResultAction/ValidateColumnMappingAction
 * never trust a schema enum alone. The schema also declares
 * evidence_refs' "minItems: 1" as a first line of defense (see
 * diagnosisResultSchema()'s docblock), but a JSON Schema "enum"/"minItems"
 * cannot express "any subset of this specific request's identifiers", so
 * this action independently re-checks both that evidence_refs is
 * non-empty and that every element is one of suppliedEvidenceIds — the
 * *only* place both Evidence-grounding (every DiagnosisResult traces back
 * to at least one supplied Fact) and Hallucinated Evidence Rate (see
 * docs/product/DIAGNOSIS_ENGINE.md) are actually enforced.
 *
 * Any violation throws InvalidArgumentException. The caller
 * (RunDiagnosisForAnalysisJobAction) treats that exactly like any other
 * per-entity Diagnosis failure: soft-fail, no DiagnosisResult row for
 * that EvaluationFact, pipeline continues — see that class's docblock.
 *
 * Out of scope: calling the AI, DB writes, AnalysisJob status changes.
 */
class NormalizeDiagnosisResultAction
{
    /**
     * @param  list<string>  $allowedCategories  this exact request's
     *                                           Evidence-gated category set (see
     *                                           BuildDiagnosisEvidencePackageAction)
     * @param  list<string>  $suppliedEvidenceIds  every evidence identifier
     *                                             this exact request actually offered (trigger + supporting
     *                                             facts) — see RunDiagnosisForAnalysisJobAction
     * @return array{
     *     category_key: string,
     *     self_reported_confidence: float,
     *     rationale_summary: string,
     *     evidence_refs: list<string>,
     *     missing_evidence: list<string>,
     * }
     *
     * @throws InvalidArgumentException if the raw response is not valid
     *                                  JSON, does not match the expected shape, chooses a
     *                                  category_key outside allowed_categories, supplies an
     *                                  empty evidence_refs, references an evidence_ref outside
     *                                  suppliedEvidenceIds, or reports a self_reported_confidence
     *                                  outside [0, 1].
     */
    public function execute(string $rawResponse, array $allowedCategories, array $suppliedEvidenceIds): array
    {
        $decoded = $this->decode($rawResponse);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Diagnosis AI response must decode to a JSON object.');
        }

        $primary = $decoded['primary_diagnosis'] ?? null;

        if (! is_array($primary)) {
            throw new InvalidArgumentException('Diagnosis AI response is missing a valid "primary_diagnosis" object.');
        }

        $categoryKey = $this->requiredString($primary, 'category_key');

        if (! in_array($categoryKey, $allowedCategories, true)) {
            throw new InvalidArgumentException(
                "Diagnosis AI response \"category_key\" ({$categoryKey}) is not one of this request's allowed_categories.",
            );
        }

        $confidence = $this->confidence($primary);
        $rationaleSummary = $this->requiredNonEmptyString($primary, 'rationale_summary');
        $evidenceRefs = $this->stringArray($primary, 'evidence_refs');

        if ($evidenceRefs === []) {
            throw new InvalidArgumentException(
                'Diagnosis AI response "primary_diagnosis.evidence_refs" must contain at least one supplied evidence identifier.',
            );
        }

        foreach ($evidenceRefs as $evidenceRef) {
            if (! in_array($evidenceRef, $suppliedEvidenceIds, true)) {
                throw new InvalidArgumentException(
                    "Diagnosis AI response \"evidence_refs\" references an identifier ({$evidenceRef}) that was never supplied to it.",
                );
            }
        }

        $missingEvidence = $this->stringArray($primary, 'missing_evidence');

        return [
            'category_key' => $categoryKey,
            'self_reported_confidence' => $confidence,
            'rationale_summary' => $rationaleSummary,
            'evidence_refs' => $evidenceRefs,
            'missing_evidence' => $missingEvidence,
        ];
    }

    private function decode(string $rawResponse): mixed
    {
        try {
            return json_decode($rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Diagnosis AI response is not valid JSON.', previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $primary
     */
    private function requiredString(array $primary, string $field): string
    {
        if (! isset($primary[$field]) || ! is_string($primary[$field])) {
            throw new InvalidArgumentException("Diagnosis AI response \"primary_diagnosis\" is missing a valid \"{$field}\" string.");
        }

        return $primary[$field];
    }

    /**
     * @param  array<string, mixed>  $primary
     */
    private function requiredNonEmptyString(array $primary, string $field): string
    {
        $value = $this->requiredString($primary, $field);

        if (trim($value) === '') {
            throw new InvalidArgumentException("Diagnosis AI response \"primary_diagnosis.{$field}\" must not be empty.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $primary
     */
    private function confidence(array $primary): float
    {
        $value = $primary['self_reported_confidence'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('Diagnosis AI response "primary_diagnosis.self_reported_confidence" must be a number.');
        }

        $confidence = (float) $value;

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Diagnosis AI response "primary_diagnosis.self_reported_confidence" must be between 0 and 1.');
        }

        return $confidence;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @return list<string>
     */
    private function stringArray(array $primary, string $field): array
    {
        if (! isset($primary[$field]) || ! is_array($primary[$field])) {
            throw new InvalidArgumentException("Diagnosis AI response \"primary_diagnosis\" is missing a valid \"{$field}\" array.");
        }

        foreach ($primary[$field] as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Diagnosis AI response \"primary_diagnosis.{$field}\" must contain only strings.");
            }
        }

        return array_values($primary[$field]);
    }
}
