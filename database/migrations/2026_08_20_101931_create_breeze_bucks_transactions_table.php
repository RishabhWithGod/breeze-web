<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single ledger of every Breeze Bucks change. A balance is never
     * stored anywhere — it is always `SUM(amount)` over this table for a
     * user, so there is no denormalized counter that can drift from the
     * transaction history. `amount` is signed: positive for earned/bonus,
     * negative for redeemed/reversal/adjustment-down.
     */
    public function up(): void
    {
        Schema::create('breeze_bucks_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('amount');
            $table->integer('balance_after');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breeze_bucks_transactions');
    }
};
