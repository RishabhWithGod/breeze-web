<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Services\Access\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Roles & Permissions on the phone — the same per-company matrix web's
 * `RolePermissionController` edits, behind the same `admin.manage_roles` gate.
 */
class RolePermissionController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly Permissions $permissions) {}

    public function show(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }

        $company = CompanyProfile::query()->findOrFail($request->user()->company_id);
        $locked = config('permissions.locked_role');

        return $this->ok([
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
            'granted' => $this->permissions->matrix($company->id),
            'defaults' => $this->permissions->defaults(),
            'customised' => $company->role_permissions !== null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (($denied = $this->guard($request)) !== null) {
            return $denied;
        }

        $company = CompanyProfile::query()->findOrFail($request->user()->company_id);
        $roles = array_values(array_diff(array_keys(config('permissions.roles')), [config('permissions.locked_role')]));

        $data = $request->validate([
            'granted' => ['required', 'array'],
            ...collect($roles)->mapWithKeys(fn (string $role) => ["granted.{$role}" => ['present', 'array']])->all(),
            'granted.*.*' => ['string', Rule::in($this->permissions->all())],
        ]);

        $this->permissions->save($company, $data['granted']);

        return $this->ok(null, 'Permissions saved. They apply to everyone with that role.');
    }

    private function guard(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->company_id === null) {
            return $this->fail('Permissions belong to a company.', 403);
        }
        if (! $this->permissions->allows($user, 'admin.manage_roles')) {
            return $this->fail('You do not have access to Roles & Permissions.', 403);
        }

        return null;
    }
}
