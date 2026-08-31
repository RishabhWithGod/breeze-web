<?php

namespace App\Http\Requests;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentVersionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(UploadLimits::effectiveMb() * 1024),
                Rule::file()->extensions(config('documents.extensions')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the updated file to upload.',
            'file.max' => 'The file must be under '.UploadLimits::effectiveMb().' MB.',
            'file.extensions' => 'Unsupported file type — accepted types are '
                .implode(', ', array_map(fn (string $ext) => ".{$ext}", config('documents.extensions'))),
        ];
    }
}
