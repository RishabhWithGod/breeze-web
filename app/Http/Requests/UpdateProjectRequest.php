<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\CompanyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit Project: the same fields Add Project takes, corrected rather than
 * retyped from scratch.
 *
 * Mirrors {@see StoreProjectRequest} exactly — same fields, same limits — so
 * the two screens can never drift apart on what a project is allowed to be.
 */
class UpdateProjectRequest extends FormRequest
{
    /** The form posts an empty string when the field is left blank. */
    protected function prepareForValidation(): void
    {
        if ($this->input('estimate_target_total') === '') {
            $this->merge(['estimate_target_total' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            // A short line under the name — what the reference calls "Description".
            'description' => ['nullable', 'string', 'max:255'],
            // A project is always for somebody.
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            /*
             * Who from the client's crew is staffed to it. Optional, and
             * narrowed server-side to that client's own team — see
             * ProjectController::update().
             */
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', CompanyRule::exists('foremen')],
            'project_type' => ['nullable', Rule::in(Project::TYPES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimate_target_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            /*
             * More rate lists, folded into the ones the project already has —
             * see StoreProjectRequest for why these formats and this limit.
             */
            'vendor_rate_list' => ['nullable', 'array', 'max:20'],
            'vendor_rate_list.*' => ['file', 'mimes:xlsx,ods,csv,txt,pdf,doc,docx,rtf,odt', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Project name is required',
            'client_id.required' => 'Pick the client this project is for',
            'name.min' => 'Use at least 3 characters',
            'end_date.after_or_equal' => 'End date cannot be before the start date',
            'estimate_target_total.numeric' => 'Enter a valid amount',
            'estimate_target_total.min' => 'Amount cannot be negative',
            'vendor_rate_list.max' => 'Upload up to 20 files at a time',
            'vendor_rate_list.*.mimes' => 'Upload an Excel, CSV, PDF, or Word (.doc/.docx) commodity list',
            'vendor_rate_list.*.max' => 'Each file must be smaller than 10MB',
        ];
    }
}
