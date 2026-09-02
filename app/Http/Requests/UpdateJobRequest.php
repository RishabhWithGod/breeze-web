<?php

namespace App\Http\Requests;

use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobRequest extends FormRequest
{
    /**
     * Editing adds `status` — created jobs get theirs from the intake flow, but
     * an existing job can be moved through the pipeline here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            /** The client, picked from the client register — see ClientDirectory. */
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            /*
             * The client sites this job is at — one or more of the client's own
             * addresses. `location` is not posted: it is written from the first
             * of these, so the two can never disagree.
             */
            'address_ids' => ['required', 'array', 'min:1', 'max:25'],
            'address_ids.*' => ['integer', 'distinct', 'exists:client_addresses,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            'status' => ['required', Rule::in(Job::STATUSES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'foreman_id' => ['nullable', 'integer', 'exists:foremen,id'],
            'create_estimate' => ['boolean'],
            'assign_team' => ['boolean'],
            'notify_client' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Job name is required',
            'name.min' => 'Use at least 3 characters',
            'project_id.required' => 'Client is required',
            'project_id.exists' => 'Pick a client from the list',
            'address_ids.required' => 'Pick at least one site for this job',
            'address_ids.*.exists' => 'That site is not on the client\'s record',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
            'budget.gt' => 'Enter an amount greater than zero',
        ];
    }
}
