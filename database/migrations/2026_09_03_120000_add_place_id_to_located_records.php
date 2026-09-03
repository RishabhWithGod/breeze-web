<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google place a stored address came from.
 *
 * Additive, and nullable everywhere: every address already on record was typed
 * or geocoded before this existed, and none of them are wrong for having no
 * place id. Nothing is backfilled — inventing one would mean guessing which
 * real-world place an old string meant.
 *
 * `client_addresses` is where an address actually lives. `work_jobs` and
 * `projects` keep a snapshot of the address they stand on — scheduling, time
 * tracking and a printed job sheet all read those columns rather than joining
 * — so the id travels with the snapshot, beside the coordinates it belongs to.
 */
return new class extends Migration
{
    private const TABLES = ['client_addresses', 'work_jobs', 'projects'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'place_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                // Google's ids are opaque and have no documented maximum, but
                // run well under 255. Indexed because the mobile app will look
                // a site up by it.
                $blueprint->string('place_id')->nullable()->after('longitude')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'place_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex([$blueprint->getTable().'_place_id_index']);
                $blueprint->dropColumn('place_id');
            });
        }
    }
};
