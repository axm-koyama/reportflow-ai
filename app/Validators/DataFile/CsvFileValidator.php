<?php

declare(strict_types=1);

namespace App\Validators\DataFile;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CsvFileValidator
{
    /**
     * Validate that the given file is structurally readable as a CSV file.
     *
     * This checks only that the file can be opened, that a first (header)
     * row can be read, and that the header row is not entirely blank.
     * Business-specific column requirements are not validated here.
     *
     * @param UploadedFile $file
     * @return void
     * @throws ValidationException
     */
    public function validate(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if ($path === false) {
            $this->fail('The CSV file could not be read.');
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->fail('The CSV file could not be read.');
        }

        try {
            $header = fgetcsv($handle, escape: '\\');
        } finally {
            fclose($handle);
        }

        if ($header === false) {
            $this->fail('The CSV file could not be read.');
        }

        if (! $this->hasNonEmptyColumn($header)) {
            $this->fail('The CSV file must contain a header row.');
        }
    }

    /**
     * Determine whether the given CSV row contains at least one non-empty column.
     *
     * @param  array<int, string|null>  $row
     * @return bool
     */
    private function hasNonEmptyColumn(array $row): bool
    {
        foreach ($row as $column) {
            if (trim((string) $column) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Throw a validation exception scoped to the "file" field.
     *
     * @param string $message
     * @return never
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'file' => [$message],
        ]);
    }
}
