<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commodity list is a company's price book under another name, so what it needs beyond
 * the rate itself — an item code, a markup, and a way to retire an item without losing it —
 * lives on the price book's own items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_book_items', function (Blueprint $table) {
            $table->string('item_code', 40)->nullable()->after('description');
            $table->decimal('markup_pct', 6, 2)->nullable()->after('unit_manhours');
            $table->timestamp('archived_at')->nullable()->after('is_pinned');
            $table->unique(['user_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::table('price_book_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'item_code']);
            $table->dropColumn(['item_code', 'markup_pct', 'archived_at']);
        });
    }
};
