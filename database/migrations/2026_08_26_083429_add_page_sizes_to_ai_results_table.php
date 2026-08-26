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
        Schema::table('ai_results', function (Blueprint $table) {
            // The engine's real per-page raster size for this run, keyed by
            // page number — fetched from its page-info endpoint. Never a
            // guess: absent for a page the engine didn't report.
            $table->json('page_sizes')->nullable()->after('page_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_results', function (Blueprint $table) {
            $table->dropColumn('page_sizes');
        });
    }
};
