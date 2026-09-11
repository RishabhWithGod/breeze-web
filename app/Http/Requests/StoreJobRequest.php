<?php

namespace App\Http\Requests;

use App\Models\Job;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    /**
     * Mirrors the Create New Job screen: name, client, location and the dates
     * the work runs are required. Everything else can be filled in later. No foreman: they
     * are assigned per task, once the job has been broken into the work it
     * takes — picking one here was a guess made before that was known. A job names
     * both its client and its project: the client is who it is for, the project is
     * projects, so it is picked from the register rather than typed, and the
     * job's own `client` column is a snapshot the controller writes from it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
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
            /*
             * When the work runs. Required: a job with no dates cannot be
             * scheduled, cannot be crewed, and shows as a blank row on every
             * calendar in the app — which is worse than being asked for two
             * dates that can be corrected later.
             */
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'create_estimate' => ['boolean'],
            'assign_team' => ['boolean'],
            'notify_client' => ['boolean'],
            /** "Save as Draft" instead of "Create Job". */
            'save_as_draft' => ['boolean'],
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
             * Required: a job is the work on a drawing. Without one there is no
             * takeoff, no estimate, and nothing for the task step that follows
             * to plan from. Must be one of this manager's own projects' drawings.
             */
            'upload_id' => [
                'required', 'integer',
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
            'upload_id.required' => 'Pick the drawing this job is for',
            'address_ids.required' => 'Pick the site this job runs at',
            'address_ids.size' => 'A job runs at one site',
            'address_ids.*.exists' => 'That site is not on the project\'s record',
            'start_date.required' => 'Pick the day this job starts',
            'end_date.required' => 'Pick the day this job is due to finish',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
            'budget.gt' => 'Enter an amount greater than zero',
        ];
    }
}
