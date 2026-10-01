<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which plan, paid how often. The card is never asked for here — Stripe does that. */
class StorePaymentSetupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::in(array_keys(config('subscription.plans')))],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ];
    }
}
