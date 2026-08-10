<?php

namespace App\Http\Requests\Concerns;

use App\Support\UploadLimits;
use Illuminate\Validation\Rule;

/**
 * The drawing PDFs defined against a project.
 *
 * Shared by "create a project" and "add drawings to a project" so both accept
 * exactly the same files: the limits are the ones the AI takeoff upload enforces,
 * narrowed to PDF, which is what a project's drawing set is defined as.
 *
 * `document_titles[i]` is the optional label for `documents[i]`; the two arrive as
 * parallel arrays because that is what a multipart form can express.
 */
trait DefinesProjectDocuments
{
    /**
     * @return array<string, mixed>
     */
    protected function documentRules(bool $required): array
    {
        $maxFiles = (int) config('takeoff.uploads.max_files');

        return [
            'documents' => [$required ? 'required' : 'nullable', 'array', 'max:'.$maxFiles],
            'documents.*' => [
                'required',
                'file',
                // The effective limit, not the configured one: PHP's own ceiling
                // sits underneath and would drop the file first.
                'max:'.(UploadLimits::effectiveMb() * 1024),
                Rule::file()->extensions(['pdf']),
            ],
            'document_titles' => ['nullable', 'array', 'max:'.$maxFiles],
            'document_titles.*' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function documentMessages(): array
    {
        $maxFiles = (int) config('takeoff.uploads.max_files');

        return [
            'documents.required' => 'Add at least one PDF.',
            'documents.max' => "Only {$maxFiles} PDFs can be added at once.",
            'documents.*.max' => 'Each PDF must be under '.UploadLimits::effectiveMb().' MB.'
                .(UploadLimits::phpHint() === null ? '' : ' '.UploadLimits::phpHint()),
            // A file over PHP's own limit arrives empty, which fails `file`.
            'documents.*.file' => 'That file did not arrive intact — it is probably over PHP\'s '
                .UploadLimits::phpMb().' MB upload limit.',
            'documents.*.extensions' => 'Project drawings must be PDFs.',
            'document_titles.*.max' => 'A drawing label is limited to 120 characters.',
        ];
    }

    /** What the form advertises: file count and size, plus PHP's ceiling if lower. */
    public static function documentLimits(): array
    {
        return [
            'maxFiles' => (int) config('takeoff.uploads.max_files'),
            'maxFileSizeMb' => UploadLimits::effectiveMb(),
            'serverHint' => UploadLimits::phpHint(),
        ];
    }
}
