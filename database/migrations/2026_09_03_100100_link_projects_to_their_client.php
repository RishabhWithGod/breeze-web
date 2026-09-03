<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every project belongs to a client.
 *
 * Backfilled from `projects.client`, the name the merge left behind. That
 * column stays as a snapshot — every list reads it, and a printed job sheet
 * must not change when a client is later renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'client_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('user_id')
                    ->constrained('clients')->nullOnDelete();
            });
        }

        /*
         * One client per distinct name per owner. Scoped to the owner because
         * two workspaces may each have a "Smith" and they are not the same
         * person; falls back to the project's own name for a row that never
         * got a client name.
         */
        $groups = DB::table('projects')
            ->selectRaw('user_id, COALESCE(NULLIF(client, ""), name) AS client_name')
            ->whereNull('client_id')
            ->groupBy('user_id', 'client_name')
            ->get();

        foreach ($groups as $group) {
            if (blank($group->client_name)) {
                continue;
            }

            $clientId = DB::table('clients')
                ->where('user_id', $group->user_id)
                ->where('name', $group->client_name)
                ->value('id')
                ?? DB::table('clients')->insertGetId([
                    'user_id' => $group->user_id,
                    'name' => $group->client_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('projects')
                ->where('user_id', $group->user_id)
                ->whereRaw('COALESCE(NULLIF(client, ""), name) = ?', [$group->client_name])
                ->whereNull('client_id')
                ->update(['client_id' => $clientId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'client_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        }
    }
};
