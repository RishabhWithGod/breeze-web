<?php

namespace App\Http\Requests;

use App\Support\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUploadRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $limits = config('takeoff.uploads');

        return [
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where('user_id', $this->user()->id),
            ],
            'files' => ['required', 'array', 'min:1', 'max:'.$limits['max_files']],
            'files.*' => [
                'required',
                'file',
                // The effective limit, not the configured one: PHP's own ceiling
                // sits underneath and would drop the file first.
                'max:'.(UploadLimits::effectiveMb() * 1024),
                Rule::file()->extensions($limits['extensions']),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $limits = config('takeoff.uploads');

        return [
            'project_id.required' => 'Select a project before running a takeoff.',
            'project_id.exists' => 'That project could not be found.',
            'files.required' => 'Add at least one supported drawing file before running a takeoff.',
            'files.max' => $limits['max_files'] === 1
                ? 'Only one file can be queued at a time.'
                : "Only {$limits['max_files']} files can be queued at once.",
            'files.*.max' => 'Each file must be under '.UploadLimits::effectiveMb().' MB.'
                .(UploadLimits::phpHint() === null ? '' : ' '.UploadLimits::phpHint()),
            // A file over PHP's own limit arrives empty, which fails `file`.
            'files.*.file' => 'That file did not arrive intact — it is probably over PHP\'s '
                .UploadLimits::phpMb().' MB upload limit.',
            'files.*.extensions' => 'Unsupported format — accepted types are '
                .implode(', ', array_map(fn (string $ext) => ".{$ext}", $limits['extensions'])),
            'notes.max' => 'Notes are limited to 500 characters',
        ];
    }
}
