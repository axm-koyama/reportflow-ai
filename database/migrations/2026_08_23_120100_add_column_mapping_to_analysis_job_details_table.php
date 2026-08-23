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
        Schema::table('analysis_job_details', function (Blueprint $table) {
            $table->json('column_mapping')
                ->nullable()
                ->after('prompt')
                ->comment('Resolved semantic field => {column, confidence, status} for the Analysis Template used, if any');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_job_details', function (Blueprint $table) {
            $table->dropColumn('column_mapping');
        });
    }
};
