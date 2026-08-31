<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `page` defaulted to 1 for every symbol-type row, even one spread across
     * several pages — there was no honest single value to store. Nullable so
     * an aggregated multi-page symbol can leave it unset rather than lying;
     * its real per-page locations live in `occurrences` instead. Plain SQL
     * because column-type changes need doctrine/dbal, which isn't installed.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite has no real column-nullability toggle; its columns are
            // already permissive enough for this to be a no-op there.
            return;
        }

        DB::statement('ALTER TABLE symbol_reviews MODIFY page SMALLINT UNSIGNED NULL DEFAULT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('UPDATE symbol_reviews SET page = 1 WHERE page IS NULL');
        DB::statement('ALTER TABLE symbol_reviews MODIFY page SMALLINT UNSIGNED NOT NULL DEFAULT 1');
    }
};
