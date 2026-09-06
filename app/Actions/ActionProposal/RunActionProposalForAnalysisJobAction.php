<?php

declare(strict_types=1);

namespace App\Actions\ActionProposal;

use App\AI\AiAnalysisClient;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use App\Models\EvaluationFact;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunActionProposalForAnalysisJobAction
{
    private const string PROMPT_VERSION = 'action_prompt_v1';

    public function __construct(
        private readonly DetermineActionEligibilityAction $determineActionEligibilityAction,
        private readonly BuildActionEvidencePackageAction $buildActionEvidencePackageAction,
        private readonly AiAnalysisClient $aiAnalysisClient,
        private readonly NormalizeActionProposalAction $normalizeActionProposalAction,
    ) {}

    public function execute(AnalysisJob $analysisJob): int
    {
        ActionProposal::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->delete();

        $facts = EvaluationFact::query()
            ->where('analysis_job_id', $analysisJob->analysis_job_id)
            ->with(['diagnosisResult', 'priorityResult'])
            ->get();

        $saved = 0;

        foreach ($facts as $fact) {
            $eligibility = $this->determineActionEligibilityAction->execute($fact, $analysisJob);

            if (! $eligibility['eligible']) {
                Log::info('RunActionProposalForAnalysisJobAction: candidate is ineligible — skipping.', [
                    'analysis_job_id' => $analysisJob->analysis_job_id,
                    'evaluation_fact_id' => $fact->evaluation_fact_id,
                    'reason' => $eligibility['reason'],
                ]);

                continue;
            }

            if ($this->proposeOne($analysisJob, $fact, $eligibility['catalog_key'])) {
                $saved++;
            }
        }

        return $saved;
    }

    private function proposeOne(AnalysisJob $analysisJob, EvaluationFact $fact, string $catalogKey): bool
    {
        try {
            $package = $this->buildActionEvidencePackageAction->execute($analysisJob, $fact, $catalogKey);
            $rawResponse = $this->aiAnalysisClient->proposeAction([
                'system_instruction' => $this->systemInstruction(),
                'evidence_package' => $package,
            ]);
            $proposal = $this->normalizeActionProposalAction->execute(
                $rawResponse,
                $package,
                $analysisJob,
                $fact,
            );

            ActionProposal::query()->create([
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'diagnosis_result_id' => $fact->diagnosisResult->diagnosis_result_id,
                'priority_result_id' => $fact->priorityResult->priority_result_id,
                'catalog_key' => $proposal['catalog_key'],
                'title' => $proposal['title'],
                'rationale_summary' => $proposal['rationale_summary'],
                'selected_checks_json' => $proposal['selected_checks'],
                'evidence_refs_json' => $proposal['evidence_refs'],
                'missing_evidence_json' => $proposal['missing_evidence'],
                'raw_response' => $rawResponse,
                'model' => (string) config('services.openai.model'),
                'prompt_version' => self::PROMPT_VERSION,
                'contract_version' => $package['contract_version'],
                'proposed_at' => now(),
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::error('RunActionProposalForAnalysisJobAction: proposal failed for one EvaluationFact — continuing.', [
                'analysis_job_id' => $analysisJob->analysis_job_id,
                'evaluation_fact_id' => $fact->evaluation_fact_id,
                'catalog_key' => $catalogKey,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function systemInstruction(): string
    {
        return <<<'TEXT'
            Create one advisory review proposal from only the supplied Action
            Evidence Package. This is not a confirmed cause, approval, or
            authorization to execute anything. Copy catalog_key, checks,
            evidence references, and missing-evidence strings only from the
            supplied allow-lists. Do not propose budget, bid, targeting,
            creative, landing-page, campaign, connector, URL, command, or
            external-system changes. Return only the required structured output.
            TEXT;
    }
}
