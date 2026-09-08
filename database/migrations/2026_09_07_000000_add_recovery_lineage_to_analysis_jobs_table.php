<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table) {
            $table->foreignId('recovered_from_analysis_job_id')
                ->nullable()
                ->after('status')
                ->constrained('analysis_jobs', 'analysis_job_id', 'analysis_jobs_recovery_source_fk')
                ->restrictOnDelete();
            $table->unique('recovered_from_analysis_job_id', 'analysis_jobs_recovery_source_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table) {
            $table->dropForeign('analysis_jobs_recovery_source_fk');
            $table->dropUnique('analysis_jobs_recovery_source_uniq');
            $table->dropColumn('recovered_from_analysis_job_id');
        });
    }
};
