<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnalysisJobDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $analysis_job_id
 * @property string $prompt
 * @property array<string, array{column: string|null, confidence: string, status: string}>|null $column_mapping AI-proposed mapping after ValidateColumnMappingAction — an audit record, never mutated after being written
 * @property array<string, array{column: string|null}>|null $manual_column_mapping sparse: only fields the user explicitly touched (Phase 3-C, see docs/product/MAPPING_CONTROL.md)
 * @property array<string, array{column: string|null, status: string, source: 'ai'|'manual'}>|null $effective_column_mapping the deterministic Manual > Validated AI > Unmapped result actually used by Planning/Calculation/Analyze (Phase 3-C)
 * @property string|null $raw_response
 * @property array<string, mixed>|null $result
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalysisJob $analysisJob
 */
class AnalysisJobDetail extends Model
{
    /** @use HasFactory<AnalysisJobDetailFactory> */
    use HasFactory;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'analysis_job_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * The primary key is shared with, and assigned from, the owning
     * AnalysisJob, so this table does not generate its own IDs.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'analysis_job_id',
        'prompt',
        'column_mapping',
        'manual_column_mapping',
        'effective_column_mapping',
        'raw_response',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'manual_column_mapping' => 'array',
            'effective_column_mapping' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the analysis job that owns this detail.
     *
     * @return BelongsTo<AnalysisJob, $this>
     */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'analysis_job_id', 'analysis_job_id');
    }
}
