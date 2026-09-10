<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Actions\AnalysisJob\NormalizeAnalysisResultAction;
use App\Actions\Diagnosis\DetermineDiagnosisEligibilityAction;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Queries\ActionProposal\GetControlledActionViewDataQuery;
use InvalidArgumentException;

class BuildReportSnapshotAction
{
    public const string SCHEMA_VERSION = 'report_schema_v1.0';

    public function __construct(
        private readonly NormalizeAnalysisResultAction $normalizeAnalysisResultAction,
        private readonly DetermineDiagnosisEligibilityAction $determineDiagnosisEligibilityAction,
        private readonly GetControlledActionViewDataQuery $getControlledActionViewDataQuery,
    ) {}

    /** @return array<string, mixed> */
    public function execute(AnalysisJob $analysisJob): array
    {
        if ($analysisJob->status !== AnalysisJobStatus::Completed) {
            throw new InvalidArgumentException('Only a completed AnalysisJob can be used to build a Report snapshot.');
        }

        $detail = $analysisJob->analysisJobDetail;

        if ($detail === null || ! is_array($detail->result)) {
            throw new InvalidArgumentException('A completed AnalysisJob must have a normalized result.');
        }

        foreach (['highlights', 'metrics', 'tables', 'insights', 'recommendations'] as $section) {
            if (array_key_exists($section, $detail->result)
                && (! is_array($detail->result[$section]) || ! array_is_list($detail->result[$section]))) {
                throw new InvalidArgumentException("A Report result section [{$section}] must be a list.");
            }
        }

        $encodedResult = json_encode($detail->result, JSON_THROW_ON_ERROR);
        $analysis = $this->normalizeAnalysisResultAction->execute($encodedResult);

        if (trim($analysis['summary']) === '') {
            throw new InvalidArgumentException('A Report summary must be a non-empty string.');
        }

        $facts = $analysisJob->evaluationFacts->sortBy('evaluation_fact_id')->values();
        $evaluationApplicable = $analysisJob->template_key !== null
            && array_key_exists($analysisJob->template_key, config('evaluation_metrics', []));
        $diagnosis = [];
        $priority = [];

        foreach ($facts as $fact) {
            $eligible = $analysisJob->template_key !== null
                && $this->determineDiagnosisEligibilityAction->execute($fact, $analysisJob->template_key);

            if ($eligible) {
                $diagnosisResult = $fact->diagnosisResult;
                $diagnosis[] = $diagnosisResult === null
                    ? ['evaluation_fact_id' => $fact->evaluation_fact_id, 'entity_key' => $fact->entity_key, 'status' => 'unavailable']
                    : [
                        'evaluation_fact_id' => $fact->evaluation_fact_id,
                        'entity_key' => $fact->entity_key,
                        'status' => 'available',
                        'category_key' => $diagnosisResult->category_key,
                        'category_label' => config("diagnosis_categories.categories.{$diagnosisResult->category_key}.label") ?? $diagnosisResult->category_key,
                        'rationale' => $diagnosisResult->rationale_summary,
                        'evidence_refs' => $diagnosisResult->evidence_refs_json,
                        'missing_evidence' => $diagnosisResult->missing_evidence_json,
                        'model' => $diagnosisResult->model,
                        'prompt_version' => $diagnosisResult->prompt_version,
                    ];
            }

            $priorityResult = $fact->priorityResult;
            $priority[] = ! $eligible
                ? ['evaluation_fact_id' => $fact->evaluation_fact_id, 'entity_key' => $fact->entity_key, 'status' => 'not_eligible']
                : ($priorityResult === null
                    ? ['evaluation_fact_id' => $fact->evaluation_fact_id, 'entity_key' => $fact->entity_key, 'status' => 'unavailable']
                    : [
                        'evaluation_fact_id' => $fact->evaluation_fact_id,
                        'entity_key' => $fact->entity_key,
                        'status' => 'available',
                        'priority_band' => $priorityResult->priority_band,
                        'impact_score' => $priorityResult->impact_score,
                        'impact_value' => $priorityResult->impact_value,
                        'impact_total' => $priorityResult->impact_total,
                        'gap_raw_value' => $priorityResult->gap_raw_value,
                        'gap_reference_value' => $priorityResult->gap_reference_value,
                        'formula_version' => $priorityResult->formula_version,
                        'computed_at' => $priorityResult->computed_at?->toISOString(),
                    ]);
        }

        $controlled = $this->getControlledActionViewDataQuery->execute($analysisJob);
        $proposals = $controlled['proposals']->sortBy('action_proposal_id')->values()->map(fn ($proposal): array => [
            'action_proposal_id' => $proposal->action_proposal_id,
            'catalog_key' => $proposal->catalog_key,
            'catalog_label' => config("action_catalog.{$proposal->catalog_key}.label") ?? $proposal->catalog_key,
            'title' => $proposal->title,
            'entity_key' => $proposal->evaluationFact->entity_key,
            'metric_key' => $proposal->evaluationFact->metric_key,
            'metric_label' => $this->metricLabel($proposal->evaluationFact->metric_key),
            'rationale' => $proposal->rationale_summary,
            'priority_band' => $proposal->priorityResult->priority_band,
            'impact_score' => $proposal->priorityResult->impact_score,
            'gap_raw_value' => $proposal->priorityResult->gap_raw_value,
            'selected_checks' => $proposal->selected_checks_json,
            'evidence_refs' => $proposal->evidence_refs_json,
            'missing_evidence' => $proposal->missing_evidence_json,
            'model' => $proposal->model,
            'prompt_version' => $proposal->prompt_version,
            'contract_version' => $proposal->contract_version,
            'proposed_at' => $proposal->proposed_at?->toISOString(),
            'advisory_label' => 'Advisory only — not executed',
        ])->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'title' => $analysisJob->title,
                'template_key' => $analysisJob->template_key,
                'display_mode' => $this->displayMode($analysisJob->template_key),
                'data_file_name' => $analysisJob->dataFile->original_name,
                'completed_at' => $detail->completed_at?->toISOString(),
                'recovered_from_analysis_job_id' => $analysisJob->recovered_from_analysis_job_id,
            ],
            'analysis' => $analysis,
            'evaluation' => [
                'applicable' => $evaluationApplicable,
                'rows' => $facts->map(fn ($fact): array => [
                    'evaluation_fact_id' => $fact->evaluation_fact_id,
                    'entity_type' => $fact->entity_type,
                    'entity_key' => $fact->entity_key,
                    'metric_key' => $fact->metric_key,
                    'metric_label' => $this->metricLabel($fact->metric_key),
                    'metric_value' => $fact->metric_value,
                    'display_baseline_value' => $fact->display_baseline_value,
                    'delta_absolute' => $fact->delta_absolute,
                    'direction' => $fact->direction,
                    'evaluation_level' => $fact->evaluation_level,
                    'rule_version' => $fact->rule_version,
                    'computed_at' => $fact->computed_at?->toISOString(),
                ])->all(),
            ],
            'diagnosis' => ['rows' => $diagnosis],
            'priority' => ['rows' => $priority],
            'controlled_actions' => [
                'applicable' => $controlled['applicable'],
                'eligible_count' => $controlled['eligible_count'],
                'proposals' => $proposals,
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    private function displayMode(?string $templateKey): string
    {
        if ($templateKey === null) {
            return 'Free Analysis';
        }

        return config("analysis_templates.{$templateKey}.name") ?? "Unknown template ({$templateKey})";
    }

    private function metricLabel(string $metricKey): string
    {
        return ucfirst(str_replace('_', ' ', $metricKey));
    }
}
