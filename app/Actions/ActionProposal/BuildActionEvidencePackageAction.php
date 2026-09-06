<?php

declare(strict_types=1);

namespace App\Actions\ActionProposal;

use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use InvalidArgumentException;

class BuildActionEvidencePackageAction
{
    private const string CONTRACT_VERSION = 'action_contract_v1';

    /**
     * @return array<string, mixed>
     */
    public function execute(AnalysisJob $analysisJob, EvaluationFact $fact, string $catalogKey): array
    {
        $diagnosis = $fact->diagnosisResult;
        $priority = $fact->priorityResult;
        $catalog = config("action_catalog.{$catalogKey}");

        if ($diagnosis === null || $priority === null || ! is_array($catalog)) {
            throw new InvalidArgumentException('Action evidence package requires a valid catalog entry, DiagnosisResult, and PriorityResult.');
        }

        $allowedEvidenceRefs = [
            'evaluation_fact:'.$fact->evaluation_fact_id,
            'diagnosis_result:'.$diagnosis->diagnosis_result_id,
            'priority_result:'.$priority->priority_result_id,
        ];

        return [
            'analysis_job_id' => $analysisJob->analysis_job_id,
            'action_catalog_key' => $catalogKey,
            'trigger_fact' => [
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'metric_key' => $fact->metric_key,
                'entity_key' => $fact->entity_key,
                'evaluation_level' => $fact->evaluation_level,
                'direction' => $fact->direction,
            ],
            'diagnosis' => [
                'diagnosis_result_id' => $diagnosis->diagnosis_result_id,
                'category_key' => $diagnosis->category_key,
                'rationale_summary' => $diagnosis->rationale_summary,
                'evidence_refs' => array_values($diagnosis->evidence_refs_json),
                'missing_evidence' => array_values($diagnosis->missing_evidence_json),
            ],
            'priority' => [
                'priority_result_id' => $priority->priority_result_id,
                'priority_band' => $priority->priority_band,
                'priority_score' => $priority->priority_score,
                'formula_version' => $priority->formula_version,
            ],
            'allowed_checks' => array_values($catalog['allowed_checks']),
            'allowed_evidence_refs' => $allowedEvidenceRefs,
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }
}
