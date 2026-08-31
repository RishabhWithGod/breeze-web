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
            'client' => ['required', 'string', 'max:120'],
            'location' => ['required', 'string', 'max:160'],
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
            'client.required' => 'Client is required',
            'location.required' => 'Location is required',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
            'budget.gt' => 'Enter an amount greater than zero',
        ];
    }
}
