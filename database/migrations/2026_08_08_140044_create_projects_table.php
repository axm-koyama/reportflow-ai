<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
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
        Schema::create('projects', function (Blueprint $table) {
            $table->comment('Business projects that group data files, analysis jobs, and reports.');
            $table->id('project_id');
            $table->string('name')->comment('Project name');
            $table->text('description')->nullable()->comment('Project description');
            $table->string('status', 20)
                ->default(ProjectStatus::Active->value)
                ->comment('Business status (ProjectStatus enum value)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
