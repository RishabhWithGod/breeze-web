<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in becomes a per-channel choice: `two_factor_enabled` stays
 * the *web* setting, and these two columns are the same switch for the mobile
 * app. Turning one on or off never touches the other.
 *
 * Anyone who already had it on keeps it on for the app too — it protected
 * both until now, and this must not quietly weaken an account. From here on
 * they are independent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_security_settings', function (Blueprint $table) {
            $table->boolean('app_two_factor_enabled')->default(false)->after('two_factor_confirmed_at');
            $table->timestamp('app_two_factor_confirmed_at')->nullable()->after('app_two_factor_enabled');
        });

        DB::table('user_security_settings')->where('two_factor_enabled', true)->update([
            'app_two_factor_enabled' => true,
            'app_two_factor_confirmed_at' => DB::raw('two_factor_confirmed_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('user_security_settings', function (Blueprint $table) {
            $table->dropColumn(['app_two_factor_enabled', 'app_two_factor_confirmed_at']);
        });
    }
};
