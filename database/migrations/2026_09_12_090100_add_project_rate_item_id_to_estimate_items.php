<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of this project's own rates a line was quoted at.
 *
 * A sibling to `price_book_item_id` rather than a replacement for it: old
 * lines keep pointing at the price book row they were actually priced from,
 * and nothing is backfilled or migrated. Every line written from here on
 * uses this column instead — an estimate no longer reads the price book at
 * all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->foreignId('project_rate_item_id')->nullable()->after('price_book_item_id')
                ->constrained('project_rate_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_rate_item_id');
        });
    }
};
