<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PriorityResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Phase 4-C Deterministic Priority Layer v1.1 output: at most one row per
 * Priority-eligible EvaluationFact. See docs/product/PRIORITY_ENGINE.md.
 *
 * Produced entirely by PrioritizeAnalysisJobAction from an already-persisted
 * EvaluationFact row + config/priority_rules.php + config/evaluation_metrics.php's
 * practical_significance_floor — zero AI involvement (see
 * CalculatePriorityAction, which is the only class that performs the actual
 * arithmetic and never touches the database).
 *
 * Idempotent per AnalysisJob attempt: PrioritizeAnalysisJobAction deletes
 * every existing row for an analysis_job_id and inserts this attempt's rows
 * inside one transaction ("delete + recreate", the same idempotency shape
 * as EvaluateAnalysisJobAction — not DiagnosisResult's "delete-first", since
 * Priority has no AI non-determinism to guard against). The unique index on
 * evaluation_fact_id is a defensive backstop, not the primary mechanism —
 * as is this table's evaluation_fact_id foreign key being cascadeOnDelete,
 * which transitively removes any row whose EvaluationFact was itself
 * replaced by Phase 4-A's own delete+recreate.
 *
 * priority_score is computed from EvaluationFact alone and is identical
 * whether or not a DiagnosisResult exists for the same EvaluationFact (see
 * docs/product/PRIORITY_ENGINE.md "Diagnosis非依存") — this model carries
 * no relation to DiagnosisResult.
 *
 * @property int $priority_result_id
 * @property int $analysis_job_id
 * @property int $evaluation_fact_id
 * @property string $impact_basis
 * @property float|null $impact_value
 * @property float|null $impact_total
 * @property float $impact_score
 * @property float|null $gap_raw_value
 * @property float|null $gap_reference_value
 * @property float $gap_score
 * @property float $priority_score
 * @property string $priority_band 'high'|'medium'|'low'
 * @property string $formula_version
 * @property Carbon $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalysisJob $analysisJob
 * @property-read EvaluationFact $evaluationFact
 */
class PriorityResult extends Model
{
    /** @use HasFactory<PriorityResultFactory> */
    use HasFactory;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'priority_result_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'analysis_job_id',
        'evaluation_fact_id',
        'impact_basis',
        'impact_value',
        'impact_total',
        'impact_score',
        'gap_raw_value',
        'gap_reference_value',
        'gap_score',
        'priority_score',
        'priority_band',
        'formula_version',
        'computed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'impact_value' => 'float',
            'impact_total' => 'float',
            'impact_score' => 'float',
            'gap_raw_value' => 'float',
            'gap_reference_value' => 'float',
            'gap_score' => 'float',
            'priority_score' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * Get the analysis job that owns this priority result.
     *
     * @return BelongsTo<AnalysisJob, $this>
     */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'analysis_job_id', 'analysis_job_id');
    }

    /**
     * Get the evaluation fact this priority was computed for.
     *
     * @return BelongsTo<EvaluationFact, $this>
     */
    public function evaluationFact(): BelongsTo
    {
        return $this->belongsTo(EvaluationFact::class, 'evaluation_fact_id', 'evaluation_fact_id');
    }

    public function actionProposal(): HasOne
    {
        return $this->hasOne(ActionProposal::class, 'priority_result_id', 'priority_result_id');
    }
}
