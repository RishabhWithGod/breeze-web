<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A team is its name, and nothing else.
 *
 * The notes field was never asked for and never read: what a crew covers is
 * visible from the work they are carrying, and a free-text box beside a name is
 * one more thing to keep true. Dropped rather than left nullable and unused,
 * because an unused column is a column something will eventually write to by
 * accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teams', 'notes')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('teams', 'notes')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('name');
        });
    }
};
