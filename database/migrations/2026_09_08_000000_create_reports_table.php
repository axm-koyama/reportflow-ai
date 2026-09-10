<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id('report_id');
            $table->foreignId('analysis_job_id')
                ->constrained('analysis_jobs', 'analysis_job_id', 'reports_analysis_job_fk')
                ->cascadeOnDelete();
            $table->string('title');
            $table->json('snapshot_json');
            $table->longText('rendered_html');
            $table->string('schema_version', 50);
            $table->string('renderer_version', 50);
            $table->char('content_hash', 64);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique('analysis_job_id', 'reports_analysis_job_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
