<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable on purpose: the existing seeded rows (`position >= 0`) are
 * reference/demo content meant for everyone and stay ownerless. A real event
 * recorded from here on carries the acting manager's id, so one manager's
 * dashboard never narrates another manager's jobs and invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feed_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
