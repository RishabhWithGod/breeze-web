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
            /** Who the work is for, from the client register. */
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            /*
             * And which of their projects it is on. Every drawing, takeoff and
             * estimate hangs off a project, so the job does too — the client
             * alone cannot say which set of drawings this is.
             */
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            /*
             * The site this job is at, one of its project's own. `location` is
             * not posted: it is written from this, so the two cannot disagree.
             *
             * One site per job: the screen offers radios, and the rule has to
             * agree with it or a hand-made request could still send several.
             */
            'address_ids' => ['required', 'array', 'size:1'],
            'address_ids.*' => ['integer', 'distinct', 'exists:client_addresses,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            /*
             * The crew this job is handed to. Optional: work is often raised
             * before anyone knows who will run it. Once it is set, it narrows
             * who a task on this job can be given to.
             */
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'status' => ['required', Rule::in(Job::STATUSES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            // No foreman on a job: work is handed to someone task by task, so
            // the edit form does not offer one and nothing may set one here.
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
            'client_id.required' => 'Client is required',
            'client_id.exists' => 'Pick a client from the list',
            'project_id.required' => 'Pick the project this job is on',
            'project_id.exists' => 'Pick a project from the list',
            'address_ids.required' => 'Pick the site this job runs at',
            'address_ids.size' => 'A job runs at one site',
            'address_ids.*.exists' => 'That site is not on the project\'s record',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
            'budget.gt' => 'Enter an amount greater than zero',
        ];
    }
}
