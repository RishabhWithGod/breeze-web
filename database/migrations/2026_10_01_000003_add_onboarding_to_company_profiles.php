<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            // Which optional setup steps the company chose to skip, and when it called setup finished.
            $table->json('onboarding_skipped')->nullable()->after('logo_path');
            $table->timestamp('onboarding_finished_at')->nullable()->after('onboarding_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', fn (Blueprint $table) => $table->dropColumn(['onboarding_skipped', 'onboarding_finished_at']));
    }
};
