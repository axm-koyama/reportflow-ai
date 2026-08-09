<?php

declare(strict_types=1);

namespace App\Http\Requests\DataFile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

class StoreDataFileRequest extends FormRequest
{
    /**
     * The maximum accepted upload size, in kilobytes (10 MB).
     */
    private const int MAX_FILE_SIZE_KB = 10 * 1024;

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
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_SIZE_KB, 'extensions:csv'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if (! $file instanceof UploadedFile) {
                    return;
                }

                if (blank($file->getClientOriginalName())) {
                    $validator->errors()->add('file', 'The file must have a readable original file name.');
                }

                if (($file->getSize() ?? 0) <= 0) {
                    $validator->errors()->add('file', 'The file must not be empty.');
                }
            },
        ];
    }

    /**
     * Get the validated uploaded file.
     *
     * @return UploadedFile
     * @throws LogicException
     */
    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('Validated uploaded file is missing.');
        }

        return $file;
    }
}
