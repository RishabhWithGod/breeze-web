<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The takeoff a person is part-way through, so they can leave from one
 * client and pick it back up from the other.
 *
 * Previously just a PHP session key (`TakeoffFlow`'s own `takeoff_flow_
 * project_id`) — fine while "leaving" only meant a browser tab, but a
 * mobile Sanctum request carries a bearer token, not a session cookie, so
 * nothing about it survived between requests, and a session on one device
 * was never visible to the other anyway. One column, one account, read and
 * written by both: `TakeoffFlow::remember()`/`forget()` (web's own screens)
 * and `Api\V1\TakeoffFlowController` (mobile's), so leaving the flow on
 * either one's own screens hands it to the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('takeoff_flow_project_id')->nullable()
                ->after('id')->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('takeoff_flow_project_id');
        });
    }
};
