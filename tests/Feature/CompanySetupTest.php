<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The first screen a new account sees: the company profile.
 */
class CompanySetupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validCompany(array $overrides = []): array
    {
        return [
            'name' => 'Volt & Co',
            'business_address' => "12 Main St\nAustin, TX 78701",
            'primary_contact' => 'Alex Morgan',
            'phone' => '(512) 555-0142',
            'email' => 'office@volt.test',
            'license_number' => 'TECL-12345',
            'timezone' => 'America/Chicago',
            ...$overrides,
        ];
    }

    private function signUp(): User
    {
        $this->post('/signup', [
            'name' => 'New Owner',
            'email' => 'owner@example.com',
            'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x',
        ])->assertRedirect(route('home'));

        return User::where('email', 'owner@example.com')->firstOrFail();
    }

    public function test_a_new_account_is_held_at_company_setup(): void
    {
        $user = $this->signUp();

        $this->assertTrue($user->needs_company_setup);

        $this->get(route('home'))->assertRedirect(route('company.setup.create'));
        $this->get('/clients')->assertRedirect(route('company.setup.create'));
        $this->get(route('company.setup.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CompanySetup')
                ->where('company', null)
                ->where('defaults.email', 'owner@example.com'));
    }

    public function test_saving_the_company_clears_the_hold(): void
    {
        Storage::fake('public');
        $user = $this->signUp();

        $this->post(route('company.setup.store'), $this->validCompany([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]))->assertRedirect(route('terms.create'));

        $company = $user->company;
        $this->assertSame('Volt & Co', $company->name);
        $this->assertSame('America/Chicago', $company->timezone);
        $this->assertSame($user->id, $company->user_id);
        $this->assertFalse($user->fresh()->needs_company_setup);
        Storage::disk('public')->assertExists($company->logo_path);

        // The terms are still to sign, so the app stays shut...
        $this->get(route('home'))->assertRedirect(route('terms.create'));

        // ...and once they are signed, nothing is left to do on the company step.
        $this->post(route('terms.store'), ['accept_terms' => true, 'accept_privacy' => true, 'signer_name' => 'New Owner']);
        auth()->user()->forceFill(['needs_payment_setup' => false])->save();
        $this->get(route('home'))->assertOk();
        $this->get(route('company.setup.create'))->assertRedirect(route('home'));
    }

    public function test_every_required_field_is_validated(): void
    {
        $this->signUp();

        $this->post(route('company.setup.store'), [])->assertSessionHasErrors([
            'name', 'business_address', 'primary_contact', 'phone', 'email', 'timezone',
        ]);

        $this->post(route('company.setup.store'), $this->validCompany(['phone' => '12', 'timezone' => 'Mars/Base']))
            ->assertSessionHasErrors(['phone', 'timezone']);

        $this->post(route('company.setup.store'), $this->validCompany([
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ]))->assertSessionHasErrors('logo');

        $this->assertSame(0, CompanyProfile::count());
    }

    public function test_every_signup_sets_up_its_own_company(): void
    {
        $first = $this->signUp();
        $this->post(route('company.setup.store'), $this->validCompany())->assertRedirect(route('terms.create'));
        $this->post(route('logout'));

        $this->post('/signup', [
            'name' => 'Second Owner',
            'email' => 'second@example.com',
            'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x',
        ]);
        $second = User::where('email', 'second@example.com')->firstOrFail();

        // The first company being there does not excuse the second account.
        $this->assertTrue($second->needs_company_setup);
        $this->get(route('home'))->assertRedirect(route('company.setup.create'));
        $this->get(route('company.setup.create'))
            ->assertInertia(fn ($page) => $page->where('company', null));

        $this->post(route('company.setup.store'), $this->validCompany(['name' => 'Second Co']))
            ->assertRedirect(route('terms.create'));

        $this->assertSame('Volt & Co', $first->fresh()->company->name);
        $this->assertSame('Second Co', $second->fresh()->company->name);
        $this->assertSame(2, CompanyProfile::count());
    }

    public function test_an_account_not_made_through_signup_is_never_held(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();
    }

    public function test_sign_out_stays_reachable_while_setup_is_pending(): void
    {
        $this->signUp();

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }
}
