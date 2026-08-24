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
        Schema::create('priority_results', function (Blueprint $table) {
            $table->comment('Phase 4-C Deterministic Priority Layer v1.1 output: at most one row per Priority-eligible EvaluationFact. See docs/product/PRIORITY_ENGINE.md.');

            $table->id('priority_result_id');

            $table->foreignId('analysis_job_id')
                ->constrained('analysis_jobs', 'analysis_job_id')
                ->cascadeOnDelete()
                ->comment('Owning analysis job ID');

            $table->foreignId('evaluation_fact_id')
                ->constrained('evaluation_facts', 'evaluation_fact_id')
                ->cascadeOnDelete()
                ->comment('The EvaluationFact this Priority was computed for (Phase 4-C v1.1: exactly one Priority per Priority-eligible EvaluationFact)');

            $table->string('impact_basis', 50)
                ->comment('Which share the Impact Score expresses, e.g. "denominator_share"');

            $table->decimal('impact_value', 18, 8)
                ->nullable()
                ->comment('This entity\'s own value for impact_basis (denominator_share: its denominator_value)');

            $table->decimal('impact_total', 18, 8)
                ->nullable()
                ->comment('Population total impact_value is divided by — every EvaluationFact in this AnalysisJob sharing this metric_key with a valid (non-null, > 0) denominator_value, regardless of evaluation_level (see PRIORITY_ENGINE.md "Impact Total母集団定義")');

            $table->decimal('impact_score', 8, 6)
                ->comment('impact_value / impact_total, 0.0-1.0');

            $table->decimal('gap_raw_value', 18, 8)
                ->nullable()
                ->comment('abs(EvaluationFact.metric_value - EvaluationFact.test_baseline_value) — the leave-one-out peer/control gap, never display_baseline_value (self-dilution); never recomputed from anything but those two verbatim EvaluationFact columns');

            $table->decimal('gap_reference_value', 18, 8)
                ->nullable()
                ->comment('practical_significance_floor * gap_reference_multiple, both resolved from config at computation time');

            $table->decimal('gap_score', 8, 6)
                ->comment('min(gap_raw_value / gap_reference_value, 1), 0.0-1.0');

            $table->decimal('priority_score', 8, 6)
                ->comment('impact_score * gap_score, 0.0-1.0. Diagnosis-independent by construction — see PRIORITY_ENGINE.md "Diagnosis非依存"');

            $table->string('priority_band', 10)
                ->comment('high / medium / low, from config/priority_rules.php band_thresholds — never AI-assigned');

            $table->string('formula_version', 50)
                ->comment('Priority formula/config version that produced this row, e.g. "priority_v1.1"');

            $table->timestamp('computed_at')
                ->comment('When this Priority was computed');

            $table->timestamps();

            $table->unique('evaluation_fact_id', 'priority_results_uniq_evaluation_fact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('priority_results');
    }
};
