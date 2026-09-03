<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add Project: whose it is, and what it is called.
 *
 * Nothing else, because nothing else is known yet. The place comes from the
 * client's address book, and everything about the work itself — its counts,
 * its bill of quantities, its price — comes from the takeoff that runs against
 * it. Asking here would be asking someone to guess.
 *
 * No drawings are accepted either. A PDF only ever arrives through AI Takeoff,
 * uploaded against a project that already exists, so the product has one
 * upload path rather than three.
 */
class StoreProjectRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            // A project is always for somebody.
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            /*
             * No address here. A project and the job on it are at the same
             * place, and that place is in the client's address book — asking
             * again on this form would be a second copy free to drift.
             */
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Project name is required',
            'client_id.required' => 'Pick the client this project is for',
            'name.min' => 'Use at least 3 characters',
        ];
    }
}
