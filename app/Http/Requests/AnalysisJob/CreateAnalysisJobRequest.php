<?php

declare(strict_types=1);

namespace App\Http\Requests\AnalysisJob;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAnalysisJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * template_key is nullable: omitting it (or leaving the form's
     * Template select on "自由分析") requests free-form analysis, exactly
     * as before Analysis Templates existed. When present, it must be a
     * key that actually exists in config('analysis_templates') — an
     * unknown key is rejected here, before an AnalysisJob is ever
     * created, rather than surfacing later as a Job failure.
     *
     * prompt is required only when template_key is absent
     * ("required_without"), preserving free-form analysis's original
     * "prompt is always required" behavior exactly. When a Template is
     * selected, prompt is the user's optional additional request; the
     * global ConvertEmptyStringsToNull middleware turns a blank submitted
     * value into null before validation runs, and prompt() below casts
     * that back to '' (never null) for storage.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'template_key' => ['nullable', 'string', Rule::in(array_keys(config('analysis_templates')))],
            'prompt' => ['required_without:template_key', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get the validated analysis job title.
     */
    public function title(): string
    {
        return (string) $this->validated('title');
    }

    /**
     * Get the validated Analysis Template key, or null for free-form analysis.
     */
    public function templateKey(): ?string
    {
        $templateKey = $this->validated('template_key');

        return is_string($templateKey) && $templateKey !== '' ? $templateKey : null;
    }

    /**
     * Get the validated analysis prompt: the user's own free-form
     * analysis request, or (when a Template is selected) their optional
     * additional request. Never null — an omitted/blank prompt becomes ''.
     */
    public function prompt(): string
    {
        return (string) $this->validated('prompt');
    }
}
