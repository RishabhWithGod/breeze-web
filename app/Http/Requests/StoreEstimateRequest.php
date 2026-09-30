<?php

namespace App\Http\Requests;

use App\Models\Estimate;
use App\Support\Ownership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = Ownership::userIdList($this->user());

        return [
            'issued_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            /*
             * What the estimate is on. Every drawing and takeoff hangs off a
             * project, so the estimate does too — and it is the one thing that
             * has to be answered, because the client is read from it. Must be
             * one of this manager's own.
             */
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereIn('user_id', $userId)],
            /*
             * Who it is for. Sent by the form because that is the field the
             * project list is narrowed by, but not required: a project belongs
             * to exactly one client, and the client column is derived from it.
             */
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereIn('user_id', $userId)],
            /** The drawing it is priced from, one of that project's own. */
            'upload_id' => [
                'nullable', 'integer',
                Rule::exists('uploads', 'id')->where(
                    fn ($query) => $query->whereIn('project_id', fn ($sub) => $sub->select('id')->from('projects')->whereIn('user_id', $userId))
                ),
            ],
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
