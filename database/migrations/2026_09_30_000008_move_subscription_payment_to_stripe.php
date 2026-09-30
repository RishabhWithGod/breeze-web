<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The subscription is now paid for through Stripe, so these are the ids
        // that tie a row here to what Stripe holds.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('status');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
        });

        // The card and billing address come back from Stripe, which does not
        // always have every line of an address.
        Schema::table('subscription_cards', function (Blueprint $table) {
            $table->string('address_line1')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state', 64)->nullable()->change();
            $table->string('postal_code', 16)->nullable()->change();
            $table->string('country', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id']));
    }
};
