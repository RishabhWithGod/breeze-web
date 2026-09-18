<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a job from a chosen standalone estimate plus whichever addenda were
 * selected on the Addendum screen. Mirrors `StoreJobRequest` for the fields a
 * job always needs (name, site, crew, dates) — `client_id`/`project_id`/the
 * estimate to price it from are not asked for here because they are derived
 * from the selected estimates themselves, in `JobFromEstimatesController`.
 */
class StoreJobFromEstimatesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            /*
             * Which estimates to build the job from — the standalone one and
             * whichever addenda were checked. Ownership only, here: that they
             * all share one project (so there is one client/site to build the
             * job against) is checked in the controller, once they are loaded.
             */
            'estimate_ids' => ['required', 'array', 'min:1'],
            'estimate_ids.*' => [
                'integer', 'distinct',
                Rule::exists('estimates', 'id')->where('user_id', $userId),
            ],
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'address_ids' => ['required', 'array', 'size:1'],
            'address_ids.*' => [
                'integer', 'distinct',
                Rule::exists('client_addresses', 'id')->where(
                    fn ($query) => $query->whereIn('client_id', fn ($sub) => $sub->select('id')->from('clients')->where('user_id', $userId))
                ),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'estimate_ids.required' => 'Select at least one estimate to build the job from.',
            'name.required' => 'Job name is required',
            'team_id.required' => 'Pick the crew this job is handed to',
            'address_ids.required' => 'Pick the site this job runs at',
            'address_ids.size' => 'A job runs at one site',
            'start_date.required' => 'Pick the day this job starts',
            'end_date.required' => 'Pick the day this job is due to finish',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ];
    }
}
