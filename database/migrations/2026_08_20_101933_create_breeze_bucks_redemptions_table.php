<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per redemption, linked to the ledger transaction that actually
     * moved the points — the transaction is the audit trail, this is the
     * fulfillment record.
     */
    public function up(): void
    {
        Schema::create('breeze_bucks_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('breeze_bucks_transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points_spent');
            $table->string('status')->default('completed');
            $table->uuid('redemption_reference')->unique();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breeze_bucks_redemptions');
    }
};
