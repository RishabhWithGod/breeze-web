<?php

namespace App\Console\Commands;

use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hands the crew that existed before companies to one company.
 *
 * Teams, crew members and the crew's own logins made before there were companies
 * belong to no company, and so to no manager once managers have one. This gives
 * them all to the company of the account named.
 */
class AdoptLegacyCrew extends Command
{
    protected $signature = 'company:adopt-legacy {email : A manager whose company takes the crew that has none}
                            {--managers : Also take the other accounts of managers that have no company, and with them the work they made}';

    protected $description = 'Give the crew, and work with no owner, that belong to no company to one manager\'s company';

    public function handle(): int
    {
        $manager = User::where('email', strtolower($this->argument('email')))->first();

        if (! $manager) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        if ($manager->company_id === null) {
            $this->error('That account has no company yet. Have them finish company setup first.');

            return self::FAILURE;
        }

        $counts = DB::transaction(function () use ($manager) {
            $company = $manager->company_id;
            $managerRoles = ['project manager', 'admin', 'owner'];

            // Work nobody was ever recorded as making (from before work had an owner)
            // goes to the manager whose company is taking the rest.
            $unowned = [];
            foreach (['work_jobs' => 'jobs', 'projects' => 'projects', 'clients' => 'clients', 'invoices' => 'invoices'] as $table => $label) {
                $unowned["{$label} with no owner"] = DB::table($table)->whereNull('user_id')->update(['user_id' => $manager->id]);
            }

            // Estimates are numbered per owner, so taking one on means giving it the next
            // number in that owner's series — its old number may already be theirs.
            $unowned['estimates with no owner (renumbered)'] = 0;
            foreach (DB::table('estimates')->whereNull('user_id')->orderBy('id')->pluck('id') as $estimateId) {
                DB::table('estimates')->where('id', $estimateId)->update([
                    'user_id' => $manager->id,
                    'number' => Estimate::nextNumber($manager),
                ]);
                $unowned['estimates with no owner (renumbered)']++;
            }

            return [
                ...array_filter($unowned),
                'teams' => Team::withoutGlobalScopes()->whereNull('company_id')->update(['company_id' => $company]),
                'crew members' => Foreman::withoutGlobalScopes()->whereNull('company_id')->update(['company_id' => $company]),
                'time-tracking records' => TeamMember::withoutGlobalScopes()->whereNull('company_id')->update(['company_id' => $company]),
                // Crew logins only — another manager with no company is not this company's crew.
                'crew logins' => User::query()
                    ->whereNull('company_id')
                    ->whereRaw('lower(trim(role)) not in (?, ?, ?)', $managerRoles)
                    ->update(['company_id' => $company]),
                // With --managers, the other managers too — and so the clients, projects,
                // estimates, jobs and invoices they made, which follow their owner.
                ...($this->option('managers') ? [
                    'other managers (with their work)' => User::query()
                        ->whereNull('company_id')
                        ->whereKeyNot($manager->id)
                        ->whereRaw('lower(trim(role)) in (?, ?, ?)', $managerRoles)
                        ->update(['company_id' => $company]),
                ] : []),
            ];
        });

        foreach ($counts as $what => $count) {
            $this->line("{$count} {$what} moved to {$manager->email}'s company.");
        }

        return self::SUCCESS;
    }
}
