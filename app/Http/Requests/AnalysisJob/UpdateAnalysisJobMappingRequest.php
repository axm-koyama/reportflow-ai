<?php

declare(strict_types=1);

namespace App\Http\Requests\AnalysisJob;

use App\Models\AnalysisJob;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the HTTP shape of a Mapping Preview manual-override submission
 * (Phase 3-C). See docs/product/MAPPING_CONTROL.md.
 *
 * This validates *shape only*:
 * - "mapping" is an array
 * - every submitted key is a semantic field that actually exists on this
 *   AnalysisJob's Template (an unknown/hallucinated field name is
 *   rejected outright, not silently ignored — see
 *   docs/product/MAPPING_CONTROL.md "Security")
 * - each field's "column" is nullable|string
 *
 * It deliberately does NOT validate:
 * - whether "column" actually exists as a real CSV column
 * - whether "column"'s inferred type matches the field's "kind"
 * - duplicate/cross-source ambiguity
 * - required_fields/required_field_groups satisfaction
 *
 * All of the above are ResolveEffectiveColumnMappingAction's (i.e.
 * ValidateColumnMappingAction's, reused) responsibility — this class only
 * guards the HTTP input's shape, per the same "FormRequest validates HTTP
 * input; business rules belong to the Action" split
 * CreateAnalysisJobRequest already follows. Only {field: column} pairs
 * are ever accepted from the client — column_candidates, inferred_type,
 * confidence, status, and source are always rebuilt/decided server-side
 * and are never read from client input.
 */
class UpdateAnalysisJobMappingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Route model binding for both {project} and {analysisJob} happens
     * before a FormRequest is resolved, so this is the earliest point at
     * which cross-project / no-template access can be rejected (a 403,
     * before validation rules even run) — earlier than
     * AnalysisJobController::updateMapping()'s own body-level guards,
     * which exist for defense in depth and to keep parity with
     * editMapping()'s (FormRequest-free) 404s.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $analysisJob = $this->route('analysisJob');

        if (! $project instanceof Project || ! $analysisJob instanceof AnalysisJob) {
            return false;
        }

        if ($analysisJob->template_key === null) {
            // Free Analysis never has a Column Mapping to confirm.
            return false;
        }

        $dataFile = $analysisJob->dataFile;

        return $dataFile !== null && $dataFile->project_id === $project->project_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['present', 'array'],
            'mapping.*' => ['array'],
            'mapping.*.column' => ['nullable', 'string'],
        ];
    }

    /**
     * Reject any submitted semantic field key that is not actually
     * declared on this AnalysisJob's Template — Laravel's array/wildcard
     * rules validate the shape of known keys but never reject *extra*,
     * unexpected ones, so this needs an explicit check. authorize()
     * above already guarantees $analysisJob has a Template by the time
     * this runs.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var AnalysisJob $analysisJob */
            $analysisJob = $this->route('analysisJob');

            $knownFields = array_keys(config("analysis_templates.{$analysisJob->template_key}.fields", []));
            $submittedFields = array_keys((array) $this->input('mapping', []));

            foreach ($submittedFields as $field) {
                if (! is_string($field) || ! in_array($field, $knownFields, true)) {
                    $validator->errors()->add('mapping', "Unknown semantic field \"{$field}\".");
                }
            }
        });
    }

    /**
     * The submitted sparse manual overrides, reduced to
     * {field: {column: string|null}} — only fields whose submitted value
     * actually *differs* from the AI's own default for that field.
     *
     * The Mapping Preview form (resources/views/analysis-jobs/mapping.blade.php)
     * renders one <select> per field, every one pre-filled with a value
     * (the AI's mapped column, or "(未設定)"), and a native HTML form
     * submits *all* of them regardless of whether the user touched a
     * given field — there is no browser-native way to submit only the
     * fields a user interacted with. Naively treating "present in the
     * submitted mapping" as "the user has an opinion" would therefore tag
     * every field "source: manual" on every confirm, even ones the user
     * never looked at, defeating the whole point of the
     * ai-vs-manual Auditability distinction (docs/product/MAPPING_CONTROL.md
     * §15). This diff against the AI's own already-persisted
     * column_mapping is what makes "the user actually changed this
     * field" the real, correct definition of "touched" — confirmed by
     * this behavior during Browser E2E testing, where every field showed
     * up as "manual" before this fix.
     *
     * Every value has already passed shape validation (nullable|string);
     * existence/type/duplicate/required validation happens later via
     * ResolveEffectiveColumnMappingAction.
     *
     * @return array<string, array{column: string|null}>
     */
    public function manualOverrides(): array
    {
        /** @var AnalysisJob $analysisJob */
        $analysisJob = $this->route('analysisJob');
        $aiMapping = $analysisJob->analysisJobDetail?->column_mapping ?? [];

        $overrides = [];

        foreach ((array) $this->validated('mapping', []) as $field => $entry) {
            $column = $entry['column'] ?? null;
            $aiEntry = $aiMapping[$field] ?? null;
            $aiColumn = ($aiEntry['status'] ?? null) === 'mapped' ? $aiEntry['column'] : null;

            if ($column === $aiColumn) {
                // Identical to what the AI already proposed (including
                // "both unmapped") -> the user left this field alone.
                continue;
            }

            $overrides[$field] = ['column' => $column];
        }

        return $overrides;
    }
}
