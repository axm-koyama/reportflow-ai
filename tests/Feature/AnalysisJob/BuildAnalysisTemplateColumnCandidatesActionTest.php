<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\BuildAnalysisTemplateColumnCandidatesAction;
use Tests\TestCase;

/**
 * Direct unit coverage for the candidate-building logic extracted
 * (Phase 3-C) out of ResolveAnalysisTemplateAction so
 * AnalysisJobController::editMapping() can rebuild the exact same
 * candidates without calling the AI. ResolveAnalysisTemplateActionTest's
 * own "column candidates are filtered by inferred type not by name" test
 * already exercises this indirectly through ResolveAnalysisTemplateAction;
 * this file tests the extracted Action directly and in isolation.
 */
class BuildAnalysisTemplateColumnCandidatesActionTest extends TestCase
{
    public function test_candidates_are_filtered_by_inferred_type_not_by_name(): void
    {
        $fields = [
            'channel' => ['kind' => 'dimension', 'label' => 'チャネル'],
            'spend' => ['kind' => 'measure', 'label' => '広告費'],
            'date' => ['kind' => 'temporal', 'label' => '日付'],
        ];

        $dataProfile = [
            'columns' => [
                ['name' => '媒体', 'inferred_type' => 'string'],
                ['name' => '広告コスト', 'inferred_type' => 'integer'],
                ['name' => '売上金額', 'inferred_type' => 'decimal'],
                ['name' => '登録日', 'inferred_type' => 'date'],
            ],
            'sample_rows' => [
                ['媒体' => 'Email', '広告コスト' => '1000', '売上金額' => '5000.5', '登録日' => '2026-01-01'],
            ],
        ];

        $candidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)->execute($fields, $dataProfile);

        $this->assertSame(['媒体'], array_column($candidates['channel'], 'column'));
        $this->assertEqualsCanonicalizing(['広告コスト', '売上金額'], array_column($candidates['spend'], 'column'));
        $this->assertSame(['登録日'], array_column($candidates['date'], 'column'));
    }

    public function test_sample_values_are_drawn_from_the_data_profiles_own_sample_rows(): void
    {
        $fields = ['channel' => ['kind' => 'dimension', 'label' => 'チャネル']];

        $dataProfile = [
            'columns' => [['name' => '媒体', 'inferred_type' => 'string']],
            'sample_rows' => [
                ['媒体' => 'Email'],
                ['媒体' => 'Social'],
                ['媒体' => 'Email'], // duplicate, must not repeat
                ['媒体' => ''],       // blank, must be skipped
            ],
        ];

        $candidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)->execute($fields, $dataProfile);

        $this->assertSame(['Email', 'Social'], $candidates['channel'][0]['sample_values']);
    }

    public function test_a_field_with_no_matching_column_gets_an_empty_candidate_list(): void
    {
        $fields = ['date' => ['kind' => 'temporal', 'label' => '日付']];
        $dataProfile = ['columns' => [['name' => '媒体', 'inferred_type' => 'string']], 'sample_rows' => []];

        $candidates = app(BuildAnalysisTemplateColumnCandidatesAction::class)->execute($fields, $dataProfile);

        $this->assertSame([], $candidates['date']);
    }
}
