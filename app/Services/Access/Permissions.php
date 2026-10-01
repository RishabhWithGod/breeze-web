<?php

namespace App\Services\Access;

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Routing\Route;

/**
 * What a user may do on the web app, by role.
 *
 * A project manager always has everything. The other roles start from the defaults in
 * config/permissions.php and a company may change them. A role outside the matrix (an estimator,
 * say) is not governed by it and keeps whatever access it already had.
 */
class Permissions
{
    /** @var array<int|string, array<string, list<string>>> */
    private array $matrices = [];

    /** The matrix role a user falls under, or null when the matrix does not govern them. */
    public function roleOf(User $user): ?string
    {
        return match (mb_strtolower(trim((string) $user->role))) {
            'project manager', 'admin', 'owner' => 'manager',
            'foreman' => 'foreman',
            'journeyman' => 'journeyman',
            'apprentice' => 'apprentice',
            default => null,
        };
    }

    /** @return list<string> Every permission key there is. */
    public function all(): array
    {
        $keys = [];
        foreach (config('permissions.modules') as $module => $definition) {
            foreach (array_keys($definition['permissions']) as $action) {
                $keys[] = "{$module}.{$action}";
            }
        }

        return $keys;
    }

    /** @return array<string, list<string>> role => the permissions it starts with */
    public function defaults(): array
    {
        $all = $this->all();
        $matrix = [];

        foreach (config('permissions.defaults') as $role => $rule) {
            $allowed = $this->expand($rule['allow'] ?? [], $all);
            $except = $this->expand($rule['except'] ?? [], $all);
            $matrix[$role] = array_values(array_diff($allowed, $except));
        }

        return $matrix;
    }

    /**
     * What each role may do in a company: its saved choices over the defaults. The locked role is
     * always everything.
     *
     * @return array<string, list<string>>
     */
    public function matrix(?int $companyId): array
    {
        $key = $companyId ?? 'none';

        if (isset($this->matrices[$key])) {
            return $this->matrices[$key];
        }

        $saved = $companyId === null ? null : CompanyProfile::query()->whereKey($companyId)->value('role_permissions');
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;
        $matrix = $this->defaults();

        foreach (array_keys(config('permissions.roles')) as $role) {
            if (isset($saved[$role]) && is_array($saved[$role])) {
                $matrix[$role] = $this->normalise($saved[$role]);
            }
        }
        $matrix[config('permissions.locked_role')] = $this->all();

        return $this->matrices[$key] = $matrix;
    }

    /**
     * Saves a company's matrix. View is what opens a module, so anything granted in a module
     * without its View is dropped.
     *
     * @param  array<string, list<string>>  $matrix
     */
    public function save(CompanyProfile $company, array $matrix): void
    {
        $clean = [];
        foreach (array_keys(config('permissions.roles')) as $role) {
            if ($role === config('permissions.locked_role')) {
                continue;
            }
            $clean[$role] = $this->normalise($matrix[$role] ?? []);
        }

        $company->forceFill(['role_permissions' => $clean])->save();
        unset($this->matrices[$company->id]);
    }

    /**
     * The permissions a user holds — or null when the matrix does not govern them.
     *
     * @return list<string>|null
     */
    public function for(User $user): ?array
    {
        $role = $this->roleOf($user);

        return $role === null ? null : $this->matrix($user->company_id)[$role];
    }

    public function allows(User $user, string $permission): bool
    {
        $held = $this->for($user);

        return $held === null || in_array($permission, $held, true);
    }

    /**
     * The permission a request needs, from the route it is for — or null for a route the matrix
     * does not govern (the dashboard, a person's own profile and security, notifications…).
     */
    public function required(Route $route, string $method): ?string
    {
        $name = $route->getName();

        if ($name === null) {
            return null;
        }

        // A job's tasks are the Tasks module.
        $module = str_starts_with($name, 'jobs.tasks.') ? 'tasks' : null;
        $trimmed = $name;
        if ($module !== null) {
            $trimmed = substr($name, strlen('jobs.'));
        }

        $target = $module ?? config('permissions.routes.'.explode('.', $name)[0]);

        // Starting a takeoff from a project is a takeoff.
        if ($name === 'projects.takeoff.start') {
            return 'takeoff.create';
        }

        if ($target === null) {
            return null;
        }
        // The admin entries are a single permission each, whatever is done.
        if (str_starts_with($target, 'admin.')) {
            return $target;
        }

        $parts = explode('.', $trimmed);
        $last = end($parts);
        // `module.store` is creating one; `module.thing.store` is editing what it belongs to.
        $top = count($parts) <= 2;

        if ($target === 'schedule') {
            return in_array($method, ['GET', 'HEAD'], true) ? 'schedule.view' : 'schedule.manage';
        }

        $action = match (true) {
            $method === 'DELETE' => $top ? 'delete' : 'edit',
            $last === 'create', $last === 'setup' => 'create',
            $last === 'store' && $top => 'create',
            $last === 'edit', in_array($method, ['PUT', 'PATCH'], true) => 'edit',
            in_array($method, ['GET', 'HEAD'], true) => 'view',
            default => 'edit',
        };

        return "{$target}.{$action}";
    }

    /** The label of the module a permission belongs to, for telling someone what they cannot open. */
    public function moduleLabel(string $permission): string
    {
        $module = explode('.', $permission)[0];

        return config("permissions.modules.{$module}.label", 'this area');
    }

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $all
     * @return list<string>
     */
    private function expand(array $patterns, array $all): array
    {
        return array_values(array_filter($all, function (string $key) use ($patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern === '*' || fnmatch($pattern, $key)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Only real permissions, each once, with View implied by anything else in its module.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function normalise(array $keys): array
    {
        $valid = array_values(array_intersect($this->all(), array_unique($keys)));

        foreach (config('permissions.modules') as $module => $definition) {
            $actions = array_keys($definition['permissions']);
            if (! in_array('view', $actions, true)) {
                continue;
            }
            $held = array_filter($actions, fn ($action) => in_array("{$module}.{$action}", $valid, true));
            $hasView = in_array('view', $held, true);

            if ($hasView === false && $held !== []) {
                // Anything without View is dropped: View is what opens the module at all.
                $valid = array_values(array_diff($valid, array_map(fn ($a) => "{$module}.{$a}", $held)));
            }
        }

        return array_values(array_intersect($this->all(), $valid));
    }
}
