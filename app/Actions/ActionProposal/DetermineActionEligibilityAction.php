<?php

declare(strict_types=1);

namespace App\Actions\ActionProposal;

use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use App\Models\PriorityResult;

class DetermineActionEligibilityAction
{
    /**
     * @return array{eligible: true, catalog_key: string}|array{eligible: false, reason: string}
     */
    public function execute(EvaluationFact $fact, AnalysisJob $analysisJob): array
    {
        if ($analysisJob->template_key === null
            || ! array_key_exists($analysisJob->template_key, config('evaluation_metrics', []))) {
            return $this->ineligible('not_decision_enabled');
        }

        $priority = $fact->priorityResult;

        if (! $priority instanceof PriorityResult
            || $priority->evaluation_fact_id !== $fact->evaluation_fact_id) {
            return $this->ineligible('missing_priority');
        }

        $diagnosis = $fact->diagnosisResult;

        if (! $diagnosis instanceof DiagnosisResult
            || $diagnosis->evaluation_fact_id !== $fact->evaluation_fact_id) {
            return $this->ineligible('missing_diagnosis');
        }

        $matchingKeys = [];
        $disabledMatch = false;

        foreach (config('action_catalog', []) as $catalogKey => $entry) {
            if (! is_string($catalogKey) || ! is_array($entry)) {
                continue;
            }

            $categories = $entry['allowed_diagnosis_categories'] ?? [];

            if (! is_array($categories) || ! in_array($diagnosis->category_key, $categories, true)) {
                continue;
            }

            if (($entry['enabled'] ?? false) !== true) {
                $disabledMatch = true;

                continue;
            }

            $matchingKeys[] = $catalogKey;
        }

        if ($matchingKeys === []) {
            return $this->ineligible($disabledMatch ? 'catalog_disabled' : 'unsupported_diagnosis');
        }

        if (count($matchingKeys) !== 1) {
            return $this->ineligible('unsupported_diagnosis');
        }

        if (! $this->hasRequiredEvidence($fact, $diagnosis, $priority, $matchingKeys[0])) {
            return $this->ineligible('missing_required_evidence');
        }

        return ['eligible' => true, 'catalog_key' => $matchingKeys[0]];
    }

    private function hasRequiredEvidence(
        EvaluationFact $fact,
        DiagnosisResult $diagnosis,
        PriorityResult $priority,
        string $catalogKey,
    ): bool {
        $analysisJobId = $fact->analysis_job_id;

        if ($fact->evaluation_fact_id === null
            || $diagnosis->diagnosis_result_id === null
            || $priority->priority_result_id === null
            || $analysisJobId !== $diagnosis->analysis_job_id
            || $analysisJobId !== $priority->analysis_job_id
            || trim($diagnosis->rationale_summary) === ''
            || ! is_array($diagnosis->evidence_refs_json)
            || $diagnosis->evidence_refs_json === []) {
            return false;
        }

        if ($catalogKey !== 'collect_explanatory_evidence') {
            return true;
        }

        if (! is_array($diagnosis->missing_evidence_json)) {
            return false;
        }

        foreach ($diagnosis->missing_evidence_json as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{eligible: false, reason: string}
     */
    private function ineligible(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason];
    }
}
