<?php

declare(strict_types=1);

namespace App\Actions\Diagnosis;

use App\AI\AiAnalysisClient;
use App\Models\AnalysisJob;
use App\Models\DiagnosisResult;
use App\Models\EvaluationFact;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Top-level orchestrator for Phase 4-B's Controlled Diagnosis: turns one
 * AnalysisJob's already-persisted EvaluationFact rows into, at most, one
 * DiagnosisResult per eligible EvaluationFact. See
 * docs/product/DIAGNOSIS_ENGINE.md.
 *
 * Mirrors EvaluateAnalysisJobAction's shape (top-level orchestration,
 * Queue-free, called synchronously from ExecuteAnalysisJobAction) but for
 * one deliberate difference: this makes exactly one AI call per eligible
 * EvaluationFact (see AiAnalysisClient::diagnose()), whereas Evaluation
 * makes none.
 *
 * A Free Analysis AnalysisJob (template_key === null), a Template without
 * any config/evaluation_metrics.php entry (e.g. sales_analysis today), or
 * an AnalysisJob whose Effective Mapping is not confirmed is a pure
 * no-op — identical guard conditions to EvaluateAnalysisJobAction, since
 * any of those already guarantees zero EvaluationFact rows exist for this
 * AnalysisJob in the first place (see that class's docblock).
 *
 * Idempotency / staleness: unlike EvaluateAnalysisJobAction's "delete +
 * recreate at the end" (which needs a separate clearForAnalysisJob() for
 * the caller to invoke on soft-fail), this deletes every existing
 * DiagnosisResult row for this analysis_job_id *first*, before any
 * Eligibility/Evidence/AI work begins. That ordering means no stale row
 * can ever survive a failure anywhere in this method — there is no later
 * point a caller needs to separately "clean up" after. See
 * docs/product/DIAGNOSIS_ENGINE.md "Idempotency / stale Diagnosis
 * cleanup". This is also guaranteed transitively: DiagnosisResult's
 * evaluation_fact_id foreign key is cascadeOnDelete, so
 * EvaluateAnalysisJobAction's own delete+recreate of EvaluationFact
 * already removes any DiagnosisResult that pointed at a now-gone fact —
 * the explicit delete here is a defensive backstop on top of that, not
 * the only mechanism.
 *
 * Per-entity soft-fail: each eligible EvaluationFact's Diagnosis attempt
 * (Evidence Package -> AiAnalysisClient::diagnose() -> Laravel
 * validation -> persistence) is wrapped in its own try/catch. One
 * EvaluationFact's technical failure (AI provider error, invalid
 * category, hallucinated evidence_ref, ...) never prevents any other
 * eligible EvaluationFact in the same AnalysisJob from being diagnosed —
 * see docs/product/DIAGNOSIS_ENGINE.md "per-entity soft-fail".
 *
 * No exception from a single EvaluationFact's Diagnosis attempt ever
 * propagates out of this method — deliberately, unlike analyze()/
 * planMetrics()/mapColumns(), whose RuntimeException does propagate to
 * Laravel Queue for a whole-attempt retry. Diagnosis runs *after* those
 * calls have already succeeded (see ExecuteAnalysisJobAction's pipeline
 * order); redoing the whole AnalysisJob attempt (and re-paying for
 * Mapping/Planning/Final Analyze) just to retry one Diagnosis call would
 * be poor cost/benefit for a genuinely additive feature. See
 * docs/product/DIAGNOSIS_ENGINE.md "Retry semantics". A genuine
 * orchestration-level bug (e.g. a DB failure on the initial cleanup
 * delete, or on EvaluationFact::query()) is intentionally *not* caught
 * here — ExecuteAnalysisJobAction wraps this whole call the same way it
 * already wraps EvaluateAnalysisJobAction, so that class of failure is
 * still logged and soft-failed one level up, without this method
 * pretending to hide it.
 */
class RunDiagnosisForAnalysisJobAction
{
    /**
     * Diagnosis System Instruction / Category Catalog version. Bumped
     * whenever the Diagnosis System Instruction text or
     * config/diagnosis_categories.php's catalog changes in a way that
     * would change AI behavior — see docs/product/DIAGNOSIS_ENGINE.md
     * "model / prompt_version保存" for why a single version column is
     * enough (a Category Catalog change is defined as also requiring a
     * prompt_version bump, rather than tracking a separate
     * category_catalog_version column).
     */
    private const string PROMPT_VERSION = 'diagnosis_prompt_v1.1';

    public function __construct(
        private readonly DetermineDiagnosisEligibilityAction $determineDiagnosisEligibilityAction,
        private readonly BuildDiagnosisEvidencePackageAction $buildDiagnosisEvidencePackageAction,
        private readonly AiAnalysisClient $aiAnalysisClient,
        private readonly NormalizeDiagnosisResultAction $normalizeDiagnosisResultAction,
    ) {}

    /**
     * @param  array<string, mixed>  $aggregatedMetrics  the MetricAggregationAction output already computed for this attempt
     * @return int number of DiagnosisResult rows persisted
     */
    public function execute(AnalysisJob $analysisJob, array $aggregatedMetrics): int
    {
        if ($analysisJob->template_key === null) {
            return 0;
        }

        $effectiveColumnMapping = $analysisJob->analysisJobDetail?->effective_column_mapping;

        if ($effectiveColumnMapping === null) {
            return 0;
        }

        // Delete-first: see class docblock "Idempotency / staleness".
        DiagnosisResult::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->delete();

        $facts = EvaluationFact::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->get();

        $saved = 0;

        foreach ($facts as $fact) {
            if (! $this->determineDiagnosisEligibilityAction->execute($fact, $analysisJob->template_key)) {
                continue;
            }

            if ($this->diagnoseOneFact($analysisJob, $fact, $aggregatedMetrics, $effectiveColumnMapping)) {
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $aggregatedMetrics
     * @param  array<string, array{column: string|null, status: string, source?: string}>  $effectiveColumnMapping
     */
    private function diagnoseOneFact(
        AnalysisJob $analysisJob,
        EvaluationFact $fact,
        array $aggregatedMetrics,
        array $effectiveColumnMapping,
    ): bool {
        try {
            $package = $this->buildDiagnosisEvidencePackageAction->execute(
                $fact,
                $aggregatedMetrics,
                $effectiveColumnMapping,
                (string) $analysisJob->template_key,
            );

            if ($package['allowed_categories'] === []) {
                // Should never happen — "insufficient_explanatory_evidence"
                // is always included by BuildDiagnosisEvidencePackageAction
                // — but defensively refuse to call the AI with a schema
                // that could never be satisfied, rather than let
                // diagnose() build an impossible "enum".
                Log::warning('RunDiagnosisForAnalysisJobAction: no allowed_categories for an eligible EvaluationFact — skipping.', [
                    'analysis_job_id' => $analysisJob->analysis_job_id,
                    'evaluation_fact_id' => $fact->evaluation_fact_id,
                ]);

                return false;
            }

            $suppliedEvidenceIds = $this->suppliedEvidenceIds($package['trigger_fact'], $package['supporting_facts']);

            $model = (string) config('services.openai.model');

            $rawResponse = $this->aiAnalysisClient->diagnose([
                'system_instruction' => $this->systemInstruction(),
                'trigger_fact' => $package['trigger_fact'],
                'supporting_facts' => $package['supporting_facts'],
                'allowed_categories' => $package['allowed_categories'],
            ]);

            $result = $this->normalizeDiagnosisResultAction->execute(
                $rawResponse,
                $package['allowed_categories'],
                $suppliedEvidenceIds,
            );

            DiagnosisResult::query()->create([
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'category_key' => $result['category_key'],
                'self_reported_confidence' => $result['self_reported_confidence'],
                'rationale_summary' => $result['rationale_summary'],
                'evidence_refs_json' => $result['evidence_refs'],
                'missing_evidence_json' => $result['missing_evidence'],
                'supporting_facts_json' => $package['supporting_facts'],
                'raw_response' => $rawResponse,
                'model' => $model,
                'prompt_version' => self::PROMPT_VERSION,
            ]);

            return true;
        } catch (Throwable $exception) {
            // Per-entity soft-fail: covers both infrastructure failures
            // from AiAnalysisClient::diagnose() (RuntimeException —
            // timeout/429/5xx/network/refusal/incomplete) and semantic
            // failures from NormalizeDiagnosisResultAction
            // (InvalidArgumentException — invalid category, out-of-range
            // confidence, hallucinated evidence_ref, malformed shape). See
            // class docblock "Per-entity soft-fail" / "Retry semantics".
            // Neither ever reaches Laravel Queue.
            Log::error('RunDiagnosisForAnalysisJobAction: Diagnosis failed for one EvaluationFact — continuing with the rest.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Every evidence identifier this exact request actually offered:
     * trigger_fact's own "evidence_id" plus one per supporting fact. This
     * is the closed set NormalizeDiagnosisResultAction checks
     * evidence_refs against — see that class's docblock and
     * docs/product/DIAGNOSIS_ENGINE.md "evidence_refs".
     *
     * Deliberately reads "evidence_id" back out of the same package sent
     * to the AI (BuildDiagnosisEvidencePackageAction), rather than
     * re-deriving the id format independently here — a prior version did
     * the latter, and a Real API E2E run caught the resulting bug: the
     * reconstructed ids matched Laravel's own convention but were never
     * actually shown to the AI in the request payload, so the AI
     * invented its own ids and every response was rejected. Reading the
     * same field back guarantees "what was sent" and "what is checked"
     * can never drift apart again.
     *
     * @param  array<string, mixed>  $triggerFact
     * @param  list<array{evidence_id: string, metric_key: string, value: int|float}>  $supportingFacts
     * @return list<string>
     */
    private function suppliedEvidenceIds(array $triggerFact, array $supportingFacts): array
    {
        $ids = [$triggerFact['evidence_id']];

        foreach ($supportingFacts as $supportingFact) {
            $ids[] = $supportingFact['evidence_id'];
        }

        return $ids;
    }

    /**
     * The Phase 4-B v1 Diagnosis System Instruction. See
     * docs/product/DIAGNOSIS_ENGINE.md "Diagnosis System Instruction".
     */
    private function systemInstruction(): string
    {
        return <<<'TEXT'
            You are assisting with Controlled Diagnosis of a single flagged
            business metric. A separate, deterministic Evaluation layer has
            already decided this metric's evaluation_level and direction —
            your task is narrower: choose the best-supported explanatory
            category for it, or say the evidence cannot distinguish one.

            Rules:

            1. The supplied trigger_fact is an immutable, already-verified
            Evaluation Fact. Do not recompute, re-derive, reinterpret, or
            challenge its metric_value, baseline values, direction,
            evaluation_level, or z_score.

            2. Use only the supplied trigger_fact and supporting_facts. Do
            not use any other knowledge about advertising, marketing, or
            this business.

            3. Choose category_key only from allowed_categories. Never
            invent a category name not present in that list.

            4. Do not invent data that was not supplied.

            5. Do not infer a landing-page cause, creative cause, audience
            mismatch, seasonality, or tracking failure without
            deterministic, distinguishing evidence for it.

            6. If the supplied evidence cannot distinguish between possible
            causes, choose insufficient_explanatory_evidence. This is a
            normal, correct answer — not a failure — and you should
            actively prefer it whenever the evidence does not clearly
            point to one specific cause.

            7. Zero conversions with a sufficient denominator does not by
            itself establish a measurement or tracking problem — a genuine
            zero-conversion outcome (the channel truly converted nobody)
            remains an equally possible explanation. measurement_consistency_risk
            means only that measurement consistency is one possible area
            worth verifying, because the observed zero-result pattern is
            notable — never that a problem was found. Never state or imply
            that tracking failure is confirmed, probable, or more likely
            than a genuine zero-conversion outcome unless distinguishing
            evidence was supplied to you.

            8. Do not assign a priority (high, medium, or low) to
            anything.

            9. Do not recommend any action, including budget
            increase/decrease, targeting changes, creative changes, bid
            changes, or landing-page changes.

            10. Supporting facts are raw, unevaluated values — no baseline
            or peer comparison has been computed for them. Do not
            characterize them as high, low, good, bad, strong, weak,
            efficient, or inefficient. Only trigger_fact carries a
            verified evaluation.

            11. Every item you were given — trigger_fact and each entry in
            supporting_facts — has its own "evidence_id" field. evidence_refs
            must contain at least one of these exact "evidence_id" strings,
            copied verbatim, and must never be empty — even for
            insufficient_explanatory_evidence, cite the trigger_fact's
            evidence_id, since that Fact is what you evaluated. Never
            invent, reformat, abbreviate, or guess an evidence identifier.

            12. missing_evidence must name data or evidence that would
            help narrow the diagnosis (for example "landing-page-level
            conversion rate") — never an operational action (for example
            "improve the landing page").

            13. self_reported_confidence expresses your own uncertainty
            about which category best fits the supplied evidence. It does
            not express confidence in trigger_fact. trigger_fact is fixed
            for this Diagnosis step and must not be reassessed here.

            14. Return only the required structured output.

            15. rationale_summary must describe what the evidence shows,
            never what should be done about it.
            TEXT;
    }
}
