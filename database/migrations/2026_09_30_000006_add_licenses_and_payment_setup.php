<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Who bought it. Null is the one company-wide row from before
            // accounts had a subscription of their own.
            $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            $table->unsignedInteger('licenses')->default(1)->after('plan');
        });

        // The plans were renamed with the new pricing: Pro became Growth,
        // Business became Enterprise. A plan already held keeps its meaning.
        DB::table('subscriptions')->where('plan', 'pro')->update(['plan' => 'growth']);
        DB::table('subscriptions')->where('plan', 'business')->update(['plan' => 'enterprise']);

        // Whoever signs in today holds a license, so the existing row is given
        // as many as it needs, within what its plan allows.
        $active = User::query()->where('status', User::STATUS_ACTIVE)->count();
        foreach (DB::table('subscriptions')->get() as $row) {
            $plan = config("subscription.plans.{$row->plan}");
            if (! $plan) {
                continue;
            }
            $licenses = max($plan['min_licenses'], $active);
            if ($plan['max_licenses'] !== null) {
                $licenses = min($licenses, $plan['max_licenses']);
            }
            DB::table('subscriptions')->where('id', $row->id)->update(['licenses' => $licenses]);
        }

        // One card per account: what the subscription is paid with. Only what a
        // card processor hands back is kept — never the number or the CVV.
        Schema::create('subscription_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cardholder_name');
            $table->string('brand', 40);
            $table->string('last_four', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city');
            $table->string('state', 64);
            $table->string('postal_code', 16);
            $table->string('country', 2);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('needs_payment_setup')->default(false)->after('needs_terms_acceptance');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('needs_payment_setup'));
        Schema::dropIfExists('subscription_cards');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('licenses');
        });
    }
};
