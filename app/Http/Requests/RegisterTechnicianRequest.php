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
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
