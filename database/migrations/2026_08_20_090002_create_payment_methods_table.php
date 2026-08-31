<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saved payment method belongs to a connected processor — never
     * created standalone. Only what a real tokenized-payment-method response
     * would ever return is stored: brand, last four, expiry, and the
     * processor's own opaque reference. No full card number, no CVV, ever.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_processor_id')->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->string('last_four', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            /** The processor's own token/reference for this method — opaque to us. */
            $table->string('external_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
