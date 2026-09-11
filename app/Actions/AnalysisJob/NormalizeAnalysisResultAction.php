<?php

declare(strict_types=1);

namespace App\Actions\AnalysisJob;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

/**
 * Converts a raw AI response into ReportFlow AI's V1 Result Schema.
 *
 * The AI provider is expected to return a JSON object already shaped like
 * the target result schema (see docs/product/AI_CONTEXT.md §11-§19 and
 * docs/product/AI_ANALYSIS.md §16). This action validates that shape and
 * fills in missing optional sections; it does not attempt to parse
 * free-form text or strip Markdown code fences (the Structured Output
 * assumption is maintained as-is).
 *
 * Out of scope: calling the AI again, DB updates, AnalysisJob status
 * changes, report generation, queue management.
 */
class NormalizeAnalysisResultAction
{
    /**
     * Allowed `recommendations[].priority` values (null is also allowed
     * and is handled separately, not listed here).
     *
     * @var list<string>
     */
    private const array ALLOWED_PRIORITIES = ['high', 'medium', 'low'];

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException if the raw response is not valid JSON
     *                                  or does not match the expected result shape.
     */
    public function execute(string $rawResponse, bool $decisionEnabled = false): array
    {
        $decoded = $this->decode($rawResponse);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('AI response must decode to a JSON object.');
        }

        $recommendations = $this->objectList($decoded, 'recommendations', $this->normalizeRecommendation(...));

        if ($decisionEnabled && $recommendations !== []) {
            Log::warning('NormalizeAnalysisResultAction: stripped legacy recommendations from a Decision-enabled analysis.', [
                'recommendation_count' => count($recommendations),
            ]);

            $recommendations = [];
        }

        return [
            'summary' => $this->requiredString($decoded, 'summary', 'AI response'),
            'highlights' => $this->stringList($decoded, 'highlights'),
            'metrics' => $this->objectList($decoded, 'metrics', $this->normalizeMetric(...)),
            'tables' => $this->objectList($decoded, 'tables', $this->normalizeTable(...)),
            'insights' => $this->objectList($decoded, 'insights', $this->normalizeInsight(...)),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Decode the raw AI response as an associative array.
     *
     * @throws InvalidArgumentException if the response is not valid JSON.
     */
    private function decode(string $rawResponse): mixed
    {
        try {
            return json_decode($rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('AI response is not valid JSON.', previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeMetric(array $item): array
    {
        return [
            'label' => $this->requiredString($item, 'label', 'AI response "metrics" item'),
            'value' => $this->requiredString($item, 'value', 'AI response "metrics" item'),
            'unit' => $this->optionalNullableString($item, 'unit', 'AI response "metrics" item'),
            'change' => $this->optionalNullableString($item, 'change', 'AI response "metrics" item'),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeTable(array $item): array
    {
        $columns = $this->requiredStringArray($item, 'columns', 'AI response "tables" item');
        $rows = $this->requiredRows($item);

        foreach ($rows as $row) {
            if (count($row) !== count($columns)) {
                throw new InvalidArgumentException(
                    'AI response "tables" item row cell count must match the "columns" count.',
                );
            }
        }

        return [
            'title' => $this->requiredString($item, 'title', 'AI response "tables" item'),
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeInsight(array $item): array
    {
        return [
            'title' => $this->requiredString($item, 'title', 'AI response "insights" item'),
            'description' => $this->requiredString($item, 'description', 'AI response "insights" item'),
            'evidence' => $this->optionalNullableString($item, 'evidence', 'AI response "insights" item'),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeRecommendation(array $item): array
    {
        return [
            'title' => $this->requiredString($item, 'title', 'AI response "recommendations" item'),
            'description' => $this->requiredString($item, 'description', 'AI response "recommendations" item'),
            'priority' => $this->recommendationPriority($item),
        ];
    }

    /**
     * Read a required, non-null string field.
     *
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $field, string $context): string
    {
        if (! isset($data[$field]) || ! is_string($data[$field])) {
            throw new InvalidArgumentException("{$context} is missing a valid \"{$field}\" string.");
        }

        return $data[$field];
    }

    /**
     * Read an optional, nullable string field. Missing and explicit null
     * both normalize to null; any non-string, non-null value is rejected.
     *
     * @param  array<string, mixed>  $data
     */
    private function optionalNullableString(array $data, string $field, string $context): ?string
    {
        if (! array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (! is_string($data[$field])) {
            throw new InvalidArgumentException("{$context} \"{$field}\" must be a string or null.");
        }

        return $data[$field];
    }

    /**
     * Read a required array field whose values must all be strings.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function requiredStringArray(array $data, string $field, string $context): array
    {
        if (! isset($data[$field]) || ! is_array($data[$field])) {
            throw new InvalidArgumentException("{$context} is missing a valid \"{$field}\" array.");
        }

        foreach ($data[$field] as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("{$context} \"{$field}\" must contain only strings.");
            }
        }

        return array_values($data[$field]);
    }

    /**
     * Read a table's required "rows": an array of arrays of strings.
     *
     * @param  array<string, mixed>  $item
     * @return list<list<string>>
     */
    private function requiredRows(array $item): array
    {
        if (! isset($item['rows']) || ! is_array($item['rows'])) {
            throw new InvalidArgumentException('AI response "tables" item is missing a valid "rows" array.');
        }

        $rows = [];

        foreach ($item['rows'] as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('AI response "tables" item "rows" must contain only arrays.');
            }

            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    throw new InvalidArgumentException('AI response "tables" row cells must be strings.');
                }
            }

            $rows[] = array_values($row);
        }

        return $rows;
    }

    /**
     * Read a recommendation's "priority": one of "high"/"medium"/"low", or
     * null (missing and explicit null both normalize to null).
     *
     * @param  array<string, mixed>  $item
     */
    private function recommendationPriority(array $item): ?string
    {
        if (! array_key_exists('priority', $item) || $item['priority'] === null) {
            return null;
        }

        if (! is_string($item['priority']) || ! in_array($item['priority'], self::ALLOWED_PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'AI response "recommendations" item "priority" must be "high", "medium", "low", or null.',
            );
        }

        return $item['priority'];
    }

    /**
     * Read an optional array-of-strings section, defaulting to an empty list.
     *
     * @param  array<string, mixed>  $decoded
     * @return list<string>
     */
    private function stringList(array $decoded, string $key): array
    {
        if (! array_key_exists($key, $decoded)) {
            return [];
        }

        if (! is_array($decoded[$key])) {
            throw new InvalidArgumentException("AI response \"{$key}\" must be an array.");
        }

        foreach ($decoded[$key] as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(
                    "AI response \"{$key}\" must contain only strings.",
                );
            }
        }

        return array_values($decoded[$key]);
    }

    /**
     * Read an optional array-of-objects section, defaulting to an empty
     * list. Each item must itself be an array (object); $normalizeItem
     * validates and normalizes one item, throwing InvalidArgumentException
     * if it is invalid.
     *
     * Unknown keys within an item are not preserved: only the fields
     * $normalizeItem extracts end up in the output, consistent with how
     * unknown top-level keys are also dropped (see execute()).
     *
     * @param  array<string, mixed>  $decoded
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalizeItem
     * @return list<array<string, mixed>>
     */
    private function objectList(array $decoded, string $key, callable $normalizeItem): array
    {
        if (! array_key_exists($key, $decoded)) {
            return [];
        }

        if (! is_array($decoded[$key])) {
            throw new InvalidArgumentException("AI response \"{$key}\" must be an array.");
        }

        $items = [];

        foreach ($decoded[$key] as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("AI response \"{$key}\" items must be objects.");
            }

            $items[] = $normalizeItem($item);
        }

        return $items;
    }
}
