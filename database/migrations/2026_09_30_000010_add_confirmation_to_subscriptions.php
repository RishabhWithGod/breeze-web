<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the Subscription Confirmed screen shows: a reference the customer can
        // quote, and Stripe's own page for the receipt.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('confirmation_number', 32)->nullable()->unique()->after('stripe_subscription_id');
            $table->text('receipt_url')->nullable()->after('confirmation_number');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['confirmation_number', 'receipt_url']));
    }
};
