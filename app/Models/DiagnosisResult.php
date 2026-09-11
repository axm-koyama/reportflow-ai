<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DiagnosisResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Phase 4-B Controlled Diagnosis v1 output: at most one row per eligible
 * EvaluationFact. See docs/product/DIAGNOSIS_ENGINE.md.
 *
 * Produced entirely by RunDiagnosisForAnalysisJobAction: one
 * AiAnalysisClient::diagnose() call per already-eligible EvaluationFact
 * (see DetermineDiagnosisEligibilityAction), constrained by an
 * Evidence-gated category set (see BuildDiagnosisEvidencePackageAction)
 * and independently re-validated (see NormalizeDiagnosisResultAction)
 * before being persisted here.
 *
 * Idempotent per AnalysisJob attempt: RunDiagnosisForAnalysisJobAction
 * deletes every existing row for an analysis_job_id *before* computing
 * this attempt's rows (delete-first, not delete+recreate-at-the-end — see
 * that Action's docblock), so a Queue retry of the whole AnalysisJob
 * attempt never accumulates duplicates or leaves a stale row behind. The
 * unique index on evaluation_fact_id is a defensive backstop, not the
 * primary mechanism — as is this table's evaluation_fact_id foreign key
 * being cascadeOnDelete, which transitively removes any row whose
 * EvaluationFact was itself replaced by Phase 4-A's own delete+recreate.
 *
 * @property int $diagnosis_result_id
 * @property int $analysis_job_id
 * @property int $evaluation_fact_id
 * @property string $category_key one of config/diagnosis_categories.php's keys
 * @property float|null $self_reported_confidence AI self-reported, uncalibrated (0-1)
 * @property string $rationale_summary
 * @property list<string> $evidence_refs_json
 * @property list<string> $missing_evidence_json
 * @property list<array{metric_key: string, value: int|float}> $supporting_facts_json
 * @property string|null $raw_response
 * @property string $model
 * @property string $prompt_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalysisJob $analysisJob
 * @property-read EvaluationFact $evaluationFact
 */
class DiagnosisResult extends Model
{
    /** @use HasFactory<DiagnosisResultFactory> */
    use HasFactory;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'diagnosis_result_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'analysis_job_id',
        'evaluation_fact_id',
        'category_key',
        'self_reported_confidence',
        'rationale_summary',
        'evidence_refs_json',
        'missing_evidence_json',
        'supporting_facts_json',
        'raw_response',
        'model',
        'prompt_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'self_reported_confidence' => 'float',
            'evidence_refs_json' => 'array',
            'missing_evidence_json' => 'array',
            'supporting_facts_json' => 'array',
        ];
    }

    /**
     * Get the analysis job that owns this diagnosis result.
     *
     * @return BelongsTo<AnalysisJob, $this>
     */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'analysis_job_id', 'analysis_job_id');
    }

    /**
     * Get the evaluation fact this diagnosis was produced for.
     *
     * @return BelongsTo<EvaluationFact, $this>
     */
    public function evaluationFact(): BelongsTo
    {
        return $this->belongsTo(EvaluationFact::class, 'evaluation_fact_id', 'evaluation_fact_id');
    }

    public function actionProposal(): HasOne
    {
        return $this->hasOne(ActionProposal::class, 'diagnosis_result_id', 'diagnosis_result_id');
    }
}
