<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('symbol_reviews', function (Blueprint $table) {
            $table->json('occurrences')->nullable()->after('bbox');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('symbol_reviews', function (Blueprint $table) {
            $table->dropColumn('occurrences');
        });
    }
};
