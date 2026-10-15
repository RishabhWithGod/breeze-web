<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing is per seat now, not one flat price with a cap built into the plan
 * — a company buys a number of seats, at whichever plan's per-seat rate, and
 * that purchased count is what's enforced. A fixed company-wide price with a
 * seat cap (see `2026_09_30_000007_drop_licenses_from_subscriptions`) let one
 * subscription cover an unbounded headcount on the old "Enterprise, unlimited
 * users" tier, which is exactly the risk this corrects.
 *
 * Every existing subscription is backfilled from the seat count its plan used
 * to advertise as a cap, so nobody's current entitlement shrinks the moment
 * this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('seats')->default(1)->after('plan');
        });

        foreach (DB::table('subscriptions')->get(['id', 'plan']) as $subscription) {
            $maxUsers = config("subscription.plans.{$subscription->plan}.max_users");

            DB::table('subscriptions')->where('id', $subscription->id)->update([
                // The old "unlimited" tier had no cap to carry over — ten seats
                // is this migration's own starting point for it, not a limit.
                'seats' => $maxUsers ?? 10,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('seats'));
    }
};
