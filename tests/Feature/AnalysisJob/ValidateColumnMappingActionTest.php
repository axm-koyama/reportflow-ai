<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\ValidateColumnMappingAction;
use Tests\TestCase;

class ValidateColumnMappingActionTest extends TestCase
{
    /**
     * @return array<string, array{kind: string, label: string}>
     */
    private function fields(): array
    {
        return [
            'channel' => ['kind' => 'dimension', 'label' => 'チャネル'],
            'spend' => ['kind' => 'measure', 'label' => '広告費'],
            'revenue' => ['kind' => 'measure', 'label' => '売上'],
            'campaign' => ['kind' => 'dimension', 'label' => 'キャンペーン'],
        ];
    }

    /**
     * @return array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>>
     */
    private function columnCandidates(): array
    {
        return [
            'channel' => [
                ['column' => '媒体', 'inferred_type' => 'string', 'sample_values' => ['Email']],
            ],
            'spend' => [
                ['column' => '広告コスト', 'inferred_type' => 'integer', 'sample_values' => ['1000']],
                ['column' => 'amount', 'inferred_type' => 'integer', 'sample_values' => ['1000']],
            ],
            'revenue' => [
                ['column' => 'amount', 'inferred_type' => 'integer', 'sample_values' => ['5000']],
            ],
            'campaign' => [],
        ];
    }

    private function action(): ValidateColumnMappingAction
    {
        return new ValidateColumnMappingAction;
    }

    // --- confidence: required ------------------------------------------

    public function test_required_field_with_high_confidence_is_mapped_and_satisfies_the_requirement(): void
    {
        $result = $this->action()->execute(
            [['field' => 'channel', 'column' => '媒体', 'confidence' => 'high']],
            $this->fields(),
            $this->columnCandidates(),
            ['channel'],
            [],
        );

        $this->assertSame('mapped', $result['mapping']['channel']['status']);
        $this->assertSame('媒体', $result['mapping']['channel']['column']);
        $this->assertSame([], $result['missing_required_fields']);
    }

    public function test_required_field_with_low_confidence_is_not_used_and_fails_the_requirement(): void
    {
        $result = $this->action()->execute(
            [['field' => 'channel', 'column' => '媒体', 'confidence' => 'low']],
            $this->fields(),
            $this->columnCandidates(),
            ['channel'],
            [],
        );

        $this->assertSame('ignored', $result['mapping']['channel']['status']);
        $this->assertSame(['channel'], $result['missing_required_fields']);
    }

    public function test_required_field_with_unmapped_confidence_fails_the_requirement(): void
    {
        $result = $this->action()->execute(
            [['field' => 'channel', 'column' => null, 'confidence' => 'unmapped']],
            $this->fields(),
            $this->columnCandidates(),
            ['channel'],
            [],
        );

        $this->assertSame('unmapped', $result['mapping']['channel']['status']);
        $this->assertSame(['channel'], $result['missing_required_fields']);
    }

    public function test_a_required_field_never_mentioned_by_the_ai_fails_the_requirement(): void
    {
        $result = $this->action()->execute(
            [],
            $this->fields(),
            $this->columnCandidates(),
            ['channel'],
            [],
        );

        $this->assertSame('unmapped', $result['mapping']['channel']['status']);
        $this->assertSame(['channel'], $result['missing_required_fields']);
    }

    // --- confidence: optional ------------------------------------------

    public function test_optional_field_with_high_confidence_is_mapped(): void
    {
        $result = $this->action()->execute(
            [['field' => 'campaign', 'column' => '媒体', 'confidence' => 'high']],
            $this->fields(),
            ['campaign' => [['column' => '媒体', 'inferred_type' => 'string', 'sample_values' => []]]] + $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('mapped', $result['mapping']['campaign']['status']);
    }

    public function test_optional_field_with_low_confidence_is_not_used_but_does_not_fail_anything(): void
    {
        $result = $this->action()->execute(
            [['field' => 'campaign', 'column' => '媒体', 'confidence' => 'low']],
            $this->fields(),
            ['campaign' => [['column' => '媒体', 'inferred_type' => 'string', 'sample_values' => []]]] + $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('ignored', $result['mapping']['campaign']['status']);
        $this->assertSame([], $result['missing_required_fields']);
        $this->assertSame([], $result['missing_required_field_groups']);
    }

    public function test_optional_field_with_unmapped_confidence_is_simply_unmapped(): void
    {
        $result = $this->action()->execute(
            [['field' => 'campaign', 'column' => null, 'confidence' => 'unmapped']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('unmapped', $result['mapping']['campaign']['status']);
    }

    // --- duplicate / ambiguous mapping -----------------------------------

    public function test_high_and_low_claiming_the_same_column_keeps_only_the_high_one(): void
    {
        $result = $this->action()->execute(
            [
                ['field' => 'revenue', 'column' => 'amount', 'confidence' => 'high'],
                ['field' => 'spend', 'column' => 'amount', 'confidence' => 'low'],
            ],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('mapped', $result['mapping']['revenue']['status']);
        $this->assertSame('ignored', $result['mapping']['spend']['status']);
    }

    public function test_two_high_confidence_claims_on_the_same_column_are_both_ambiguous(): void
    {
        $result = $this->action()->execute(
            [
                ['field' => 'revenue', 'column' => 'amount', 'confidence' => 'high'],
                ['field' => 'spend', 'column' => 'amount', 'confidence' => 'high'],
            ],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('ambiguous', $result['mapping']['revenue']['status']);
        $this->assertSame('ambiguous', $result['mapping']['spend']['status']);
    }

    public function test_a_required_field_that_becomes_ambiguous_fails_the_requirement(): void
    {
        $result = $this->action()->execute(
            [
                ['field' => 'revenue', 'column' => 'amount', 'confidence' => 'high'],
                ['field' => 'spend', 'column' => 'amount', 'confidence' => 'high'],
            ],
            $this->fields(),
            $this->columnCandidates(),
            ['spend'],
            [],
        );

        $this->assertSame(['spend'], $result['missing_required_fields']);
    }

    // --- unknown candidate --------------------------------------------

    public function test_a_column_not_present_in_that_fields_candidates_is_forced_unmapped(): void
    {
        $result = $this->action()->execute(
            [['field' => 'channel', 'column' => 'some_hallucinated_column', 'confidence' => 'high']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('unmapped', $result['mapping']['channel']['status']);
        $this->assertNull($result['mapping']['channel']['column']);
    }

    public function test_a_column_valid_for_a_different_field_is_still_unknown_for_this_field(): void
    {
        // "amount" is a known candidate for revenue/spend, but not for channel.
        $result = $this->action()->execute(
            [['field' => 'channel', 'column' => 'amount', 'confidence' => 'high']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame('unmapped', $result['mapping']['channel']['status']);
    }

    public function test_an_unknown_field_name_in_the_response_is_ignored_entirely(): void
    {
        $result = $this->action()->execute(
            [['field' => 'not_a_real_field', 'column' => '媒体', 'confidence' => 'high']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertArrayNotHasKey('not_a_real_field', $result['mapping']);
    }

    // --- required_field_groups -----------------------------------------

    public function test_required_field_group_is_satisfied_when_at_least_one_member_is_mapped(): void
    {
        $result = $this->action()->execute(
            [['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [['spend', 'revenue']],
        );

        $this->assertSame([], $result['missing_required_field_groups']);
    }

    public function test_required_field_group_fails_when_no_member_is_mapped(): void
    {
        $result = $this->action()->execute(
            [['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'low']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [['spend', 'revenue']],
        );

        $this->assertSame([0], $result['missing_required_field_groups']);
    }

    public function test_multiple_required_field_groups_are_each_checked_independently(): void
    {
        $result = $this->action()->execute(
            [['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high']],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [['spend', 'revenue'], ['channel', 'campaign']],
        );

        $this->assertSame([1], $result['missing_required_field_groups']);
    }

    // --- completeness ----------------------------------------------------

    public function test_every_declared_field_is_present_in_the_result_even_if_never_mentioned(): void
    {
        $result = $this->action()->execute(
            [],
            $this->fields(),
            $this->columnCandidates(),
            [],
            [],
        );

        $this->assertSame(
            array_keys($this->fields()),
            array_keys($result['mapping']),
        );
    }
}
