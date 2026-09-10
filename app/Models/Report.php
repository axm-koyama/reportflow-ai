<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $report_id
 * @property int $analysis_job_id
 * @property string $title
 * @property array<string, mixed> $snapshot_json
 * @property string $rendered_html
 * @property string $schema_version
 * @property string $renderer_version
 * @property string $content_hash
 * @property Carbon $generated_at
 * @property-read AnalysisJob $analysisJob
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected $primaryKey = 'report_id';

    protected $fillable = [
        'analysis_job_id',
        'title',
        'snapshot_json',
        'rendered_html',
        'schema_version',
        'renderer_version',
        'content_hash',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_json' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AnalysisJob, $this> */
    public function analysisJob(): BelongsTo
    {
        return $this->belongsTo(AnalysisJob::class, 'analysis_job_id', 'analysis_job_id');
    }
}
