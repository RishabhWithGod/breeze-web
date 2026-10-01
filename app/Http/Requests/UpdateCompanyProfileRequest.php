<?php

namespace App\Http\Requests;

use App\Models\CompanyProfile;

/**
 * Correcting the company's details: the same fields as setting them up, but only its
 * owner may. Checked before the fields are, so anyone else is refused outright rather
 * than told what they got wrong.
 */
class UpdateCompanyProfileRequest extends StoreCompanyProfileRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->company_id !== null
            && CompanyProfile::query()->whereKey($user->company_id)->value('user_id') === $user->id;
    }
}
