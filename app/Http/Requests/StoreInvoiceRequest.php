<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            /** The client, picked from the client register — see ClientDirectory. */
            // Who the work is for. What it is on is the project, which an
            // estimate or invoice inherits from the job it belongs to.
            //
            // Each of these must be one of this manager's own rows: refused
            // here rather than trusted, so a hand-made request cannot raise an
            // invoice against another manager's client, job or estimate.
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('user_id', $userId)],
            'job_id' => ['nullable', 'integer', Rule::exists('work_jobs', 'id')->where('user_id', $userId)],
            'estimate_id' => ['nullable', 'integer', Rule::exists('estimates', 'id')->where('user_id', $userId)],
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
            'client_id.required' => 'Client is required',
            'client_id.exists' => 'Pick a client from the list',
            'invoice_date.required' => 'Invoice date is required',
            'due_date.after_or_equal' => 'Due date cannot be before the invoice date',
        ];
    }
}
