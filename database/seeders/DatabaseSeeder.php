<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * `DemoDataSeeder` creates real, publicly-known-password accounts
     * (including an admin-equivalent role) purely for local development —
     * running `db:seed`/`migrate --seed` against a production database
     * would create real, guessable logins on a real system. Refuses to
     * run there unless `ALLOW_DEMO_SEEDING=true` is set explicitly, for
     * the one legitimate exception (a demo/staging environment that is
     * deliberately meant to carry this data).
     *
     * Note: no WithoutModelEvents here — User derives its avatar initials in a
     * saving hook, and muting model events would leave them blank.
     */
    public function run(): void
    {
        if (app()->environment('production') && ! env('ALLOW_DEMO_SEEDING', false)) {
            throw new \RuntimeException(
                'Refusing to seed demo data (including known-password accounts) into a '
                .'production environment. Set ALLOW_DEMO_SEEDING=true if this environment '
                .'is deliberately a demo/staging instance meant to carry this data.'
            );
        }

        $this->call(DemoDataSeeder::class);
        // Runs after the jobs exist: it books crew onto them.
        $this->call(SchedulingSeeder::class);
    }
}
