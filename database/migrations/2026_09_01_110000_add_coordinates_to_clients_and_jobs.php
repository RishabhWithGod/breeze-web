<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The point behind a Site / Location, captured when the address is picked from
 * the lookup rather than typed blind.
 *
 * Nullable everywhere and always will be: an address typed before this existed
 * has no coordinates, the geocoder may be unconfigured, and a site can be
 * somewhere the geocoder has never heard of. Nothing may assume they are set.
 *
 * `decimal(10, 7)` is roughly 11mm of precision — far past what a street
 * address means, and exact, which a float is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'work_jobs'] as $table) {
            if (Schema::hasColumn($table, 'latitude')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('latitude', 10, 7)->nullable()->after('location');
                $blueprint->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }
    }

    public function down(): void
    {
        foreach (['projects', 'work_jobs'] as $table) {
            if (! Schema::hasColumn($table, 'latitude')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['latitude', 'longitude']);
            });
        }
    }
};
