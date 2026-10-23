<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Models\CompanyProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Correcting the company's details from Settings › Company — web's `CompanyProfileController`.
 * Only the company's owner may; every other manager reads them.
 */
class OfficeCompanyController extends Controller
{
    use ApiResponses;

    public function show(Request $request): JsonResponse
    {
        $company = $this->owned($request);

        return $this->ok([
            'company' => [
                'name' => $company->name,
                'businessAddress' => $company->business_address,
                'primaryContact' => $company->primary_contact,
                'phone' => $company->phone,
                'email' => $company->email,
                'licenseNumber' => $company->license_number,
                'timezone' => $company->timezone,
                'logoUrl' => $company->logoUrl(),
            ],
            'timezones' => collect(timezone_identifiers_list())
                ->map(fn (string $zone) => ['value' => $zone, 'label' => str_replace('_', ' ', $zone)])
                ->values(),
        ]);
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $company = $this->owned($request);
        $data = $request->safe()->except(['logo', 'remove_logo']);

        if ($request->hasFile('logo')) {
            $this->forgetLogo($company);
            $data['logo_path'] = $request->file('logo')->store('company', CompanyProfile::LOGO_DISK);
        } elseif ($request->boolean('remove_logo')) {
            $this->forgetLogo($company);
            $data['logo_path'] = null;
        }

        $company->update($data);

        return $this->ok(null, 'Company details were updated.');
    }

    private function owned(Request $request): CompanyProfile
    {
        $user = $request->user();
        $company = $user->company_id === null ? null : CompanyProfile::query()->find($user->company_id);
        abort_unless($company !== null && $company->user_id === $user->id, 403, 'Only the company owner can change these details.');

        return $company;
    }

    private function forgetLogo(CompanyProfile $company): void
    {
        if ($company->logo_path) {
            Storage::disk(CompanyProfile::LOGO_DISK)->delete($company->logo_path);
        }
    }
}
