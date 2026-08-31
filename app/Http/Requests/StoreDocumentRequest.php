<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // The effective limit, not the configured one: PHP's own ceiling
                // sits underneath and would drop the file first.
                'max:'.(UploadLimits::effectiveMb() * 1024),
                Rule::file()->extensions(config('documents.extensions')),
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'document_type' => ['required', Rule::in(Document::TYPES)],
            'job_id' => ['nullable', 'integer', 'exists:work_jobs,id'],
            'estimate_id' => ['nullable', 'integer', 'exists:estimates,id'],
            'folder_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', Rule::in([Document::VISIBILITY_TEAM, Document::VISIBILITY_PRIVATE])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to upload.',
            'file.max' => 'The file must be under '.UploadLimits::effectiveMb().' MB.'
                .(UploadLimits::phpHint() === null ? '' : ' '.UploadLimits::phpHint()),
            'file.file' => 'That file did not arrive intact — it is probably over PHP\'s '
                .UploadLimits::phpMb().' MB upload limit.',
            'file.extensions' => 'Unsupported file type — accepted types are '
                .implode(', ', array_map(fn (string $ext) => ".{$ext}", config('documents.extensions'))),
            'document_type.required' => 'Choose a document type.',
        ];
    }
}
