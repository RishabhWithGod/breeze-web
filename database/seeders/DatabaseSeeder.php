<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Note: no WithoutModelEvents here — User derives its avatar initials in a
     * saving hook, and muting model events would leave them blank.
     */
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);
    }
}
