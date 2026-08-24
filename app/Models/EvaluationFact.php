<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EvaluationFactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Phase 4-A Deterministic Evaluation Engine output: one row per
 * entity x metric x AnalysisJob attempt. See
 * docs/product/EVALUATION_ENGINE.md.
 *
 * Produced entirely by EvaluateAnalysisJobAction — deterministic Laravel
 * calculation from aggregated_metrics + effective_column_mapping +
 * config/evaluation_metrics.php. No AI is ever involved in producing a
 * row of this table.
 *
 * Idempotent per AnalysisJob attempt: EvaluateAnalysisJobAction deletes
 * every existing row for an analysis_job_id before inserting the current
 * attempt's rows (see that Action's docblock), so a Queue retry never
 * accumulates duplicates. The unique index on (analysis_job_id,
 * entity_type, entity_key, metric_key, rule_version) is a defensive
 * backstop for that guarantee, not the primary mechanism.
 *
 * @property int $evaluation_fact_id
 * @property int $analysis_job_id
 * @property string $entity_type semantic entity field key evaluated, e.g. "channel"
 * @property string $entity_key the entity value evaluated, e.g. "Social"
 * @property string $metric_key evaluation metric key, e.g. "conversion_rate"
 * @property string $metric_type evaluation metric type, e.g. "rate"
 * @property float|null $metric_value the entity's own metric value (0-1 scale for a rate)
 * @property float|null $display_baseline_value weighted aggregate baseline across every entity (0-1 scale for a rate)
 * @property float|null $test_baseline_value leave-one-out weighted control baseline used for the statistical test (0-1 scale for a rate)
 * @property int|null $numerator_value entity numerator event count
 * @property int|null $denominator_value entity denominator event count
 * @property int|null $control_numerator_value leave-one-out control numerator event count
 * @property int|null $control_denominator_value leave-one-out control denominator event count
 * @property float|null $delta_absolute metric_value - display_baseline_value (0-1 scale for a rate)
 * @property float|null $delta_percent delta_absolute / display_baseline_value; null when display_baseline_value is 0
 * @property float|null $z_score two-proportion z-test statistic; null when insufficient_data
 * @property string|null $direction numeric fact only: 'above'|'below'|'equal', relative to display_baseline_value
 * @property string $evaluation_level 'high'|'medium'|'low'|'insufficient_data'
 * @property string $rule_version evaluation rule version that produced this row
 * @property Carbon $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalysisJob $analysisJob
 * @property-read DiagnosisResult|null $diagnosisResult
 */
class EvaluationFact extends Model
{
    /** @use HasFactory<EvaluationFactFactory> */
    use HasFactory;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'evaluation_fact_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'analysis_job_id',
        'entity_type',
        'entity_key',
        'metric_key',
        'metric_type',
        'metric_value',
        'display_baseline_value',
        'test_baseline_value',
        'numerator_value',
        'denominator_value',
        'control_numerator_value',
        'control_denominator_value',
        'delta_absolute',
        'delta_percent',
        'z_score',
        'direction',
        'evaluation_level',
        'rule_version',
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
            'metric_value' => 'float',
            'display_baseline_value' => 'float',
            'test_baseline_value' => 'float',
            'numerator_value' => 'integer',
            'denominator_value' => 'integer',
            'control_numerator_value' => 'integer',
            'control_denominator_value' => 'integer',
            'delta_absolute' => 'float',
            'delta_percent' => 'float',
            'z_score' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * Get the analysis job that owns this evaluation fact.
     *
     * @return BelongsTo<AnalysisJob, $this>
     */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'analysis_job_id', 'analysis_job_id');
    }

    /**
     * Get the Phase 4-B Diagnosis Result produced for this evaluation
     * fact, if any (not every EvaluationFact is Diagnosis-eligible — see
     * DetermineDiagnosisEligibilityAction — and an eligible one may still
     * have none if Diagnosis technically failed — see
     * docs/product/DIAGNOSIS_ENGINE.md "per-entity soft-fail"). Phase 4-B
     * v1 produces at most one Diagnosis per EvaluationFact.
     *
     * @return HasOne<DiagnosisResult, $this>
     */
    public function diagnosisResult(): HasOne
    {
        return $this->hasOne(DiagnosisResult::class, 'evaluation_fact_id', 'evaluation_fact_id');
    }
}
