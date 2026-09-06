<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ActionProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $action_proposal_id
 * @property int $analysis_job_id
 * @property int $evaluation_fact_id
 * @property int $diagnosis_result_id
 * @property int $priority_result_id
 * @property string $catalog_key
 * @property string $title
 * @property string $rationale_summary
 * @property list<string> $selected_checks_json
 * @property list<string> $evidence_refs_json
 * @property list<string> $missing_evidence_json
 * @property string|null $raw_response
 * @property string $model
 * @property string $prompt_version
 * @property string $contract_version
 * @property Carbon $proposed_at
 * @property-read AnalysisJob $analysisJob
 * @property-read EvaluationFact $evaluationFact
 * @property-read DiagnosisResult $diagnosisResult
 * @property-read PriorityResult $priorityResult
 */
class ActionProposal extends Model
{
    /** @use HasFactory<ActionProposalFactory> */
    use HasFactory;

    protected $primaryKey = 'action_proposal_id';

    protected $fillable = [
        'analysis_job_id',
        'evaluation_fact_id',
        'diagnosis_result_id',
        'priority_result_id',
        'catalog_key',
        'title',
        'rationale_summary',
        'selected_checks_json',
        'evidence_refs_json',
        'missing_evidence_json',
        'raw_response',
        'model',
        'prompt_version',
        'contract_version',
        'proposed_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_checks_json' => 'array',
            'evidence_refs_json' => 'array',
            'missing_evidence_json' => 'array',
            'proposed_at' => 'datetime',
        ];
    }

    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'analysis_job_id', 'analysis_job_id');
    }

    public function evaluationFact(): BelongsTo
    {
        return $this->belongsTo(EvaluationFact::class, 'evaluation_fact_id', 'evaluation_fact_id');
    }

    public function diagnosisResult(): BelongsTo
    {
        return $this->belongsTo(DiagnosisResult::class, 'diagnosis_result_id', 'diagnosis_result_id');
    }

    public function priorityResult(): BelongsTo
    {
        return $this->belongsTo(PriorityResult::class, 'priority_result_id', 'priority_result_id');
    }
}
