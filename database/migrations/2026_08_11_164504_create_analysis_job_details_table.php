<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analysis_job_details', function (Blueprint $table) {
            $table->comment('Execution details and AI results for analysis jobs.');

            $table->foreignId('analysis_job_id')
                ->primary()
                ->constrained('analysis_jobs', 'analysis_job_id')
                ->cascadeOnDelete()
                ->comment('Analysis job ID');

            $table->longText('prompt')
                ->comment('Prompt sent for AI analysis');

            $table->longText('raw_response')
                ->nullable()
                ->comment('Raw AI response for audit and reprocessing');

            $table->json('result')
                ->nullable()
                ->comment('Normalized structured analysis result');

            $table->text('error_message')
                ->nullable()
                ->comment('Failure message');

            $table->timestamp('started_at')
                ->nullable()
                ->comment('Analysis start time');

            $table->timestamp('completed_at')
                ->nullable()
                ->comment('Analysis completion time');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_job_details');
    }
};
