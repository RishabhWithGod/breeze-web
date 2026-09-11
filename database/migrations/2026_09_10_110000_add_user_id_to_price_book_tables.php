<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the one shared price book into one per user.
 *
 * `user_id` is nullable on all three tables on purpose: a null row is the
 * "universal" book everything already had (imported off `pricebook:import`),
 * and it is what an estimate falls back to for a user who has never uploaded
 * a rate list of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_book_imports', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->dropUnique(['file_hash']);
            $table->unique(['user_id', 'file_hash']);
        });

        Schema::table('price_book_lines', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('price_book_import_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'match_key', 'unit']);
        });

        Schema::table('price_book_items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->dropUnique(['match_key', 'unit']);
            $table->unique(['user_id', 'match_key', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::table('price_book_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'match_key', 'unit']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['match_key', 'unit']);
        });

        Schema::table('price_book_lines', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'match_key', 'unit']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('price_book_imports', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'file_hash']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique('file_hash');
        });
    }
};
