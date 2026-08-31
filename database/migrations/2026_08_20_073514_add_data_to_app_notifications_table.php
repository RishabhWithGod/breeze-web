<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured payload for the Notification Center: multiple labelled
     * actions (`{"actions": [{"label": "...", "href": "..."}]}`) rather than
     * the single `link` column, which stays as the legacy fallback for
     * notification classes that only ever needed one destination.
     */
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->json('data')->nullable()->after('link');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
