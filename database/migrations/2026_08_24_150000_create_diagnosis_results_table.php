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
        Schema::create('diagnosis_results', function (Blueprint $table) {
            $table->comment('Phase 4-B Controlled Diagnosis v1 output: at most one row per eligible EvaluationFact. See docs/product/DIAGNOSIS_ENGINE.md.');

            $table->id('diagnosis_result_id');

            $table->foreignId('analysis_job_id')
                ->constrained('analysis_jobs', 'analysis_job_id')
                ->cascadeOnDelete()
                ->comment('Owning analysis job ID');

            $table->foreignId('evaluation_fact_id')
                ->constrained('evaluation_facts', 'evaluation_fact_id')
                ->cascadeOnDelete()
                ->comment('The EvaluationFact this Diagnosis was produced for (Phase 4-B v1: exactly one Diagnosis per eligible EvaluationFact)');

            $table->string('category_key', 100)
                ->comment('One of config/diagnosis_categories.php\'s keys, chosen from this row\'s Evidence-gated allowed_categories');

            $table->decimal('self_reported_confidence', 4, 3)
                ->nullable()
                ->comment('AI self-reported, uncalibrated confidence in category_key (0-1). Never used for priority or action decisions.');

            $table->text('rationale_summary')
                ->comment('AI-authored explanation of why category_key was chosen, grounded in the supplied evidence only');

            $table->json('evidence_refs_json')
                ->comment('Evidence identifiers (trigger_fact / supporting_facts) the AI cited, validated to be a subset of what was actually supplied');

            $table->json('missing_evidence_json')
                ->comment('Data/evidence the AI reports would help narrow the diagnosis further');

            $table->json('supporting_facts_json')
                ->comment('Snapshot of the deterministic supporting facts actually sent to the AI (audit trail — aggregated_metrics itself is never persisted)');

            $table->longText('raw_response')
                ->nullable()
                ->comment('Raw Diagnosis AI response for audit and reprocessing');

            $table->string('model', 100)
                ->comment('OpenAI model used for this Diagnosis call');

            $table->string('prompt_version', 50)
                ->comment('Diagnosis System Instruction / Category Catalog version that produced this row, e.g. "diagnosis_prompt_v1.0"');

            $table->timestamps();

            $table->unique('evaluation_fact_id', 'diagnosis_results_uniq_evaluation_fact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagnosis_results');
    }
};
