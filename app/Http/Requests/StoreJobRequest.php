<?php

namespace App\Http\Requests;

use App\Models\Job;
use App\Services\Teams\ResolveInlineTeam;
use App\Support\CompanyRule;
use App\Support\Ownership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    /** A team typed into the picker but not yet on the register — created now, only because this job is actually being saved. */
    protected function prepareForValidation(): void
    {
        $this->merge(['team_id' => app(ResolveInlineTeam::class)($this->input('team_id'))]);
    }

    /**
     * Mirrors the Create New Job screen: only the name is required. Client,
     * project, drawing, site, crew and dates can all be filled in later —
     * each is still checked against this manager's own register when it is
     * given, just no longer demanded up front. No foreman: they are assigned
     * per task, once the job has been broken into the work it takes —
     * picking one here was a guess made before that was known. A job names
     * both its client and its project: the client is who it is for, the project is
     * projects, so it is picked from the register rather than typed, and the
     * job's own `client` column is a snapshot the controller writes from it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = Ownership::userIdList($this->user());

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
            'address_ids' => ['nullable', 'array', 'size:1'],
            'address_ids.*' => [
                'integer', 'distinct',
                Rule::exists('client_addresses', 'id')->where(
                    fn ($query) => $query->whereIn('client_id', fn ($sub) => $sub->select('id')->from('clients')->whereIn('user_id', $userId))
                ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            /*
             * The crew this job is handed to. Optional: narrows who a task on
             * this job can be given to when it is picked, but a job can wait
             * to be staffed.
             */
            'team_id' => ['nullable', 'integer', CompanyRule::exists('teams')],
            /*
             * When the work runs. Optional: a job without dates yet just shows
             * blank on the calendar until they are set, which is no worse than
             * a job not existing at all.
             */
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            /*
             * No budget field: a job's budget is the estimate raised against
             * its drawing, never a figure typed on this form. The controller
             * writes it from the linked estimate directly.
             */
            'create_estimate' => ['boolean'],
            'assign_team' => ['boolean'],
            'notify_client' => ['boolean'],
            /** "Save as Draft" instead of "Create Job". */
            'save_as_draft' => ['boolean'],
            /** Who the work is for, from this manager's own client register. */
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereIn('user_id', $userId)],
            /*
             * And which of their projects it is on. Every drawing, takeoff and
             * estimate hangs off a project, so the job does too — the client
             * alone cannot say which set of drawings this is. Also this
             * manager's own.
             */
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereIn('user_id', $userId)],
            /*
             * Optional: a job is usually the work on a drawing, but one raised
             * by hand with no takeoff yet is still a real job — the task step
             * that follows just has nothing to plan from until one is linked.
             * Must be one of this manager's own projects' drawings when given.
             */
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
            'name.required' => 'Job name is required',
            'name.min' => 'Use at least 3 characters',
            'client_id.exists' => 'Pick a client from the list',
            'project_id.exists' => 'Pick a project from the list',
            'address_ids.size' => 'A job runs at one site',
            'address_ids.*.exists' => 'That site is not on the project\'s record',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ];
    }
}
