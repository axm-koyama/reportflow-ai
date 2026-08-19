<?php

declare(strict_types=1);

namespace App\Http\Requests\AnalysisJob;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:5000'],
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
     * Get the validated analysis prompt.
     */
    public function prompt(): string
    {
        return (string) $this->validated('prompt');
    }
}
