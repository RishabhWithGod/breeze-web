<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A foreman was a name and a set of initials. That is enough to hand a task to
 * someone, and not enough to reach them once you have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foremen', function (Blueprint $table) {
            if (! Schema::hasColumn('foremen', 'phone')) {
                $table->string('phone', 40)->nullable()->after('initials');
            }

            if (! Schema::hasColumn('foremen', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }

            // What lets them sign off work on site.
            if (! Schema::hasColumn('foremen', 'licence_number')) {
                $table->string('licence_number', 60)->nullable()->after('email');
            }

            if (! Schema::hasColumn('foremen', 'started_on')) {
                $table->date('started_on')->nullable()->after('licence_number');
            }

            if (! Schema::hasColumn('foremen', 'notes')) {
                $table->text('notes')->nullable()->after('started_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foremen', function (Blueprint $table) {
            foreach (['phone', 'email', 'licence_number', 'started_on', 'notes'] as $column) {
                if (Schema::hasColumn('foremen', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
