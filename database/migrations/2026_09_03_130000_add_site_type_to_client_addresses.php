<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of building a site is — residential, commercial or industrial.
 *
 * It belongs to the address rather than to the client or the project: one
 * client can own a house and a warehouse, and it is the building that decides
 * how the work is priced and crewed. A job raised at a site takes its type from
 * here, which is why the question is asked once, where the site is recorded.
 *
 * Nullable, and nothing is backfilled: every site already on record was entered
 * before this was asked, and guessing a warehouse from an address is worse than
 * leaving it blank for someone to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_addresses', 'site_type')) {
            return;
        }

        Schema::table('client_addresses', function (Blueprint $table) {
            // The same three words a job and a project use, kept as a string
            // rather than an enum so adding a fourth is a code change, not a
            // table rebuild.
            $table->string('site_type', 24)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_addresses', 'site_type')) {
            return;
        }

        Schema::table('client_addresses', function (Blueprint $table) {
            $table->dropColumn('site_type');
        });
    }
};
