<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `stock` null means unlimited; a non-null value is decremented on every
     * successful redemption and never allowed to go below zero.
     */
    public function up(): void
    {
        Schema::create('reward_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('points_required');
            $table->string('icon')->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'points_required']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_catalog_items');
    }
};
