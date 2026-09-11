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
        $userId = $this->user()->id;

        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            /** Who the work is for, from this manager's own client register. */
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('user_id', $userId)],
            /*
             * And which of their projects it is on. Every drawing, takeoff and
             * estimate hangs off a project, so the job does too — the client
             * alone cannot say which set of drawings this is. Also this
             * manager's own.
             */
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('user_id', $userId)],
            /*
             * The site this job is at, one of its project's own. `location` is
             * not posted: it is written from this, so the two cannot disagree.
             *
             * One site per job: the screen offers radios, and the rule has to
             * agree with it or a hand-made request could still send several.
             * Refused here rather than trusted: the address must be on one of
             * this manager's own clients.
             */
            'address_ids' => ['required', 'array', 'size:1'],
            'address_ids.*' => [
                'integer', 'distinct',
                Rule::exists('client_addresses', 'id')->where(
                    fn ($query) => $query->whereIn('client_id', fn ($sub) => $sub->select('id')->from('clients')->where('user_id', $userId))
                ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            /*
             * The crew this job is handed to. Optional: work is often raised
             * before anyone knows who will run it. Once it is set, it narrows
             * who a task on this job can be given to.
             */
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'status' => ['required', Rule::in(Job::STATUSES)],
            /*
             * Both required, exactly as they are when the job is raised. A job
             * that can be created only with dates but saved without them can
             * lose them on the way through this form, and every calendar in
             * the app then has a blank row where the work was.
             */
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            // No foreman on a job: work is handed to someone task by task, so
            // the edit form does not offer one and nothing may set one here.
            /*
             * The drawing the work is taken off. Optional here where it is
             * required at creation: a job raised before its drawing existed
             * can be linked to one later, and one already linked keeps what it
             * has when the field is left alone. Must be one of this manager's
             * own projects' drawings.
             */
            'upload_id' => [
                'nullable', 'integer',
                Rule::exists('uploads', 'id')->where(
                    fn ($query) => $query->whereIn('project_id', fn ($sub) => $sub->select('id')->from('projects')->where('user_id', $userId))
                ),
            ],
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
