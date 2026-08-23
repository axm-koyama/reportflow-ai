<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\DataProfiling\MetricAggregationAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Executes a single attempt of one AnalysisJob run.
 *
 * Coordinates the full ReportFlow AI pipeline for one execution attempt:
 *
 *   markProcessing()
 *   -> DataProfilingAction          (DataFile CSV -> Data Profile)
 *   -> MetricAggregationAction      (DataFile CSV + Data Profile -> Aggregated Metrics)
 *   -> [template_key set only]
 *        effective_column_mapping already confirmed (Phase 3-C resume)?
 *          Yes -> skip straight to building analysisTemplate/columnMapping
 *                 from it (no AI call at all)
 *          No  -> column_mapping already recorded (Phase 3-C retry recovery,
 *                 see below)?
 *                   Yes -> reuse it as the validated AI mapping (no AI call)
 *                   No  -> ResolveAnalysisTemplateAction
 *                            -> MapAnalysisTemplateColumnsAction -> AiAnalysisClient::mapColumns() [AI call]
 *                            -> ValidateColumnMappingAction (deterministic; no longer throws — see below)
 *                          recordColumnMapping() (write-once; always, even when required fields are missing)
 *                 -> ResolveEffectiveColumnMappingAction (manualOverrides=[]) re-validates
 *                    the validated AI mapping (whichever source it came from) and reports
 *                    missing required fields/groups
 *                    Yes (missing) -> markAwaitingMappingConfirmation(), return (Queue job ends normally)
 *                    No            -> persist effective_column_mapping (source: "ai" for every field)
 *   -> PlanDerivedMetricsAction     (prompt + Aggregated Metrics [+ analysisTemplate/columnMapping] -> proposed CalculationDefinitions) [AI call]
 *   -> CalculateDerivedMetricsAction (CalculationDefinitions + Aggregated Metrics -> Derived Metrics)
 *   -> BuildAnalysisContextAction   (prompt + Data Profile + Aggregated Metrics + Derived Metrics [+ analysisTemplate/columnMapping] -> AI Context)
 *   -> AiAnalysisClient::analyze()  (AI Context -> raw structured output) [AI call]
 *   -> NormalizeAnalysisResultAction (raw output -> canonical result)
 *   -> markCompleted()
 *
 * A free-form AnalysisJob (template_key === null) makes exactly the same
 * two AI calls as before Phase 3-A (PlanDerivedMetricsAction, then
 * AiAnalysisClient::analyze()) — the entire Template-related block is
 * skipped outright, not merely passed empty values, so free-form analysis
 * is byte-for-byte the pre-Template code path, and can never reach
 * AwaitingMappingConfirmation. A Template-based AnalysisJob normally makes
 * 3 AI calls on a successful execution path: Column Mapping, Planning, and
 * Analyze. Column Mapping is called at most once across the AnalysisJob's
 * *entire* lifetime once column_mapping has been persisted, regardless of
 * whether it went through Mapping confirmation or not — a confirmed
 * effective_column_mapping (or even just a persisted column_mapping; see
 * "retry recovery" below) means Mapping AI is never called again. Planning
 * and Analyze, however, are not similarly capped: a technical failure that
 * triggers a Laravel Queue retry redoes Planning and/or Analyze from
 * scratch (see below), so the lifetime total can exceed 3 AI calls.
 * CalculateDerivedMetricsAction and ValidateColumnMappingAction never call
 * the AI — see docs/product/DERIVED_METRICS.md and
 * docs/product/ANALYSIS_TEMPLATE_MODULE.md.
 *
 * The DataFile itself (and its CSV content) is only ever touched by
 * DataProfilingAction and MetricAggregationAction. AiAnalysisClient
 * receives the AI Context only (system_instruction / user_prompt /
 * data_profile / aggregated_metrics / derived_metrics / analysis_template /
 * column_mapping / output_schema) — never the DataFile, its stored_path,
 * or raw CSV content.
 *
 * Phase 3-C: required-field handling. ResolveAnalysisTemplateAction no
 * longer throws when a required field/group cannot be resolved — it
 * always returns successfully and reports
 * missing_required_fields/missing_required_field_groups instead. This
 * action alone decides what that means: pause for user confirmation
 * (markAwaitingMappingConfirmation()), not fail. See
 * docs/product/MAPPING_CONTROL.md. Consequently, a Template AnalysisJob
 * whose AI mapping cannot resolve a required field is never marked
 * Failed on that account alone — only a genuine technical failure (AI
 * provider error, malformed response, Planning/Calculation/Analyze
 * failure) still propagates as an exception for Laravel Queue's retry
 * mechanism, exactly as before.
 *
 * Phase 3-C: idempotency guards. This action defensively no-ops for an
 * AnalysisJob that is already Completed, Failed, or
 * AwaitingMappingConfirmation before doing anything else (see
 * docs/product/MAPPING_CONTROL.md "Queue no-op guards") — a stale or
 * duplicate Queue message must never re-run Mapping AI against a Job
 * already waiting for the user, nor re-process an already-finished one.
 * A Pending or (already) Processing AnalysisJob proceeds normally;
 * markProcessing() itself is idempotent for an already-Processing Job
 * (a genuine Queue retry of the same attempt).
 *
 * Once column_mapping has been persisted for a Template AnalysisJob —
 * which happens on its very first successful Mapping AI call, regardless
 * of whether required fields were satisfied — Mapping AI is never called
 * again for that AnalysisJob, for the rest of its lifetime. This refines
 * the older "a retried attempt redoes every AI call from scratch" rule
 * specifically for Column Mapping, once resolved:
 * - if effective_column_mapping is also already set, it is reused as-is
 *   (the confirmed Fact; see the resume path above)
 * - if only column_mapping is set (e.g. a retry after a technical failure
 *   struck between recordColumnMapping() and the Awaiting/Effective-Mapping
 *   write — see "retry recovery" above), that stored, already-validated
 *   mapping is reused as the input to re-validation instead of asking the
 *   AI again — see docs/product/MAPPING_CONTROL.md "Retry Recovery"
 * Planning and Analyze are still redone from scratch on every
 * attempt/resume, as before — no partial Planning/Calculation/Analyze
 * state is ever kept between attempts.
 *
 * AI provider I/O is external network I/O and is intentionally kept outside
 * of any database transaction; only the individual status-update steps are
 * transactional (see UpdateAnalysisJobAction).
 */
class ExecuteAnalysisJobAction
{
    public function __construct(
        private readonly UpdateAnalysisJobAction $updateAnalysisJobAction,
        private readonly DataProfilingAction $dataProfilingAction,
        private readonly MetricAggregationAction $metricAggregationAction,
        private readonly ResolveAnalysisTemplateAction $resolveAnalysisTemplateAction,
        private readonly ResolveEffectiveColumnMappingAction $resolveEffectiveColumnMappingAction,
        private readonly BuildAnalysisTemplateColumnCandidatesAction $buildAnalysisTemplateColumnCandidatesAction,
        private readonly FilterRecommendedDerivedMetricsAction $filterRecommendedDerivedMetricsAction,
        private readonly PlanDerivedMetricsAction $planDerivedMetricsAction,
        private readonly CalculateDerivedMetricsAction $calculateDerivedMetricsAction,
        private readonly BuildAnalysisContextAction $buildAnalysisContextAction,
        private readonly AiAnalysisClient $aiAnalysisClient,
        private readonly NormalizeAnalysisResultAction $normalizeAnalysisResultAction,
    ) {}

    /**
     * Run one AnalysisJob execution attempt.
     *
     * Flow:
     *   pending|processing -> processing -> completed
     *   pending|processing -> processing -> awaiting_mapping_confirmation (Template job, required field(s) missing)
     *
     * markProcessing() is retry-safe, so re-entering this method for a
     * retried attempt (AnalysisJob already Processing) is safe. An
     * AnalysisJob already Completed, Failed, or
     * AwaitingMappingConfirmation is left untouched (see class docblock).
     *
     * @param int $analysisJobId
     * @return void
     * @throws ModelNotFoundException if no AnalysisJob exists for the given ID.
     * @throws \Throwable propagated as-is from markProcessing(), Data
     *                     Profiling, Metric Aggregation, Analysis Template
     *                     resolution, Metric Planning, Derived Metric
     *                     calculation, AI Context building, the final
     *                     analysis AI call, normalization, or
     *                     markCompleted(), for the Laravel Queue worker to
     *                     retry or ultimately fail. A missing required
     *                     Template field is never one of these — see the
     *                     class docblock.
     */
    public function execute(int $analysisJobId): void
    {
        $analysisJob = AnalysisJob::query()
            ->with(['dataFile', 'analysisJobDetail'])
            ->findOrFail($analysisJobId);

        if (in_array($analysisJob->status, [
            AnalysisJobStatus::Completed,
            AnalysisJobStatus::Failed,
            AnalysisJobStatus::AwaitingMappingConfirmation,
        ], true)) {
            // A stale/duplicate Queue message for a Job that already
            // reached a terminal or waiting state. Re-running here would
            // at best waste an AI call and at worst (Completed/Failed)
            // throw inside markProcessing() and manufacture a spurious
            // failed_jobs entry for a Job that isn't actually failing.
            // For AwaitingMappingConfirmation specifically, this guard
            // prevents an unnecessary Mapping AI re-call in the first
            // place and protects the audit record / user-facing
            // confirmation state while it awaits review — column_mapping
            // is write-once and reused rather than overwritten even if
            // Mapping AI were re-called (see "Retry Recovery" below), but
            // this guard means it never needs to be. See
            // docs/product/MAPPING_CONTROL.md "Queue no-op guards".
            Log::info('ExecuteAnalysisJobAction: skipping — AnalysisJob is not in a runnable state.', [
                'analysis_job_id' => $analysisJobId,
                'status' => $analysisJob->status->name,
            ]);

            return;
        }

        $this->updateAnalysisJobAction->markProcessing($analysisJob);

        $prompt = $analysisJob->analysisJobDetail->prompt;

        $dataProfile = $this->dataProfilingAction->execute($analysisJob->dataFile);

        $aggregatedMetrics = $this->metricAggregationAction->execute($analysisJob->dataFile, $dataProfile);

        $analysisTemplate = null;
        $columnMapping = [];

        if ($analysisJob->template_key !== null) {
            $detail = $analysisJob->analysisJobDetail;
            $template = config("analysis_templates.{$analysisJob->template_key}");

            if ($detail->effective_column_mapping !== null) {
                // Resume path (or a same-attempt retry past this point):
                // the Effective Mapping is already a confirmed Fact.
                // Mapping AI is never called again.
                $columnMapping = $this->resolveAnalysisTemplateAction->simpleMappingForAi($detail->effective_column_mapping);

                $analysisTemplate = [
                    'name' => $template['name'],
                    'instruction' => $template['instruction'],
                    'recommended_derived_metrics' => $this->filterRecommendedDerivedMetricsAction->execute(
                        $template['recommended_derived_metrics'] ?? [],
                        $detail->effective_column_mapping,
                    ),
                ];
            } else {
                $fields = $template['fields'] ?? [];
                $requiredFields = $template['required_fields'] ?? [];
                $requiredFieldGroups = $template['required_field_groups'] ?? [];

                if ($detail->column_mapping !== null) {
                    // Retry recovery: Mapping AI already ran and its
                    // validated result was recorded on a previous attempt
                    // (e.g. one that failed later, at Planning/Analyze, or
                    // was interrupted before the Awaiting/Effective-Mapping
                    // write completed). column_mapping is a write-once
                    // audit record specifically so this exact situation
                    // can reuse it instead of calling Mapping AI again —
                    // see docs/product/MAPPING_CONTROL.md "Retry Recovery".
                    $validatedAiMapping = $detail->column_mapping;
                } else {
                    // First attempt for this AnalysisJob: call Mapping AI
                    // once and record its result permanently.
                    $resolved = $this->resolveAnalysisTemplateAction->execute(
                        $analysisJob->template_key,
                        $prompt,
                        $dataProfile,
                    );

                    $this->updateAnalysisJobAction->recordColumnMapping(
                        $analysisJob,
                        $resolved['column_mapping_for_storage'],
                    );

                    $validatedAiMapping = $resolved['column_mapping_for_storage'];
                }

                // Whether $validatedAiMapping just came from Mapping AI or
                // was reused from a previous attempt, the rest is
                // identical: re-validate it (with no manual overrides) to
                // learn both whether required fields are satisfied and,
                // if so, the resulting Effective Mapping — a single,
                // uniform path shared with the manual-confirmation flow
                // (see ResolveEffectiveColumnMappingAction's docblock).
                $columnCandidates = $this->buildAnalysisTemplateColumnCandidatesAction->execute($fields, $dataProfile);

                $effective = $this->resolveEffectiveColumnMappingAction->execute(
                    $fields,
                    $columnCandidates,
                    $validatedAiMapping,
                    [],
                    $requiredFields,
                    $requiredFieldGroups,
                );

                if ($effective['missing_required_fields'] !== [] || $effective['missing_required_field_groups'] !== []) {
                    $this->updateAnalysisJobAction->markAwaitingMappingConfirmation($analysisJob);

                    return;
                }

                $this->updateAnalysisJobAction->recordEffectiveMapping($analysisJob, $effective['effective_mapping']);

                $columnMapping = $this->resolveAnalysisTemplateAction->simpleMappingForAi($effective['effective_mapping']);

                $analysisTemplate = [
                    'name' => $template['name'],
                    'instruction' => $template['instruction'],
                    'recommended_derived_metrics' => $this->filterRecommendedDerivedMetricsAction->execute(
                        $template['recommended_derived_metrics'] ?? [],
                        $effective['effective_mapping'],
                    ),
                ];
            }
        }

        $proposedDefinitions = $this->planDerivedMetricsAction->execute(
            $prompt,
            $aggregatedMetrics,
            $analysisTemplate,
            $columnMapping,
        );

        $derivedMetrics = $this->calculateDerivedMetricsAction->execute($proposedDefinitions, $aggregatedMetrics);

        $context = $this->buildAnalysisContextAction->execute(
            $prompt,
            $dataProfile,
            $aggregatedMetrics,
            $derivedMetrics,
            $analysisTemplate,
            $columnMapping,
        );

        $rawResponse = $this->aiAnalysisClient->analyze($context);

        $result = $this->normalizeAnalysisResultAction->execute($rawResponse);

        $this->updateAnalysisJobAction->markCompleted($analysisJob, $rawResponse, $result);
    }
}
