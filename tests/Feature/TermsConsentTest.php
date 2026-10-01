<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\TermsAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The second setup step: terms and consent, after the company.
 */
class TermsConsentTest extends TestCase
{
    use RefreshDatabase;

    private function signUp(bool $withCompany = true): User
    {
        $this->post('/signup', [
            'name' => 'New Owner',
            'email' => 'owner@example.com',
            'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x',
        ]);

        $user = User::where('email', 'owner@example.com')->firstOrFail();

        if ($withCompany) {
            $this->post(route('company.setup.store'), [
                'name' => 'Volt & Co',
                'business_address' => '12 Main St',
                'primary_contact' => 'Alex Morgan',
                'phone' => '(512) 555-0142',
                'email' => 'office@volt.test',
                'timezone' => 'Pacific/Auckland',
            ]);
        }

        return $user->fresh();
    }

    private function sign(array $overrides = [])
    {
        return $this->post(route('terms.store'), [
            'accept_terms' => true,
            'accept_privacy' => true,
            'signer_name' => 'Alex Morgan',
            ...$overrides,
        ]);
    }

    public function test_saving_the_company_leads_to_the_terms(): void
    {
        $user = $this->signUp();

        $this->assertFalse($user->needs_company_setup);
        $this->assertTrue($user->needs_terms_acceptance);

        // Nothing else opens until the terms are signed.
        $this->get(route('home'))->assertRedirect(route('terms.create'));
        $this->get(route('terms.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('TermsConsent')
                ->where('terms.title', 'Terms of Service')
                ->where('privacy.title', 'Privacy Summary')
                ->has('terms.points', 5)
                ->where('signedOn', now('Pacific/Auckland')->toDateString()));

        // Back to the company step is allowed, and it is pre-filled.
        $this->get(route('company.setup.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('company.name', 'Volt & Co'));
    }

    public function test_the_terms_cannot_be_reached_before_the_company(): void
    {
        $this->signUp(withCompany: false);

        $this->get(route('terms.create'))->assertRedirect(route('company.setup.create'));
        $this->sign()->assertRedirect(route('company.setup.create'));
        $this->assertSame(0, TermsAcceptance::count());
    }

    public function test_signing_records_the_acceptance_and_opens_the_app(): void
    {
        $user = $this->signUp();

        // The plan and card are the next step.
        $this->sign(['signer_name' => '  Alex Morgan '])->assertRedirect(route('payment.setup.create'));
        auth()->user()->forceFill(['needs_payment_setup' => false])->save();

        $acceptance = TermsAcceptance::firstOrFail();
        $this->assertSame($user->id, $acceptance->user_id);
        $this->assertSame('Alex Morgan', $acceptance->signer_name);
        $this->assertSame(config('legal.version'), $acceptance->version);
        $this->assertSame(now('Pacific/Auckland')->toDateString(), $acceptance->signed_on->toDateString());
        $this->assertFalse($user->fresh()->needs_terms_acceptance);

        $this->get(route('home'))->assertOk();
        $this->get(route('terms.create'))->assertRedirect(route('home'));
    }

    public function test_both_agreements_and_a_name_are_required(): void
    {
        $this->signUp();

        $this->sign(['accept_terms' => false])->assertSessionHasErrors('accept_terms');
        $this->sign(['accept_privacy' => false])->assertSessionHasErrors('accept_privacy');
        $this->sign(['signer_name' => ''])->assertSessionHasErrors('signer_name');
        $this->sign(['signer_name' => 'A'])->assertSessionHasErrors('signer_name');

        $this->assertSame(0, TermsAcceptance::count());
        $this->get(route('home'))->assertRedirect(route('terms.create'));
    }

    public function test_the_date_is_not_the_browsers_to_choose(): void
    {
        $this->signUp();

        $this->sign(['signed_on' => '2001-01-01'])->assertRedirect(route('payment.setup.create'));

        $this->assertSame(now('Pacific/Auckland')->toDateString(), TermsAcceptance::firstOrFail()->signed_on->toDateString());
    }

    public function test_an_older_account_is_never_sent_to_the_terms(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();
        $this->assertSame(0, CompanyProfile::count());
    }
}
