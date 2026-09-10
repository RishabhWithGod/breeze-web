<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The crew a technician belongs to, mirroring `foremen.team_id` exactly.
 *
 * Nullable and nothing is backfilled, for the same reason `foremen.team_id`
 * is: everyone already on the register sits outside any team until a manager
 * says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')
                ->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
