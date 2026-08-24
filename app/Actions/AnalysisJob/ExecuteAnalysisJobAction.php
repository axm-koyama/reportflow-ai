<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use App\Actions\DataProfiling\DataProfilingAction;
use App\Actions\DataProfiling\MetricAggregationAction;
use App\Actions\Diagnosis\RunDiagnosisForAnalysisJobAction;
use App\Actions\Evaluation\EvaluateAnalysisJobAction;
use App\AI\AiAnalysisClient;
use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *   -> EvaluateAnalysisJobAction    (Phase 4-A: Aggregated Metrics + effective_column_mapping
 *                                    + config/evaluation_metrics.php -> persisted EvaluationFact rows;
 *                                    zero AI calls, soft-fails on technical exception — see below
 *                                    and docs/product/EVALUATION_ENGINE.md. On that soft-fail path,
 *                                    any EvaluationFact rows a previous successful attempt left
 *                                    behind are cleared via clearForAnalysisJob() so this attempt
 *                                    never leaves stale facts behind while continuing to Analyze)
 *   -> BuildAnalysisContextAction   (prompt + Data Profile + Aggregated Metrics + Derived Metrics [+ analysisTemplate/columnMapping] -> AI Context;
 *                                    Phase 4-B: $decisionEnabled appends the descriptive-only instruction block — see below)
 *   -> AiAnalysisClient::analyze()  (AI Context -> raw structured output) [AI call]
 *   -> NormalizeAnalysisResultAction (raw output -> canonical result)
 *   -> RunDiagnosisForAnalysisJobAction (Phase 4-B: EvaluationFact rows -> at most one
 *                                    DiagnosisResult per eligible fact; [0..N AI calls, N = eligible
 *                                    fact count]; soft-fails per EvaluationFact internally and, for a
 *                                    genuine orchestration-level failure, at this call site too — see
 *                                    below and docs/product/DIAGNOSIS_ENGINE.md. Runs before
 *                                    markCompleted(), never after)
 *   -> markCompleted()
 *
 * EvaluateAnalysisJobAction never changes the AI call count above (it
 * makes none of its own) and never feeds EvaluationFact data into
 * BuildAnalysisContextAction / the final Analyze call — Phase 4-A
 * evaluates independently of, and does not yet influence, the existing
 * AI analysis (see docs/product/EVALUATION_ENGINE.md "Existing Final AI
 * Analysisとの関係"). It runs for every Template AnalysisJob whose
 * Effective Mapping is confirmed at this point in this same attempt
 * (auto-confident or resumed-after-manual-confirmation — both paths
 * converge before Planning, see above); it never runs for free-form
 * analysis (template_key === null), whose Template-related block
 * (including this step) is skipped outright, same as everything else in
 * that block.
 *
 * A free-form AnalysisJob (template_key === null) makes exactly the same
 * two AI calls as before Phase 3-A (PlanDerivedMetricsAction, then
 * AiAnalysisClient::analyze()) — the entire Template-related block is
 * skipped outright, not merely passed empty values, so free-form analysis
 * is byte-for-byte the pre-Template code path, and can never reach
 * AwaitingMappingConfirmation. A Template-based AnalysisJob normally makes
 * 3 AI calls on a successful execution path before Phase 4-B: Column
 * Mapping, Planning, and Analyze. Phase 4-B adds Diagnosis on top of that
 * baseline — not a fixed +1, but +1 per Diagnosis-eligible EvaluationFact
 * (0 for a Template without any config/evaluation_metrics.php entry, and
 * 0 whenever every EvaluationFact is insufficient_data/low/favorable —
 * see docs/product/DIAGNOSIS_ENGINE.md "AI call count"). Column Mapping is
 * called at most once across the AnalysisJob's *entire* lifetime once
 * column_mapping has been persisted, regardless of whether it went through
 * Mapping confirmation or not — a confirmed effective_column_mapping (or
 * even just a persisted column_mapping; see "retry recovery" below) means
 * Mapping AI is never called again. Planning and Analyze, however, are not
 * similarly capped: a technical failure that triggers a Laravel Queue
 * retry redoes Planning and/or Analyze from scratch (see below), so the
 * lifetime total can exceed 3 (+ Diagnosis) AI calls. Diagnosis itself
 * never triggers a Queue retry on its own account (see
 * RunDiagnosisForAnalysisJobAction's docblock) — only a Queue retry
 * triggered by *something else* (e.g. Planning or Analyze failing) redoes
 * Diagnosis, as a side effect of redoing the whole attempt.
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
        private readonly EvaluateAnalysisJobAction $evaluateAnalysisJobAction,
        private readonly BuildAnalysisContextAction $buildAnalysisContextAction,
        private readonly AiAnalysisClient $aiAnalysisClient,
        private readonly NormalizeAnalysisResultAction $normalizeAnalysisResultAction,
        private readonly RunDiagnosisForAnalysisJobAction $runDiagnosisForAnalysisJobAction,
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

        // Phase 4-A: Deterministic Evaluation Engine. Runs after Derived
        // Metrics, before the final Analyze call, using the exact same
        // in-memory $aggregatedMetrics already computed above (never
        // derived_metrics, never a fresh CSV read/aggregation — see
        // EvaluateAnalysisJobAction's docblock and
        // docs/product/EVALUATION_ENGINE.md). Soft-fail by design: a
        // technical failure here (a bug, an unexpected aggregated_metrics
        // shape, ...) must never turn an otherwise-successful AI analysis
        // into a Failed AnalysisJob — Evaluation is independently
        // verified in Phase 4-A and not yet relied on by anything
        // downstream (see docs/product/EVALUATION_ENGINE.md "Soft-fail").
        // A *business* outcome such as insufficient_data, a zero
        // denominator, or an unmapped field is never an exception in the
        // first place (see EvaluateRateMetricAction /
        // ResolveEvaluationMetricDefinitionsAction) — only a genuine bug
        // reaches this catch.
        try {
            $this->evaluateAnalysisJobAction->execute($analysisJob, $aggregatedMetrics);
        } catch (Throwable $evaluationException) {
            Log::error('EvaluateAnalysisJobAction: technical failure — continuing the analysis pipeline without Evaluation Facts.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'template_key' => $analysisJob->template_key,
                'exception_class' => $evaluationException::class,
                'exception_message' => $evaluationException->getMessage(),
            ]);

            // "delete + recreate" (execute()'s own idempotency guarantee)
            // never ran for this attempt, so any EvaluationFact rows a
            // *previous, successful* attempt left behind are still
            // sitting there describing this AnalysisJob as if this
            // attempt had evaluated it too. Clear them rather than let
            // stale facts silently survive a technical failure — see
            // EvaluateAnalysisJobAction::clearForAnalysisJob()'s
            // docblock and docs/product/EVALUATION_ENGINE.md
            // "Soft-fail". Persistence stays owned by
            // EvaluateAnalysisJobAction; this never touches the
            // EvaluationFact model directly.
            try {
                $this->evaluateAnalysisJobAction->clearForAnalysisJob($analysisJob);
            } catch (Throwable $cleanupException) {
                // Never let a cleanup failure mask the original
                // Evaluation exception above — both are logged
                // independently, and this stays inside the outer
                // soft-fail: the pipeline still continues to Analyze.
                Log::critical('EvaluateAnalysisJobAction: failed to clear stale EvaluationFacts after a technical failure.', [
                    'analysis_job_id' => $analysisJob->analysis_job_id,
                    'template_key' => $analysisJob->template_key,
                    'original_exception_class' => $evaluationException::class,
                    'original_exception_message' => $evaluationException->getMessage(),
                    'cleanup_exception_class' => $cleanupException::class,
                    'cleanup_exception_message' => $cleanupException->getMessage(),
                ]);
            }
        }

        // Phase 4-B: "Decision-enabled" iff this Template has a
        // config/evaluation_metrics.php entry — never a per-template_key
        // hardcode. See docs/product/DIAGNOSIS_ENGINE.md "Decision-enabled
        // Analysisの定義". Free Analysis (template_key === null) and a
        // Template without an entry (e.g. sales_analysis today) are both
        // false, leaving their Final Analyze System Instruction unchanged.
        $decisionEnabled = $analysisJob->template_key !== null
            && array_key_exists($analysisJob->template_key, config('evaluation_metrics', []));

        $context = $this->buildAnalysisContextAction->execute(
            $prompt,
            $dataProfile,
            $aggregatedMetrics,
            $derivedMetrics,
            $analysisTemplate,
            $columnMapping,
            $decisionEnabled,
        );

        $rawResponse = $this->aiAnalysisClient->analyze($context);

        $result = $this->normalizeAnalysisResultAction->execute($rawResponse);

        // Phase 4-B: Controlled Diagnosis. Runs after Final Analyze,
        // before markCompleted() — never after (see
        // docs/product/DIAGNOSIS_ENGINE.md "Pipeline最終順序": Diagnosis is
        // part of what "Completed" means, not something that appears
        // later). Uses the same in-memory $aggregatedMetrics already
        // computed above, exactly like EvaluateAnalysisJobAction above.
        // Soft-fail by design, mirroring Evaluation's own soft-fail: a
        // technical failure here must never turn an otherwise-successful
        // AnalysisJob into a Failed one. Per-EvaluationFact failures are
        // already caught inside RunDiagnosisForAnalysisJobAction itself
        // (see that class's docblock); this outer catch only guards
        // against a genuine orchestration-level failure (e.g. the
        // DiagnosisResult cleanup delete or the EvaluationFact query
        // itself failing). Unlike Evaluation, no separate "clear stale
        // rows" call is needed here — RunDiagnosisForAnalysisJobAction
        // deletes existing DiagnosisResult rows for this AnalysisJob
        // *before* doing any other work, so no stale row can survive
        // regardless of where a failure strikes afterward.
        try {
            $this->runDiagnosisForAnalysisJobAction->execute($analysisJob, $aggregatedMetrics);
        } catch (Throwable $diagnosisException) {
            Log::error('RunDiagnosisForAnalysisJobAction: technical failure — continuing the analysis pipeline without Diagnosis.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'template_key' => $analysisJob->template_key,
                'exception_class' => $diagnosisException::class,
                'exception_message' => $diagnosisException->getMessage(),
            ]);
        }

        $this->updateAnalysisJobAction->markCompleted($analysisJob, $rawResponse, $result);
    }
}
