<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow notifications point at the screen that needs attention, so the bell
 * menu can navigate straight to a review, estimate or job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('type')->default('general')->after('user_id');
            $table->string('link')->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn(['type', 'link']);
        });
    }
};
