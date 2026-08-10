<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DefinesProjectDocuments;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Project: the project's own details, plus the drawing PDFs defined with
 * it.
 *
 * Only the name and the client are required — a project is opened before its
 * drawing set is complete, and PDFs can be added from the project screen
 * afterwards. Whatever is sent is held to the same file rules the AI takeoff
 * upload enforces, so a drawing defined here can be analysed as it stands.
 */
class StoreProjectRequest extends FormRequest
{
    use DefinesProjectDocuments;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'code' => ['nullable', 'string', 'max:60'],
            'client' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'discipline' => ['nullable', 'string', 'max:60'],
            'project_type' => ['nullable', Rule::in(Project::TYPES)],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            ...$this->documentRules(required: false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Project name is required',
            'name.min' => 'Use at least 3 characters',
            'client.required' => 'Client is required',
            ...$this->documentMessages(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'project number',
            'project_type' => 'project type',
            'due_date' => 'due date',
        ];
    }
}
