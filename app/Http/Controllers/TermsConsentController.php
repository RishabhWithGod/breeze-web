<?php

namespace App\Http\Controllers;

use App\Models\TermsAcceptance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second setup step: read the terms, agree, and sign.
 */
class TermsConsentController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Open while the payment step is pending too, so Back works from there.
        if (! $user->needs_terms_acceptance && ! $user->needs_payment_setup) {
            return redirect()->route('home');
        }

        // The terms come after the company, never before it.
        if ($user->needs_company_setup) {
            return redirect()->route('company.setup.create');
        }

        return Inertia::render('TermsConsent', [
            'terms' => config('legal.terms'),
            'privacy' => config('legal.privacy'),
            'signedOn' => $this->today($request)->toDateString(),
            'signerName' => $user->termsAcceptances()->where('version', config('legal.version'))->value('signer_name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->needs_company_setup, 403);

        $request->validate([
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'signer_name' => ['required', 'string', 'min:2', 'max:255'],
        ], [
            'accept_terms.accepted' => 'You must agree to the Terms of Service.',
            'accept_privacy.accepted' => 'You must agree to the Privacy Policy.',
            'signer_name.required' => 'Enter your full name to sign.',
            'signer_name.min' => 'Enter your full name to sign.',
        ]);

        // The date is when it was signed, in the company's own time zone —
        // not something the browser gets to choose.
        TermsAcceptance::updateOrCreate(
            ['user_id' => $user->id, 'version' => config('legal.version')],
            [
                'signer_name' => trim($request->string('signer_name')->toString()),
                'signed_on' => $this->today($request)->toDateString(),
                'ip_address' => $request->ip(),
                'accepted_at' => now(),
            ],
        );

        $user->forceFill(['needs_terms_acceptance' => false])->save();

        if ($user->needs_payment_setup) {
            return redirect()->route('payment.setup.create');
        }

        return redirect()->route('home')->with('success', 'Your setup is complete. Welcome to Breeze.Ai.');
    }

    private function today(Request $request): Carbon
    {
        return now($request->user()->company?->timezone ?? config('app.timezone'));
    }
}
