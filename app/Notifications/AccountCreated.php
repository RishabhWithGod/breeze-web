<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Confirms a new account was made — the first email anyone on Breeze.Ai gets. */
class AccountCreated extends Notification
{
    public function __construct(public readonly User $user) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Breeze.Ai')
            ->greeting("Hi {$this->user->name},")
            ->line('Your Breeze.Ai account is ready.')
            ->line("Signed up with: {$this->user->email}")
            ->action('Continue to Breeze.Ai', route('home'));
    }
}
