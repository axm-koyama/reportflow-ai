<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisJobStatus;
use Database\Factories\AnalysisJobFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $analysis_job_id
 * @property int $data_file_id
 * @property string $title
 * @property string|null $template_key config/analysis_templates.php key, or null for free-form analysis
 * @property AnalysisJobStatus $status
 * @property int|null $recovered_from_analysis_job_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AnalysisJobDetail|null $analysisJobDetail
 * @property-read DataFile $dataFile
 * @property-read Collection<int, EvaluationFact> $evaluationFacts
 * @property-read Collection<int, ActionProposal> $actionProposals
 * @property-read AnalysisJob|null $recoveredFrom
 * @property-read AnalysisJob|null $recoveryAttempt
 * @property-read Report|null $report
 */
class AnalysisJob extends Model
{
    /** @use HasFactory<AnalysisJobFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'analysis_job_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'data_file_id',
        'title',
        'template_key',
        'status',
        'recovered_from_analysis_job_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => AnalysisJobStatus::class,
        ];
    }

    /**
     * Get the data file that this analysis job targets.
     *
     * @return BelongsTo<DataFile, $this>
     */
    public function dataFile(): BelongsTo
    {
        return $this->belongsTo(DataFile::class, 'data_file_id', 'data_file_id');
    }

    /** @return BelongsTo<AnalysisJob, $this> */
    public function recoveredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recovered_from_analysis_job_id', 'analysis_job_id');
    }

    /** @return HasOne<AnalysisJob, $this> */
    public function recoveryAttempt(): HasOne
    {
        return $this->hasOne(self::class, 'recovered_from_analysis_job_id', 'analysis_job_id');
    }

    /**
     * Get the execution detail for this analysis job.
     *
     * @return HasOne<AnalysisJobDetail, $this>
     */
    public function analysisJobDetail(): HasOne
    {
        return $this->hasOne(AnalysisJobDetail::class, 'analysis_job_id', 'analysis_job_id');
    }

    /**
     * Get the Phase 4-A Evaluation Facts computed for this analysis job.
     * See docs/product/EVALUATION_ENGINE.md.
     *
     * @return HasMany<EvaluationFact, $this>
     */
    public function evaluationFacts(): HasMany
    {
        return $this->hasMany(EvaluationFact::class, 'analysis_job_id', 'analysis_job_id');
    }

    public function actionProposals(): HasMany
    {
        return $this->hasMany(ActionProposal::class, 'analysis_job_id', 'analysis_job_id');
    }

    /** @return HasOne<Report, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(Report::class, 'analysis_job_id', 'analysis_job_id');
    }
}
