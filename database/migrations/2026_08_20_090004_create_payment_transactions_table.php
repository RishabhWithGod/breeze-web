<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The real payment ledger this schema never had: one row per money
     * event against an invoice. `processor_id`/`payment_method_id` are
     * nullable because a payment recorded before any processor is connected
     * (or marked paid by hand) is still a real transaction — it's just not
     * one a processor handled, and the row says so honestly rather than
     * inventing a processor for it.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_processor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status');
            /** Snapshot of the invoice's client at the time — this schema has no separate Client model. */
            $table->string('client')->nullable();
            $table->string('description');
            $table->string('external_reference')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
