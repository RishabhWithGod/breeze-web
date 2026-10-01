<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Services\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles & Permissions: what each role can open and do on the web app, per company.
 *
 * Reached from Teams by whoever holds the Manage roles permission (a project manager always does).
 * A project manager's own access is fixed at everything, so a company cannot lock itself out.
 */
class RolePermissionController extends Controller
{
    public function __construct(private readonly Permissions $permissions) {}

    public function show(Request $request): Response
    {
        $company = $this->company($request);
        $matrix = $this->permissions->matrix($company->id);
        $locked = config('permissions.locked_role');

        return Inertia::render('RolesPermissions', [
            'roles' => collect(config('permissions.roles'))->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'locked' => $key === $locked,
            ])->values()->all(),
            'modules' => collect(config('permissions.modules'))->map(fn (array $module, string $key) => [
                'key' => $key,
                'label' => $module['label'],
                'icon' => $module['icon'],
                'permissions' => collect($module['permissions'])->map(fn (string $label, string $action) => ['key' => "{$key}.{$action}", 'label' => $label])->values()->all(),
            ])->values()->all(),
            'granted' => $matrix,
            'defaults' => $this->permissions->defaults(),
            'customised' => $company->role_permissions !== null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $valid = $this->permissions->all();
        $roles = array_values(array_diff(array_keys(config('permissions.roles')), [config('permissions.locked_role')]));

        $data = $request->validate([
            'granted' => ['required', 'array'],
            ...collect($roles)->mapWithKeys(fn (string $role) => ["granted.{$role}" => ['present', 'array']])->all(),
            'granted.*.*' => ['string', Rule::in($valid)],
        ]);

        $this->permissions->save($company, $data['granted']);

        return back()->with('success', 'Permissions saved. They apply to everyone with that role.');
    }

    private function company(Request $request): CompanyProfile
    {
        $companyId = $request->user()->company_id;
        abort_if($companyId === null, 403, 'Permissions belong to a company.');

        return CompanyProfile::query()->findOrFail($companyId);
    }
}
