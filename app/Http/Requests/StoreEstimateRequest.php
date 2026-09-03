<?php

namespace App\Http\Requests;

use App\Models\Estimate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'issued_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            /** The client, picked from the client register — see ClientDirectory. */
            // Who the work is for. What it is on is the project, which an
            // estimate or invoice inherits from the job it belongs to.
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'upload_id' => ['nullable', 'integer', 'exists:uploads,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Client is required',
            'client_id.exists' => 'Pick a client from the list',
            'issued_on.required' => 'Date is required',
            'amount.required' => 'Amount is required',
        ];
    }
}
