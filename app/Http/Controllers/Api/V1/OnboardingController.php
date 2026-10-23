<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyProfileRequest;
use App\Http\Requests\StorePaymentSetupRequest;
use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Rules\UsPhoneNumber;
use App\Services\Billing\CheckoutRecorder;
use App\Services\Billing\StripeSubscriptions;
use App\Services\Billing\SubscriptionSummary;
use App\Services\Company\ManagerRegistrar;
use App\Services\Company\TeamInvitations;
use App\Services\Onboarding\SetupChecklist;
use App\Support\UsPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * First-run setup on the phone — the same steps and rules as web: the company, the terms, the
 * plan and card (paid on Stripe's own page), then Team Setup and the Get Started checklist.
 * Nothing is decided here that web does not decide; the steps and their order come from the
 * `needs_*` flags the web's `EnsureCompanySetUp` middleware walks.
 */
class OnboardingController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly SetupChecklist $checklist,
        private readonly ManagerRegistrar $managers,
        private readonly TeamInvitations $invitations,
    ) {}

    /** Where this person stands: which step is next, and whether the checklist holds the dashboard. */
    public function status(Request $request): JsonResponse
    {
        return $this->ok(self::statusFor($request->user()));
    }

    /** @return array<string, mixed> */
    public static function statusFor(User $user): array
    {
        $step = match (true) {
            (bool) $user->needs_company_setup => 'company',
            (bool) $user->needs_terms_acceptance => 'terms',
            (bool) $user->needs_payment_setup => 'payment',
            default => 'done',
        };

        return [
            'step' => $step,
            'needsCompanySetup' => (bool) $user->needs_company_setup,
            'needsTerms' => (bool) $user->needs_terms_acceptance,
            'needsPayment' => (bool) $user->needs_payment_setup,
            'holdsChecklist' => $step === 'done' && app(SetupChecklist::class)->holds($user),
        ];
    }

    /* ------------------------------------------------------------ company */

    public function company(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        return $this->ok([
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
            'defaults' => ['primaryContact' => $request->user()->name, 'email' => $request->user()->email],
        ]);
    }

    public function storeCompany(StoreCompanyProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->safe()->except('logo');
        $company = $user->company;

        if ($request->hasFile('logo')) {
            if ($company?->logo_path) {
                Storage::disk(CompanyProfile::LOGO_DISK)->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', CompanyProfile::LOGO_DISK);
        }

        if ($company) {
            $company->update($data);
        } else {
            $created = $user->company()->create($data + ['timezone' => config('app.timezone')]);
            $user->unsetRelation('company');
            $user->forceFill(['company_id' => $created->id])->save();
        }

        $user->forceFill(['needs_company_setup' => false])->save();

        return $this->ok(self::statusFor($user->fresh()), 'Your company details are saved.');
    }

    /* --------------------------------------------------------------- terms */

    public function terms(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->needs_company_setup, 403, 'The terms come after the company.');

        return $this->ok([
            'terms' => config('legal.terms'),
            'privacy' => config('legal.privacy'),
            'signedOn' => $this->today($user)->toDateString(),
            'signerName' => $user->termsAcceptances()->where('version', config('legal.version'))->value('signer_name'),
        ]);
    }

    public function storeTerms(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->needs_company_setup, 403, 'The terms come after the company.');

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

        TermsAcceptance::updateOrCreate(
            ['user_id' => $user->id, 'version' => config('legal.version')],
            [
                'signer_name' => trim($request->string('signer_name')->toString()),
                'signed_on' => $this->today($user)->toDateString(),
                'ip_address' => $request->ip(),
                'accepted_at' => now(),
            ],
        );
        $user->forceFill(['needs_terms_acceptance' => false])->save();

        return $this->ok(self::statusFor($user->fresh()), 'Terms accepted.');
    }

    /* ------------------------------------------------------------- payment */

    public function payment(Request $request, SubscriptionSummary $summary, StripeSubscriptions $stripe): JsonResponse
    {
        $user = $request->user();
        abort_if($user->needs_company_setup || $user->needs_terms_acceptance, 403, 'Company and terms come first.');

        $own = Subscription::query()->where('user_id', $user->id)->first();

        return $this->ok([
            'plans' => $summary->build($user)['plans'],
            'annualDiscountPercent' => (int) config('subscription.annual_discount_percent'),
            'saved' => $own ? ['plan' => $own->plan, 'billingCycle' => $own->billing_cycle, 'seats' => $own->seats] : null,
            'stripeReady' => $stripe->configured(),
        ]);
    }

    /** Starts Stripe Checkout; the card is entered on Stripe's own page, opened in the browser. */
    public function startPayment(StorePaymentSetupRequest $request, StripeSubscriptions $stripe): JsonResponse
    {
        $user = $request->user();
        abort_if($user->needs_company_setup || $user->needs_terms_acceptance, 403, 'Company and terms come first.');

        if (! $stripe->configured()) {
            throw ValidationException::withMessages(['plan' => 'Payments are not set up yet. Please contact support.']);
        }

        try {
            $checkout = $stripe->createCheckout(
                $user,
                $request->validated('plan'),
                $request->validated('billing_cycle'),
                (int) $request->validated('seats'),
                route('mobile.payment.return', ['status' => 'success'], true),
                route('mobile.payment.return', ['status' => 'cancelled'], true),
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe checkout could not be started', ['user' => $user->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['plan' => 'We could not reach Stripe. Please try again in a moment.']);
        }

        // Remembered, so coming back from the browser needs no session id passed along.
        Cache::put($this->checkoutKey($user), $checkout['id'], now()->addHours(2));

        return $this->ok(['checkoutUrl' => $checkout['url']]);
    }

    /** Back from Stripe: the session is looked up there and checked before anything is saved. */
    public function confirmPayment(Request $request, StripeSubscriptions $stripe, CheckoutRecorder $recorder): JsonResponse
    {
        $user = $request->user();
        $sessionId = (string) Cache::get($this->checkoutKey($user), '');

        if (! $user->needs_payment_setup) {
            return $this->ok($this->confirmation($user), 'Your subscription is already active.');
        }
        if ($sessionId === '' || ! str_starts_with($sessionId, 'cs_')) {
            return $this->fail('Start the payment first.', 409);
        }

        try {
            $session = $stripe->retrieveSession($sessionId);
        } catch (\Throwable $e) {
            Log::warning('Stripe checkout could not be confirmed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return $this->fail('We could not confirm your payment with Stripe. If you were charged, contact support.', 502);
        }

        if (! $recorder->isGenuine($user, $session)) {
            return $this->fail('The payment is not complete yet. Finish it on Stripe, then try again.', 409);
        }

        $recorder->record(
            $user,
            $session,
            $session['metadata']['plan'],
            $session['metadata']['cycle'],
            max(1, (int) ($session['metadata']['seats'] ?? 1)),
        );
        Cache::forget($this->checkoutKey($user));

        return $this->ok($this->confirmation($user->fresh()), 'Your subscription is active.');
    }

    /** @return array<string, mixed>|null */
    private function confirmation(User $user): ?array
    {
        $subscription = Subscription::query()->where('user_id', $user->id)->first();
        if ($subscription === null) {
            return null;
        }
        $plan = config("subscription.plans.{$subscription->plan}");
        $owner = CompanyProfile::query()->whereKey($user->company_id)->first()?->user ?? $user;

        return [
            'plan' => ['name' => $plan['name'] ?? $subscription->plan, 'tagline' => $plan['tagline'] ?? '', 'status' => $subscription->status],
            'seats' => $subscription->seats,
            'confirmationNumber' => $subscription->confirmation_number,
            'billingCycle' => $subscription->billing_cycle,
            'nextBillingDate' => $subscription->renews_on?->toDateString(),
            'administrator' => ['name' => $owner->name, 'email' => $owner->email],
            'receiptUrl' => $subscription->receipt_url,
        ];
    }

    /* ---------------------------------------------------------- team setup */

    public function teamSetup(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return $this->ok([
            'invitations' => TeamInvitation::query()->with('team:id,name')->latest('id')->get()
                ->map(fn (TeamInvitation $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'email' => $i->email,
                    'phone' => $i->phone,
                    'role' => $i->role,
                    'teamId' => $i->team_id,
                    'teamName' => $i->team?->name,
                    'status' => $i->label(),
                    'pending' => $i->status === TeamInvitation::STATUS_PENDING,
                    'sentAt' => $i->sent_at?->toISOString(),
                    'expiresAt' => $i->expires_at?->toISOString(),
                ])->values(),
            'teams' => Team::query()->withCount('members')->orderBy('name')->get()
                ->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name, 'members' => $t->members_count]),
            'roles' => $this->invitableRoles($request),
            'seats' => $this->invitations->seats($request->user()),
            'members' => Foreman::query()->count(),
        ]);
    }

    public function sendInvitations(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        $data = $request->validate([
            'invitations' => ['required', 'array', 'min:1', 'max:50'],
            'invitations.*.name' => ['required', 'string', 'min:2', 'max:255'],
            'invitations.*.email' => ['required', 'string', 'email', 'max:255', 'distinct:ignore_case'],
            'invitations.*.phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'invitations.*.role' => ['required', Rule::in($this->invitableRoles($request))],
            'invitations.*.team_id' => ['nullable', 'integer', \App\Support\CompanyRule::exists('teams')],
            'invitations.*.password' => ['nullable', 'string', Password::defaults()],
        ], [
            'invitations.required' => 'Add at least one person to invite.',
            'invitations.min' => 'Add at least one person to invite.',
            'invitations.*.name.required' => 'Enter a name.',
            'invitations.*.email.required' => 'Enter an email.',
            'invitations.*.email.email' => 'That does not look like an email address.',
            'invitations.*.email.distinct' => 'This email is on the list twice.',
            'invitations.*.role.required' => 'Choose a role.',
            'invitations.*.role.in' => 'You cannot invite someone to that role.',
            'invitations.*.password.min' => 'Use at least 8 characters.',
        ]);

        $rows = collect($data['invitations'])->map(fn (array $row) => [...$row, 'phone' => UsPhone::format($row['phone'] ?? null)]);

        $emails = $rows->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)));
        $taken = User::query()->whereIn('email', $emails)->pluck('email')->map(fn ($e) => mb_strtolower($e))
            ->merge(TeamInvitation::query()->where('status', TeamInvitation::STATUS_PENDING)->where('expires_at', '>', now())
                ->whereIn('email', $emails)->pluck('email')->map(fn ($e) => mb_strtolower($e)));

        $errors = [];
        foreach ($rows as $index => $row) {
            if ($taken->contains(mb_strtolower(trim($row['email'])))) {
                $errors["invitations.{$index}.email"] = 'Already has an account or an open invitation.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $sent = $this->invitations->send($request->user(), $rows->all());
        $joined = collect($sent)->where('status', TeamInvitation::STATUS_ACCEPTED)->count();
        $invited = count($sent) - $joined;

        return $this->created(null, collect([
            $joined > 0 ? $joined.' '.str('member')->plural($joined).' added and can sign in now' : null,
            $invited > 0 ? $invited.' '.str('invitation')->plural($invited).' sent' : null,
        ])->filter()->implode(' · ').'.');
    }

    public function resendInvitation(Request $request, TeamInvitation $invitation): JsonResponse
    {
        $this->authorizeManager($request);
        abort_unless($invitation->status === TeamInvitation::STATUS_PENDING, 409, 'That invitation is no longer waiting.');

        $this->invitations->resend($invitation, $request->user());

        return $this->ok(null, "Invitation sent again to {$invitation->email}.");
    }

    public function cancelInvitation(Request $request, TeamInvitation $invitation): JsonResponse
    {
        $this->authorizeManager($request);
        abort_unless($invitation->status === TeamInvitation::STATUS_PENDING, 409, 'That invitation is no longer waiting.');

        $this->invitations->cancel($invitation);

        return $this->ok(null, "The invitation to {$invitation->email} was cancelled.");
    }

    /* --------------------------------------------------------- get started */

    public function getStarted(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return $this->ok($this->checklist->page($request->user()));
    }

    public function skipStep(Request $request, string $step): JsonResponse
    {
        $company = $this->ownCompany($request);
        abort_unless(in_array($step, SetupChecklist::SKIPPABLE, true), 404);

        $skipped = collect($company->onboarding_skipped ?? [])
            ->when($request->boolean('undo'), fn ($steps) => $steps->reject(fn ($key) => $key === $step))
            ->when(! $request->boolean('undo'), fn ($steps) => $steps->push($step))
            ->unique()->values()->all();
        $company->update(['onboarding_skipped' => $skipped]);

        return $this->ok($this->checklist->page($request->user()));
    }

    public function finish(Request $request): JsonResponse
    {
        $company = $this->ownCompany($request);

        if (! $this->checklist->for($request->user())['canFinish']) {
            return $this->fail('Finish or skip the remaining steps first.', 409);
        }
        $company->forceFill(['onboarding_finished_at' => now()])->save();

        return $this->ok(null, 'Setup is finished. Your workspace is ready.');
    }

    /* ------------------------------------------------------------ helpers */

    private function checkoutKey(User $user): string
    {
        return 'onboarding.checkout.'.$user->id;
    }

    private function today(User $user): Carbon
    {
        return now($user->company?->timezone ?? config('app.timezone'));
    }

    /** @return list<string> */
    private function invitableRoles(Request $request): array
    {
        return $this->managers->canAdd($request->user())
            ? TeamInvitation::ROLES
            : array_values(array_diff(TeamInvitation::ROLES, ['Project Manager']));
    }

    private function authorizeManager(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->company_id !== null && $this->managers->isManager($user), 403);
    }

    private function ownCompany(Request $request): CompanyProfile
    {
        $this->authorizeManager($request);

        return CompanyProfile::query()->findOrFail($request->user()->company_id);
    }
}
