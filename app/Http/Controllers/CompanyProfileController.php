<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Correcting the company's details after setup. Only the owner — whoever set the
 * company up — may; every other manager reads them in Settings.
 */
class CompanyProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $company = $this->ownedCompany($request);

        return Inertia::render('CompanySetup', [
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
            'defaults' => ['primaryContact' => $company->primary_contact, 'email' => $company->email],
            'timezones' => collect(timezone_identifiers_list())
                ->map(fn (string $zone) => ['value' => $zone, 'label' => str_replace('_', ' ', $zone)])
                ->values(),
            // Tells the form it is correcting an existing company, not setting one up.
            'editing' => [
                'saveUrl' => route('settings.company.update', absolute: false),
                'backUrl' => route('settings.payment.index', ['tab' => 'company'], absolute: false),
            ],
        ]);
    }

    public function update(UpdateCompanyProfileRequest $request): RedirectResponse
    {
        $company = $this->ownedCompany($request);

        $data = $request->safe()->except(['logo', 'remove_logo']);

        if ($request->hasFile('logo')) {
            $this->forgetLogo($company);
            $data['logo_path'] = $request->file('logo')->store('company', CompanyProfile::LOGO_DISK);
        } elseif ($request->boolean('remove_logo')) {
            $this->forgetLogo($company);
            $data['logo_path'] = null;
        }

        $company->update($data);

        return redirect()
            ->route('settings.payment.index', ['tab' => 'company'])
            ->with('success', 'Company details were updated.');
    }

    /** The company this account owns; anyone else — another manager, crew — is refused. */
    private function ownedCompany(Request $request): CompanyProfile
    {
        $user = $request->user();

        $company = $user->company_id === null ? null : CompanyProfile::query()->find($user->company_id);

        abort_unless($company !== null && $company->user_id === $user->id, 403);

        return $company;
    }

    private function forgetLogo(CompanyProfile $company): void
    {
        if ($company->logo_path) {
            Storage::disk(CompanyProfile::LOGO_DISK)->delete($company->logo_path);
        }
    }
}
