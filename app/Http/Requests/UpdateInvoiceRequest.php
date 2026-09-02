<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The client, picked from the client register — see ClientDirectory. */
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'job_id' => ['nullable', 'integer', 'exists:work_jobs,id'],
            'estimate_id' => ['nullable', 'integer', 'exists:estimates,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Client is required',
            'project_id.exists' => 'Pick a client from the list',
            'invoice_date.required' => 'Invoice date is required',
            'due_date.after_or_equal' => 'Due date cannot be before the invoice date',
        ];
    }
}
