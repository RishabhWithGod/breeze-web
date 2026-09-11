<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a crew register row back to the account it was synced from, when it
 * was. A technician approved from the mobile app is still tracked as a
 * `User`/`team_members` row for time-tracking, but the Teams page's actual
 * per-team roster reads `foremen` — this is what lets a manager assigning
 * them a team upsert one real `foremen` row instead of duplicating it every
 * time, and what lets the register stop listing them separately once synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foremen', function (Blueprint $table) {
            if (! Schema::hasColumn('foremen', 'user_id')) {
                $table->foreignId('user_id')->nullable()->unique()->after('id')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foremen', function (Blueprint $table) {
            if (Schema::hasColumn('foremen', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }
};
