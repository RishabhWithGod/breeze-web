<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links a crew record to the account that signs in as that person.
 *
 * `users` and `team_members` have always been separate tables, matched only by
 * name string (see `JobSchedulePolicy::isAssigned()`). Time Tracking needs a real
 * link — "log time for yourself" has to resolve to the same identity that task
 * assignments (`job_task_assignments.team_member_id`) use — so this backfills it
 * once here rather than re-matching by name on every request.
 *
 * Rate columns live here too: a labor/billable rate is a property of the person
 * doing the work, not of any one time entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->decimal('billable_rate', 8, 2)->nullable()->after('role');
            $table->decimal('cost_rate', 8, 2)->nullable()->after('billable_rate');
        });

        // One-time backfill: the same case-insensitive/trimmed name match this
        // codebase already relies on elsewhere, done once instead of per-request.
        DB::statement(
            'UPDATE team_members
                JOIN users ON LOWER(TRIM(team_members.name)) = LOWER(TRIM(users.name))
                SET team_members.user_id = users.id
                WHERE team_members.user_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['billable_rate', 'cost_rate']);
        });
    }
};
