<?php

namespace App\Services\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentProcessor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentMethodService
{
    /** @param  array<string, mixed>  $data */
    public function create(PaymentProcessor $processor, array $data, User $user): PaymentMethod
    {
        return DB::transaction(function () use ($processor, $data, $user) {
            $isFirst = $processor->paymentMethods()->doesntExist();

            $method = $processor->paymentMethods()->create([
                'brand' => $data['brand'],
                'last_four' => $data['last_four'],
                'exp_month' => $data['exp_month'],
                'exp_year' => $data['exp_year'],
                'external_id' => $data['external_id'] ?? null,
                // The very first method on the account is the default by definition.
                'is_default' => $isFirst,
                'created_by' => $user->id,
            ]);

            if ($isFirst) {
                return $method;
            }

            if (! empty($data['make_default'])) {
                $this->makeDefault($method);
            }

            return $method;
        });
    }

    public function makeDefault(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            PaymentMethod::where('id', '!=', $method->id)->update(['is_default' => false]);
            $method->update(['is_default' => true]);
        });
    }

    public function delete(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            $wasDefault = $method->is_default;
            $method->delete();

            if (! $wasDefault) {
                return;
            }

            // Promote whichever method was added most recently — a saved
            // method list is never left without a default while one exists.
            PaymentMethod::latest('id')->first()?->update(['is_default' => true]);
        });
    }
}
