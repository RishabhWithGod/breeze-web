<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per processor the app knows how to speak to (Stripe, PayPal,
     * Square) — seeded as `not_connected` for all three, never defaulted to
     * `active`. `credentials` is the one place an API secret is ever stored,
     * and it is only ever written/read through the `encrypted` cast on the
     * model — nothing here or in the app puts a raw secret in a response.
     */
    public function up(): void
    {
        Schema::create('payment_processors', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('display_name');
            $table->string('status')->default('not_connected');
            $table->text('credentials')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_processors');
    }
};
