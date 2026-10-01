<?php

namespace App\Http\Requests;

use App\Rules\UsPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A field technician creating their own account from the mobile app.
 *
 * Deliberately smaller than the web `RegisterRequest`'s eventual account:
 * no role is accepted from the client — `AuthController::register()` always
 * sets `role: 'Technician'` and `status: pending_approval` itself, so a
 * signup can never mint its own manager access the way the web form's
 * DB-default role would if it were reused here unchanged.
 */
class RegisterTechnicianRequest extends FormRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'company_id.required' => 'Choose the company you work for.',
            'company_id.exists' => 'Choose a company from the list.',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The company whose manager will decide on this application.
            'company_id' => ['required', 'integer', 'exists:company_profiles,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
