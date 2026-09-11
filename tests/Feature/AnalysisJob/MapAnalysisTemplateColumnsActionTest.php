<?php

declare(strict_types=1);

namespace Tests\Feature\AnalysisJob;

use App\Actions\AnalysisJob\MapAnalysisTemplateColumnsAction;
use App\AI\AiAnalysisClient;
use InvalidArgumentException;
use Tests\TestCase;

class MapAnalysisTemplateColumnsActionTest extends TestCase
{
    /**
     * @return list<array{field: string, kind: string, label: string}>
     */
    private function templateFields(): array
    {
        return [
            ['field' => 'channel', 'kind' => 'dimension', 'label' => 'チャネル'],
            ['field' => 'spend', 'kind' => 'measure', 'label' => '広告費'],
        ];
    }

    /**
     * @return array<string, list<array{column: string, inferred_type: string, sample_values: list<string>}>>
     */
    private function columnCandidates(): array
    {
        return [
            'channel' => [
                ['column' => '媒体', 'inferred_type' => 'string', 'sample_values' => ['Email', 'Paid Search']],
            ],
            'spend' => [
                ['column' => '広告コスト', 'inferred_type' => 'integer', 'sample_values' => ['120000', '150000']],
            ],
        ];
    }

    public function test_it_sends_user_prompt_template_fields_and_column_candidates(): void
    {
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(json_encode(['mappings' => []], JSON_THROW_ON_ERROR));

        app(MapAnalysisTemplateColumnsAction::class)->execute(
            '特にPaid Searchを詳しく見たい',
            $this->templateFields(),
            $this->columnCandidates(),
        );

        $this->assertIsArray($capturedContext);
        $this->assertSame('特にPaid Searchを詳しく見たい', $capturedContext['user_prompt']);
        $this->assertSame($this->templateFields(), $capturedContext['template_fields']);
        $this->assertSame($this->columnCandidates(), $capturedContext['column_candidates']);
        $this->assertIsString($capturedContext['system_instruction']);
        $this->assertNotSame('', $capturedContext['system_instruction']);
    }

    /**
     * sample_values in column_candidates are exactly what is forwarded —
     * this action never re-derives or trims them itself.
     */
    public function test_sample_values_are_forwarded_unchanged(): void
    {
        $capturedContext = null;

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->withArgs(function (array $context) use (&$capturedContext): bool {
                $capturedContext = $context;

                return true;
            })
            ->andReturn(json_encode(['mappings' => []], JSON_THROW_ON_ERROR));

        app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), $this->columnCandidates());

        $this->assertSame(
            ['Email', 'Paid Search'],
            $capturedContext['column_candidates']['channel'][0]['sample_values'],
        );
    }

    public function test_it_returns_the_proposed_mappings_when_the_ai_proposes_some(): void
    {
        $mappings = [
            ['field' => 'channel', 'column' => '媒体', 'confidence' => 'high'],
            ['field' => 'spend', 'column' => '広告コスト', 'confidence' => 'high'],
        ];

        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => $mappings], JSON_THROW_ON_ERROR));

        $result = app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), $this->columnCandidates());

        $this->assertSame($mappings, $result);
    }

    /**
     * An empty mappings response is a normal, valid outcome — not an error.
     */
    public function test_an_empty_mappings_response_is_handled_normally(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => []], JSON_THROW_ON_ERROR));

        $result = app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), $this->columnCandidates());

        $this->assertSame([], $result);
    }

    /**
     * When no semantic field has any candidate at all, there is nothing to
     * map: the AI is never called.
     */
    public function test_the_ai_is_never_called_when_there_are_no_candidates_at_all(): void
    {
        $this->mock(AiAnalysisClient::class)->shouldNotReceive('mapColumns');

        $result = app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), [
            'channel' => [],
            'spend' => [],
        ]);

        $this->assertSame([], $result);
    }

    /**
     * As long as at least one field has a candidate, the AI is still
     * called (it can still resolve that field, and correctly report the
     * rest as unmapped).
     */
    public function test_the_ai_is_called_when_at_least_one_field_has_a_candidate(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => []], JSON_THROW_ON_ERROR));

        app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), [
            'channel' => [['column' => '媒体', 'inferred_type' => 'string', 'sample_values' => []]],
            'spend' => [],
        ]);
    }

    public function test_it_throws_when_the_response_is_not_valid_json(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn('{not valid json');

        $this->expectException(InvalidArgumentException::class);

        app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), $this->columnCandidates());
    }

    public function test_it_throws_when_the_response_has_no_mappings_key(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['something_else' => []], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);

        app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), $this->columnCandidates());
    }

    /**
     * Non-object entries in the AI's mappings list are dropped defensively
     * rather than crashing this action; ValidateColumnMappingAction still
     * validates every remaining field.
     */
    public function test_non_array_entries_in_the_response_are_dropped_defensively(): void
    {
        $this->mock(AiAnalysisClient::class)
            ->shouldReceive('mapColumns')
            ->once()
            ->andReturn(json_encode(['mappings' => ['not-an-object', 123, null]], JSON_THROW_ON_ERROR));

        $result = app(MapAnalysisTemplateColumnsAction::class)->execute('', $this->templateFields(), $this->columnCandidates());

        $this->assertSame([], $result);
    }
}
