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
            /*
             * The takeoff this paperwork is filed under — one of this
             * manager's own, so a hand-made request cannot file a document
             * into another manager's project (where `DocumentPolicy` would
             * then hide it from the uploader anyway).
             */
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where('user_id', $this->user()->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            /*
             * Not asked for on the form any more. Still accepted so a caller
             * that knows the answer can say so, and defaulted below when it
             * does not — the columns are not nullable and every document has
             * to land somewhere sensible.
             */
            'document_type' => ['nullable', Rule::in(Document::TYPES)],
            'job_id' => ['nullable', 'integer', 'exists:work_jobs,id'],
            'estimate_id' => ['nullable', 'integer', 'exists:estimates,id'],
            'visibility' => ['nullable', Rule::in([Document::VISIBILITY_TEAM, Document::VISIBILITY_PRIVATE])],
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
        ];
    }
}
