<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The crew register grows a third tier: Foreman / Journeyman / Apprentice.
 *
 * The old Supervisor becomes the new Foreman (keeps the same oversight
 * authority); the old Foreman becomes the new Journeyman (keeps the same
 * task-running rights); Apprentice is new, with no prior data to carry over.
 *
 * Both `foremen.role` and `users.role` (the mobile field-role subset) carry
 * this same vocabulary and are migrated together so the two never drift.
 * `team_members.role` and `job_assignments`/`job_tasks` role are unrelated,
 * pre-existing systems that happen to share some of these words — deliberately
 * untouched here.
 *
 * Single-statement `CASE` updates: each row's replacement is decided from
 * that row's own pre-update value in one pass, so there is no
 * supervisor-becomes-foreman-becomes-journeyman double-hop risk from running
 * two sequential UPDATEs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('foremen')->update([
            'role' => DB::raw("CASE role WHEN 'supervisor' THEN 'foreman' WHEN 'foreman' THEN 'journeyman' ELSE role END"),
        ]);

        Schema::table('foremen', function (Blueprint $table) {
            // Most of the register is journeymen now; a foreman is the
            // exception you pick — the same reasoning the old default
            // ('foreman', back when foreman was the rank-and-file tier) used.
            $table->string('role', 24)->default('journeyman')->change();
        });

        DB::table('users')->update([
            'role' => DB::raw("CASE role WHEN 'Site Supervisor' THEN 'Foreman' WHEN 'Foreman' THEN 'Journeyman' ELSE role END"),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->update([
            'role' => DB::raw("CASE role WHEN 'Foreman' THEN 'Site Supervisor' WHEN 'Journeyman' THEN 'Foreman' ELSE role END"),
        ]);

        Schema::table('foremen', function (Blueprint $table) {
            $table->string('role', 24)->default('foreman')->change();
        });

        DB::table('foremen')->update([
            'role' => DB::raw("CASE role WHEN 'foreman' THEN 'supervisor' WHEN 'journeyman' THEN 'foreman' ELSE role END"),
        ]);
    }
};
