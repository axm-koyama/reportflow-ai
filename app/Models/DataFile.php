<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DataFileFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $data_file_id
 * @property int $project_id
 * @property string $original_name
 * @property string $stored_path
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AnalysisJob> $analysisJobs
 */
class DataFile extends Model
{
    /** @use HasFactory<DataFileFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'data_file_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size',
    ];

    /**
     * Get the project that owns the data file.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /**
     * Get the analysis jobs executed against this data file.
     *
     * @return HasMany<AnalysisJob, $this>
     */
    public function analysisJobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class, 'data_file_id', 'data_file_id');
    }
}
