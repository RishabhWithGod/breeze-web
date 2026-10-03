<?php

namespace App\Http\Middleware;

use App\Models\CompanyProfile;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Walks a brand-new account through setup before anything else: its company,
 * then the terms, then the plan and card.
 *
 * Every account is flagged at signup and held here until all three are done. A
 * manager account that existed before companies did is flagged the first time it
 * comes back. The setup
 * screens themselves and sign-out stay reachable, and an earlier step stays
 * open while a later one is pending so Back works.
 */
class EnsureCompanySetUp
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->flagIfNeverSetUp($user);
        }

        if ($user?->needs_company_setup && ! $request->routeIs('company.setup.*', 'address.*', 'logout')) {
            return redirect()->route('company.setup.create');
        }

        if ($user?->needs_terms_acceptance && ! $request->routeIs('company.setup.*', 'terms.*', 'address.*', 'logout')) {
            return redirect()->route('terms.create');
        }

        if ($user?->needs_payment_setup && ! $request->routeIs('company.setup.*', 'terms.*', 'payment.setup.*', 'address.*', 'logout')) {
            return redirect()->route('payment.setup.create');
        }

        return $next($request);
    }

    /**
     * A manager who has no company — an account from before companies existed —
     * goes through the whole setup on its next visit, the same as a new signup.
     * Crew (foremen, journeymen, apprentices) never set a company up; they belong
     * to one a manager set up.
     */
    private function flagIfNeverSetUp(User $user): void
    {
        if (! config('company.require_setup_for_existing')
            || $user->company_id !== null
            || $user->needs_company_setup
            || $user->needs_terms_acceptance
            || $user->needs_payment_setup
            || $user->isFromMobile()
            || ! in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true)) {
            return;
        }

        // Already described a company but never linked to it: link, don't repeat it.
        $existing = CompanyProfile::query()->where('user_id', $user->id)->value('id');
        if ($existing !== null) {
            $user->forceFill(['company_id' => $existing])->save();

            return;
        }

        $user->forceFill([
            'needs_company_setup' => true,
            'needs_terms_acceptance' => true,
            'needs_payment_setup' => true,
        ])->save();
    }
}
