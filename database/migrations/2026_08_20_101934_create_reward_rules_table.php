<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per real business event that can award Breeze Bucks. A rule
     * disabled or missing here means that event never awards points — the
     * point value is never hard-coded in a listener.
     */
    public function up(): void
    {
        Schema::create('reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->unique();
            $table->unsignedInteger('points');
            $table->boolean('enabled')->default(true);
            $table->string('description');
            $table->json('role_restriction')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_rules');
    }
};
