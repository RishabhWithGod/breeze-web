<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a manager has already made their account, with a password the manager
 * chose. The password is never in the email — they are told to ask for it.
 */
class TeamMemberAdded extends Notification
{
    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $company,
        public readonly ?string $addedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->addedBy ?? 'A manager';

        return (new MailMessage)
            ->subject("You've been added to {$this->company} on Breeze.Ai")
            ->greeting("Hi {$this->invitation->name},")
            ->line("{$who} added you to {$this->company} on Breeze.Ai as a {$this->invitation->role}.")
            ->line("Sign in with this email address ({$this->invitation->email}) and the password {$who} set up for you.")
            ->action('Sign in', route('login'));
    }
}
