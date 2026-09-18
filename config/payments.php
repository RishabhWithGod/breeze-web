<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | `BillingSetting::current()` creates the single settings row from these
    | the first time anything asks for it — the same convention
    | `TimeTrackingSetting::current()` already uses.
    |
    */
    'defaults' => [
        'auto_send_invoices' => false,
        'include_payment_instructions' => false,
        'send_payment_reminders' => false,
        'apply_late_fees_automatically' => false,
        'default_payment_terms' => 'due-on-receipt',
        'default_currency' => 'USD',
    ],

    'payment_terms' => [
        'due-on-receipt' => 'Due on receipt',
        'net-15' => 'Net 15',
        'net-30' => 'Net 30',
        'net-45' => 'Net 45',
        'net-60' => 'Net 60',
    ],

    /*
    | This app formats every amount as USD (see resources/js/utils/format.ts)
    | and the Stripe connector charges in USD only, so USD is the only
    | selectable currency.
    */
    'currencies' => [
        'USD' => 'USD - US Dollar',
    ],

    'per_page' => 10,

];
