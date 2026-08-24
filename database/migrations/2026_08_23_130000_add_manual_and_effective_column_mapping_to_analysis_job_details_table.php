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
            $table->json('manual_column_mapping')
                ->nullable()
                ->after('column_mapping')
                ->comment('Sparse user overrides: {field: {column: string|null}}. A missing key defers to the AI mapping; column=null means the user explicitly unset that field.');

            $table->json('effective_column_mapping')
                ->nullable()
                ->after('manual_column_mapping')
                ->comment('Deterministic Manual > Validated AI > Unmapped result actually used by Planning/Calculation/Analyze: {field: {column, status, source: ai|manual}}.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_job_details', function (Blueprint $table) {
            $table->dropColumn(['manual_column_mapping', 'effective_column_mapping']);
        });
    }
};
