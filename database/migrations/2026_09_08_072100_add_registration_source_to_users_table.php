<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a technician who signed up from the mobile app from a
 * `Foreman`/`Site Supervisor` a manager added by hand on the web.
 *
 * `status`/`role` alone cannot answer this once a mobile signup is approved:
 * `status` becomes `active` (the same value every web-created user already
 * has by default), and `role` becomes a real operational role
 * (`Foreman`/`Site Supervisor`) rather than a marker. Without this column,
 * the Teams page has no way to keep showing an approved mobile technician in
 * its own section once they are indistinguishable from anyone else on that
 * role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_source')->default('web')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('registration_source');
        });
    }
};
