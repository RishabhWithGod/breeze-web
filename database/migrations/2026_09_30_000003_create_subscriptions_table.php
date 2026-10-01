<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row, always — the company's plan, like `billing_settings`.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 32);
            $table->string('status', 16)->default('active');
            $table->string('billing_cycle', 16)->default('monthly');
            // The day this cycle ends, and the plan renews.
            $table->date('renews_on');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
