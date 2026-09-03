<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The address book belongs to the client, not to one of their projects.
 *
 * A client with three projects on the same building recorded that address
 * three times, and three copies drift. One book, and a project picks from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_addresses', 'client_id')) {
            Schema::table('client_addresses', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('id')
                    ->constrained('clients')->cascadeOnDelete();
            });
        }

        DB::table('client_addresses')
            ->join('projects', 'projects.id', '=', 'client_addresses.project_id')
            ->whereNull('client_addresses.client_id')
            ->update(['client_addresses.client_id' => DB::raw('projects.client_id')]);

        /*
         * Two projects of the same client that recorded the same address now
         * both point at the client — so the duplicates have to go, keeping the
         * oldest of each. Anything a job is standing on is kept whatever its
         * age: dropping it would take the job's site with it.
         */
        $duplicates = DB::table('client_addresses as a')
            ->join('client_addresses as b', function ($join) {
                $join->on('a.client_id', '=', 'b.client_id')
                    ->on('a.address', '=', 'b.address')
                    ->whereColumn('a.id', '>', 'b.id');
            })
            ->whereNotNull('a.client_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('job_addresses')
                ->whereColumn('job_addresses.client_address_id', 'a.id'))
            ->distinct()
            ->pluck('a.id');

        if ($duplicates->isNotEmpty()) {
            DB::table('client_addresses')->whereIn('id', $duplicates)->delete();
        }

        if (Schema::hasColumn('client_addresses', 'project_id')) {
            Schema::table('client_addresses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_addresses', 'project_id')) {
            Schema::table('client_addresses', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('id')
                    ->constrained('projects')->cascadeOnDelete();
            });
        }
    }
};
