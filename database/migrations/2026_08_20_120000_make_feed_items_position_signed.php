<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real activity is recorded with a position below the lowest one present so
 * it always sorts ahead of everything already in the feed (see
 * FeedItemRecorder) — which requires negative values once enough real rows
 * accumulate. The column started unsigned because only the seeder, counting
 * up from 0, ever wrote to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->integer('position')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->change();
        });
    }
};
