<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_proposals', function (Blueprint $table) {
            $table->comment('Phase 4-D Controlled Action Layer v1 advisory proposals.');
            $table->id('action_proposal_id');
            $table->foreignId('analysis_job_id')
                ->constrained('analysis_jobs', 'analysis_job_id')
                ->cascadeOnDelete();
            $table->foreignId('evaluation_fact_id')
                ->constrained('evaluation_facts', 'evaluation_fact_id')
                ->cascadeOnDelete();
            $table->foreignId('diagnosis_result_id')
                ->constrained('diagnosis_results', 'diagnosis_result_id')
                ->cascadeOnDelete();
            $table->foreignId('priority_result_id')
                ->constrained('priority_results', 'priority_result_id')
                ->cascadeOnDelete();
            $table->string('catalog_key', 100)->comment('Application-owned config/action_catalog.php key');
            $table->string('title', 255);
            $table->text('rationale_summary');
            $table->json('selected_checks_json');
            $table->json('evidence_refs_json');
            $table->json('missing_evidence_json');
            $table->longText('raw_response')->nullable()->comment('Raw Action AI response for audit; never rendered directly');
            $table->string('model', 100);
            $table->string('prompt_version', 50);
            $table->string('contract_version', 50);
            $table->timestamp('proposed_at');
            $table->timestamps();
            $table->unique('evaluation_fact_id', 'action_proposals_uniq_evaluation_fact');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_proposals');
    }
};
