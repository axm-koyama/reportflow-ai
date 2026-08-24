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
        Schema::create('evaluation_facts', function (Blueprint $table) {
            $table->comment('Phase 4-A Deterministic Evaluation Engine output: one row per entity x metric x AnalysisJob attempt. See docs/product/EVALUATION_ENGINE.md.');

            $table->id('evaluation_fact_id');

            $table->foreignId('analysis_job_id')
                ->constrained('analysis_jobs', 'analysis_job_id')
                ->cascadeOnDelete()
                ->comment('Owning analysis job ID');

            $table->string('entity_type', 100)
                ->comment('Semantic entity field key evaluated, e.g. "channel"');

            $table->string('entity_key', 255)
                ->comment('The entity value evaluated, e.g. "Social"');

            $table->string('metric_key', 100)
                ->comment('Evaluation metric key, e.g. "conversion_rate"');

            $table->string('metric_type', 20)
                ->comment('Evaluation metric type, e.g. "rate"');

            $table->decimal('metric_value', 18, 8)
                ->nullable()
                ->comment('The entity\'s own metric value (0-1 scale for a rate)');

            $table->decimal('display_baseline_value', 18, 8)
                ->nullable()
                ->comment('Weighted aggregate baseline across every entity (0-1 scale for a rate)');

            $table->decimal('test_baseline_value', 18, 8)
                ->nullable()
                ->comment('Leave-one-out weighted control baseline used for the statistical test (0-1 scale for a rate)');

            $table->unsignedBigInteger('numerator_value')
                ->nullable()
                ->comment('Entity numerator event count');

            $table->unsignedBigInteger('denominator_value')
                ->nullable()
                ->comment('Entity denominator event count');

            $table->unsignedBigInteger('control_numerator_value')
                ->nullable()
                ->comment('Leave-one-out control numerator event count');

            $table->unsignedBigInteger('control_denominator_value')
                ->nullable()
                ->comment('Leave-one-out control denominator event count');

            $table->decimal('delta_absolute', 18, 8)
                ->nullable()
                ->comment('metric_value - display_baseline_value (0-1 scale for a rate)');

            $table->decimal('delta_percent', 18, 8)
                ->nullable()
                ->comment('delta_absolute / display_baseline_value; null when display_baseline_value is 0');

            $table->decimal('z_score', 18, 8)
                ->nullable()
                ->comment('Two-proportion z-test statistic; null when insufficient_data');

            $table->string('direction', 10)
                ->nullable()
                ->comment('Numeric fact only: above / below / equal, relative to display_baseline_value');

            $table->string('evaluation_level', 20)
                ->comment('Product evaluation result combining the statistical gate and the practical significance floor: high / medium / low / insufficient_data');

            $table->string('rule_version', 50)
                ->comment('Evaluation rule version that produced this row, e.g. "evaluation_rule_v1.0"');

            $table->timestamp('computed_at')
                ->comment('When this fact was computed');

            $table->timestamps();

            $table->unique(
                ['analysis_job_id', 'entity_type', 'entity_key', 'metric_key', 'rule_version'],
                'evaluation_facts_uniq_job_entity_metric_rule',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_facts');
    }
};
