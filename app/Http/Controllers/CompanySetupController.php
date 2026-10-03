<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyProfileRequest;
use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen a new account sees: who the company is.
 */
class CompanySetupController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $company = $request->user()->company;

        // Already set up, and nothing pending that sends the person back here.
        if ($company && ! $request->user()->needs_company_setup && ! $request->user()->needs_terms_acceptance && ! $request->user()->needs_payment_setup) {
            return redirect()->route('home');
        }

        return Inertia::render('CompanySetup', [
            'company' => $company ? [
                'name' => $company->name,
                'businessAddress' => $company->business_address,
                'primaryContact' => $company->primary_contact,
                'phone' => $company->phone,
                'email' => $company->email,
                'licenseNumber' => $company->license_number,
                'timezone' => $company->timezone,
                'logoUrl' => $company->logoUrl(),
            ] : null,
            'defaults' => [
                'primaryContact' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'timezones' => collect(timezone_identifiers_list())
                ->map(fn (string $zone) => ['value' => $zone, 'label' => str_replace('_', ' ', $zone)])
                ->values(),
        ]);
    }

    public function store(StoreCompanyProfileRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('logo');
        $company = $request->user()->company;

        if ($request->hasFile('logo')) {
            if ($company?->logo_path) {
                Storage::disk(CompanyProfile::LOGO_DISK)->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', CompanyProfile::LOGO_DISK);
        }

        if ($company) {
            $company->update($data);
        } else {
            $created = $request->user()->company()->create($data + ['timezone' => config('app.timezone')]);
            $request->user()->unsetRelation('company');
            // The account belongs to the company it just described.
            $request->user()->forceFill(['company_id' => $created->id])->save();
        }

        $request->user()->forceFill(['needs_company_setup' => false])->save();

        // The next step that is still pending; only someone with none is done.
        if ($request->user()->needs_terms_acceptance) {
            return redirect()->route('terms.create');
        }
        if ($request->user()->needs_payment_setup) {
            return redirect()->route('payment.setup.create');
        }

        return redirect()->route('home')->with('success', 'Your company details are saved.');
    }
}
