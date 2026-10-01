<?php

namespace Tests;

use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\Access\Permissions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Gives `$user`'s role these permissions in `$user`'s company (making one if they have none),
     * on top of what that role already has — for a test about a screen a role does not open by default.
     *
     * @param  list<string>  $keys
     */
    protected function grantPermissions(User $user, array $keys): User
    {
        $permissions = app(Permissions::class);
        $role = $permissions->roleOf($user);

        if ($user->company_id === null) {
            $company = CompanyProfile::create([
                'user_id' => $user->id, 'name' => 'Test Co '.$user->id, 'business_address' => '1 Main St', 'primary_contact' => 'A',
                'phone' => '(512) 555-0142', 'email' => "co{$user->id}@x.test", 'timezone' => 'America/Chicago',
            ]);
            $user->forceFill(['company_id' => $company->id])->save();
        }

        $company = CompanyProfile::findOrFail($user->company_id);
        $matrix = $permissions->matrix($company->id);
        $matrix[$role] = array_values(array_unique([...$matrix[$role], ...$keys]));
        $permissions->save($company, $matrix);

        return $user->refresh();
    }
}
