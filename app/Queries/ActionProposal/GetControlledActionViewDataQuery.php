<?php

declare(strict_types=1);

namespace App\Queries\ActionProposal;

use App\Actions\ActionProposal\DetermineActionEligibilityAction;
use App\Models\ActionProposal;
use App\Models\AnalysisJob;
use Illuminate\Support\Collection;

class GetControlledActionViewDataQuery
{
    public function __construct(
        private readonly DetermineActionEligibilityAction $determineActionEligibilityAction,
    ) {}

    /**
     * @return array{applicable: bool, eligible_count: int, proposals: Collection<int, ActionProposal>}
     */
    public function execute(AnalysisJob $analysisJob): array
    {
        $applicable = $analysisJob->template_key !== null
            && array_key_exists($analysisJob->template_key, config('evaluation_metrics', []));
        $eligibleCount = 0;

        if ($applicable) {
            foreach ($analysisJob->evaluationFacts as $fact) {
                if ($this->determineActionEligibilityAction->execute($fact, $analysisJob)['eligible']) {
                    $eligibleCount++;
                }
            }
        }

        return [
            'applicable' => $applicable,
            'eligible_count' => $eligibleCount,
            'proposals' => $analysisJob->actionProposals,
        ];
    }
}
