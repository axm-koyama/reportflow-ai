<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The `analysis_jobs.status` column is a plain `unsignedTinyInteger` with
 * no DB-level CHECK constraint — this backed enum is the only place the
 * set of valid values is enforced, so adding `AwaitingMappingConfirmation`
 * (Phase 3-C) required no migration.
 */
enum AnalysisJobStatus: int
{
    case Pending = 0;
    case Processing = 1;
    case Completed = 2;
    case Failed = 3;

    /**
     * A Template-based AnalysisJob whose AI-proposed Column Mapping did
     * not satisfy the Template's required_fields/required_field_groups
     * with high enough confidence. Not a failure: the AnalysisJob is
     * waiting for the user to review/override the mapping via the Mapping
     * Preview screen (see docs/product/MAPPING_CONTROL.md). Free-form
     * analysis (template_key === null) never enters this status.
     */
    case AwaitingMappingConfirmation = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::AwaitingMappingConfirmation => 'Mapping confirmation required',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-pending',
            self::Processing => 'badge-processing',
            self::AwaitingMappingConfirmation => 'badge-awaiting-mapping-confirmation',
            self::Completed => 'badge-completed',
            self::Failed => 'badge-failed',
        };
    }
}
