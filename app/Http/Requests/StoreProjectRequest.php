<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create Client: the client's own details, and nothing else.
 *
 * Only the name is required — clients and projects are the same record, so the
 * name *is* the client, and the `client` column is written from it rather than
 * asked for twice.
 *
 * No drawings are accepted here. A PDF now only ever arrives through AI
 * Takeoff, which uploads it against a client that already exists, so the
 * product has one upload path rather than three.
 */
class StoreProjectRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'code' => ['nullable', 'string', 'max:60'],
            /*
             * The client's sites. A client can be opened before any is known,
             * so the list may be empty; the first one given is the primary,
             * and is mirrored onto `location` for every list that reads it.
             */
            'addresses' => ['nullable', 'array', 'max:25'],
            'addresses.*.label' => ['nullable', 'string', 'max:80'],
            'addresses.*.address' => ['required', 'string', 'max:160'],
            /*
             * Set only when the address was picked from the lookup, so both are
             * optional — but never one without the other, or the record would
             * carry half a point.
             */
            'addresses.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:addresses.*.longitude'],
            'addresses.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:addresses.*.latitude'],
            'project_type' => ['nullable', Rule::in(Project::TYPES)],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Client name is required',
            'name.min' => 'Use at least 3 characters',
            'addresses.*.address.required' => 'Enter the address, or remove the row.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'project number',
            'project_type' => 'project type',
            'due_date' => 'due date',
        ];
    }
}
