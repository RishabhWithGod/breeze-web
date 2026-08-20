<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per user. `two_factor_enabled` only ever flips to true from
     * `TwoFactorService::confirmEnable()` after a real OTP is verified —
     * never from the toggle alone.
     */
    public function up(): void
    {
        Schema::create('user_security_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_method')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_security_settings');
    }
};
