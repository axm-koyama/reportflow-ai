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
        Schema::create('analysis_jobs', function (Blueprint $table) {
            $table->comment('AI analysis jobs for uploaded data files.');

            $table->id('analysis_job_id');

            $table->foreignId('data_file_id')
                ->constrained('data_files', 'data_file_id')
                ->restrictOnDelete()
                ->comment('Target data file ID');

            $table->string('title', 255)
                ->comment('User-defined analysis title');

            $table->unsignedTinyInteger('status')
                ->default(0)
                ->comment('0: pending, 1: processing, 2: completed, 3: failed');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_jobs');
    }
};
