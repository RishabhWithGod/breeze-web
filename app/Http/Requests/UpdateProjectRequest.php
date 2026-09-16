<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            // A project is always for somebody.
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'estimate_target_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            /*
             * More rate lists, folded into the ones the project already has —
             * see StoreProjectRequest for why these formats and this limit.
             */
            'vendor_rate_list' => ['nullable', 'array', 'max:20'],
            'vendor_rate_list.*' => ['file', 'mimes:xlsx,pdf,doc,docx,rtf,odt', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Project name is required',
            'client_id.required' => 'Pick the client this project is for',
            'name.min' => 'Use at least 3 characters',
            'estimate_target_total.numeric' => 'Enter a valid amount',
            'estimate_target_total.min' => 'Amount cannot be negative',
            'vendor_rate_list.max' => 'Upload up to 20 files at a time',
            'vendor_rate_list.*.mimes' => 'Upload an Excel, PDF, or Word (.doc/.docx) rate list',
            'vendor_rate_list.*.max' => 'Each file must be smaller than 10MB',
        ];
    }
}
