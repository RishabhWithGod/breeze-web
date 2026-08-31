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
            'client' => ['required', 'string', 'max:120'],
            'project' => ['required', 'string', 'max:160'],
            'issued_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            /** Links this estimate back to a drawing already run through AI Takeoff. */
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'upload_id' => ['nullable', 'integer', 'exists:uploads,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client.required' => 'Client is required',
            'project.required' => 'Project is required',
            'issued_on.required' => 'Date is required',
            'amount.required' => 'Amount is required',
        ];
    }
}
