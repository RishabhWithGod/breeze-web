<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The email that invites someone to join a company, with the link that accepts it. */
class TeamInvitationSent extends Notification
{
    /**
     * @param  string  $token  the link's token — only its hash is kept, so it has to travel with the mail
     */
    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $token,
        public readonly string $company,
        public readonly ?string $inviter,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->inviter ?? 'A manager';

        return (new MailMessage)
            ->subject("You're invited to join {$this->company} on Breeze.Ai")
            ->greeting("Hi {$this->invitation->name},")
            ->line("{$who} invited you to join {$this->company} on Breeze.Ai as a {$this->invitation->role}.")
            ->action('Accept the invitation', route('invitations.show', $this->token))
            ->line('The link is open until '.$this->invitation->expires_at->format('M j, Y').'. If you were not expecting this, you can ignore the email.');
    }
}
