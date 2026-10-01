<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts an account back at the start of first-run setup, for testing it again.
 */
class ResetAccountSetup extends Command
{
    protected $signature = 'setup:reset {email : The account to send back through company setup, terms and payment}';

    protected $description = 'Delete an account\'s company profile, signed terms, card and subscription and make it do first-run setup again';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user) {
            if ($logo = $user->company?->logo_path) {
                Storage::disk('public')->delete($logo);
            }

            $user->company()->delete();
            $user->termsAcceptances()->delete();
            \App\Models\SubscriptionCard::where('user_id', $user->id)->delete();
            \App\Models\Subscription::where('user_id', $user->id)->delete();
            $user->forceFill([
                'needs_company_setup' => true,
                'needs_terms_acceptance' => true,
                'needs_payment_setup' => true,
            ])->save();
        });

        $this->info("{$user->email} will go through company setup, terms and payment setup again on its next page load.");

        return self::SUCCESS;
    }
}
