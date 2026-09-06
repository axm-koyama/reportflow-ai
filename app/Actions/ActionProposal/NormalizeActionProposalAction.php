<?php

declare(strict_types=1);

namespace App\Actions\ActionProposal;

use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use InvalidArgumentException;
use JsonException;

class NormalizeActionProposalAction
{
    private const array REQUIRED_KEYS = [
        'catalog_key',
        'title',
        'rationale_summary',
        'selected_checks',
        'evidence_refs',
        'missing_evidence',
    ];

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function execute(
        string $rawResponse,
        array $package,
        AnalysisJob $analysisJob,
        EvaluationFact $fact,
    ): array {
        try {
            $decoded = json_decode($rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Action AI response is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Action AI response must decode to a JSON object.');
        }

        $keys = array_keys($decoded);
        sort($keys);
        $requiredKeys = self::REQUIRED_KEYS;
        sort($requiredKeys);

        if ($keys !== $requiredKeys) {
            throw new InvalidArgumentException('Action AI response must contain exactly the supported fields.');
        }

        $catalogKey = $this->requiredString($decoded, 'catalog_key');

        if ($catalogKey !== ($package['action_catalog_key'] ?? null)) {
            throw new InvalidArgumentException('Action AI response catalog_key does not match the eligible catalog entry.');
        }

        $catalog = config("action_catalog.{$catalogKey}");

        if (! is_array($catalog)) {
            throw new InvalidArgumentException('Action AI response references an unknown catalog entry.');
        }

        $this->validateChain($package, $analysisJob, $fact);

        $title = trim($this->requiredString($decoded, 'title'));
        $rationale = trim($this->requiredString($decoded, 'rationale_summary'));

        if ($title === '' || mb_strlen($title) > $catalog['title_max_characters']) {
            throw new InvalidArgumentException('Action AI response title is empty or exceeds the configured length.');
        }

        if ($rationale === '' || mb_strlen($rationale) > $catalog['rationale_max_characters']) {
            throw new InvalidArgumentException('Action AI response rationale_summary is empty or exceeds the configured length.');
        }

        $selectedChecks = $this->stringList($decoded, 'selected_checks');
        $allowedChecks = $package['allowed_checks'] ?? [];
        $this->assertUniqueSubset($selectedChecks, $allowedChecks, 'selected_checks');

        $evidenceRefs = $this->stringList($decoded, 'evidence_refs');

        if ($evidenceRefs === []) {
            throw new InvalidArgumentException('Action AI response evidence_refs must not be empty.');
        }

        $this->assertUniqueSubset($evidenceRefs, $package['allowed_evidence_refs'] ?? [], 'evidence_refs');

        $missingEvidence = $this->stringList($decoded, 'missing_evidence');
        $this->assertUniqueSubset($missingEvidence, $package['diagnosis']['missing_evidence'] ?? [], 'missing_evidence');

        if ($catalogKey === 'verify_measurement_consistency' && $selectedChecks === []) {
            throw new InvalidArgumentException('Measurement consistency proposals must select at least one allowed check.');
        }

        if ($catalogKey === 'collect_explanatory_evidence'
            && ($selectedChecks !== [] || $missingEvidence === [])) {
            throw new InvalidArgumentException('Evidence collection proposals require missing evidence and no selected checks.');
        }

        return [
            'catalog_key' => $catalogKey,
            'title' => $title,
            'rationale_summary' => $rationale,
            'selected_checks' => $selectedChecks,
            'evidence_refs' => $evidenceRefs,
            'missing_evidence' => $missingEvidence,
        ];
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        if (! isset($data[$key]) || ! is_string($data[$key])) {
            throw new InvalidArgumentException("Action AI response {$key} must be a string.");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function stringList(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key]) || ! array_is_list($data[$key])) {
            throw new InvalidArgumentException("Action AI response {$key} must be a list.");
        }

        foreach ($data[$key] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Action AI response {$key} must contain non-empty strings.");
            }
        }

        return array_values($data[$key]);
    }

    /**
     * @param  list<string>  $values
     */
    private function assertUniqueSubset(array $values, mixed $allowed, string $field): void
    {
        if (! is_array($allowed) || count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException("Action AI response {$field} contains duplicates or has no valid allow-list.");
        }

        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                throw new InvalidArgumentException("Action AI response {$field} contains an unsupported value.");
            }
        }
    }

    /** @param array<string, mixed> $package */
    private function validateChain(array $package, AnalysisJob $analysisJob, EvaluationFact $fact): void
    {
        $diagnosis = $fact->diagnosisResult;
        $priority = $fact->priorityResult;

        if (($package['analysis_job_id'] ?? null) !== $analysisJob->analysis_job_id
            || ($package['trigger_fact']['evaluation_fact_id'] ?? null) !== $fact->evaluation_fact_id
            || $fact->analysis_job_id !== $analysisJob->analysis_job_id
            || $diagnosis === null
            || $priority === null
            || ($package['diagnosis']['diagnosis_result_id'] ?? null) !== $diagnosis->diagnosis_result_id
            || ($package['priority']['priority_result_id'] ?? null) !== $priority->priority_result_id
            || $diagnosis->analysis_job_id !== $analysisJob->analysis_job_id
            || $priority->analysis_job_id !== $analysisJob->analysis_job_id) {
            throw new InvalidArgumentException('Action Evidence Package does not match the current AnalysisJob and EvaluationFact chain.');
        }
    }
}
