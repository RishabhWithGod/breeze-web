<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An estimate is normally `standalone` — every existing row keeps that
 * meaning. `addendum` is a follow-on estimate for extra scope found after the
 * original takeoff (`parent_estimate_id` says which); `merged` is the roll-up
 * estimate `JobFromEstimatesController` builds when a job is raised from a
 * selected original plus addenda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->string('kind')->default('standalone')->after('status');
            $table->foreignId('parent_estimate_id')->nullable()->after('kind')
                ->constrained('estimates')->nullOnDelete();
            $table->unsignedInteger('addendum_number')->nullable()->after('parent_estimate_id');
            $table->string('addendum_name')->nullable()->after('addendum_number');

            $table->index(['parent_estimate_id', 'addendum_number']);
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_estimate_id');
            $table->dropColumn(['kind', 'addendum_number', 'addendum_name']);
        });
    }
};
