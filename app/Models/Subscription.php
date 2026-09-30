<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A company's plan. What each plan costs and
 * allows lives in `config/subscription.php`.
 *
 * An account that went through Payment Setup has a row of its own (`user_id`);
 * everyone else shares the one company-wide row that existed before that.
 */
class Subscription extends Model
{
    public const CYCLES = ['monthly', 'yearly'];

    protected $fillable = ['user_id', 'plan', 'status', 'stripe_customer_id', 'stripe_subscription_id', 'confirmation_number', 'receipt_url', 'billing_cycle', 'renews_on', 'updated_by'];

    protected function casts(): array
    {
        return ['renews_on' => 'date'];
    }

    /**
     * The subscription this person works under: their own if they bought one,
     * otherwise the company-wide row.
     */
    public static function forUser(?User $user): self
    {
        $own = $user ? static::query()->where('user_id', $user->id)->first() : null;

        return static::rolledForward($own ?? static::legacy());
    }

    /** The company-wide row, made on first look on the default plan, monthly. */
    public static function current(): self
    {
        return static::rolledForward(static::legacy());
    }

    private static function legacy(): self
    {
        $plan = config('subscription.default_plan');

        return static::query()->whereNull('user_id')->first() ?? static::create([
            'plan' => $plan,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'renews_on' => Carbon::today()->addMonth(),
        ]);
    }

    /** Rolled forward to the next renewal once the date it was due has passed. */
    private static function rolledForward(self $subscription): self
    {
        while ($subscription->renews_on->lt(Carbon::today())) {
            $subscription->renews_on = $subscription->billing_cycle === 'yearly'
                ? $subscription->renews_on->copy()->addYear()
                : $subscription->renews_on->copy()->addMonth();
        }

        if ($subscription->isDirty('renews_on')) {
            $subscription->save();
        }

        return $subscription;
    }

    /** A reference to quote, like "BMA-284739-6F2A": six digits, then four characters. */
    public static function newConfirmationNumber(): string
    {
        do {
            $number = sprintf('BMA-%06d-%s', random_int(0, 999999), strtoupper(bin2hex(random_bytes(2))));
        } while (static::query()->where('confirmation_number', $number)->exists());

        return $number;
    }

    /** The start of the cycle now running — one period before it renews. */
    public function cycleStart(): Carbon
    {
        return $this->billing_cycle === 'yearly'
            ? $this->renews_on->copy()->subYear()
            : $this->renews_on->copy()->subMonth();
    }
}
