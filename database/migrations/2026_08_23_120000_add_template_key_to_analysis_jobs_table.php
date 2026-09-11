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
        Schema::table('analysis_jobs', function (Blueprint $table) {
            $table->string('template_key', 255)
                ->nullable()
                ->after('title')
                ->comment('config/analysis_templates.php key used, or null for free-form analysis');

            $table->index('template_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table) {
            $table->dropIndex(['template_key']);
            $table->dropColumn('template_key');
        });
    }
};
