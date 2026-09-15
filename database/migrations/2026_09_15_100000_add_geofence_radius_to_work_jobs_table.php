<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How close counts as "on site" for mobile check-in, in metres. `latitude`/
 * `longitude` (added in `add_coordinates_to_clients_and_jobs`) give the
 * point; this is the radius around it. Nullable — a job with no radius set
 * falls back to the mobile app's own 100 m default rather than every
 * existing job needing a value backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('work_jobs', 'geofence_radius')) {
                return;
            }
            $table->unsignedInteger('geofence_radius')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('work_jobs', 'geofence_radius')) {
                $table->dropColumn('geofence_radius');
            }
        });
    }
};
