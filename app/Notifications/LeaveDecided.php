<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the person whether their leave was approved or turned down — or later taken back. */
class LeaveDecided extends Notification
{
    public function __construct(public readonly LeaveRequest $leave) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hi {$notifiable->name},")
            ->line($this->sentence());

        return filled($this->leave->decision_note) ? $mail->line('Note from your manager: '.$this->leave->decision_note) : $mail;
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        return [
            'type' => 'leave-'.$this->leave->status,
            'title' => $this->headline(),
            'detail' => $this->sentence().(filled($this->leave->decision_note) ? ' "'.$this->leave->decision_note.'"' : ''),
            'link' => null,
            // The phone opens its Leave screen when this is tapped (see `SendPushNotification`).
            'data' => ['target_screen' => 'leave'],
        ];
    }

    private function headline(): string
    {
        return match ($this->leave->status) {
            LeaveRequest::STATUS_APPROVED => 'Your leave was approved',
            LeaveRequest::STATUS_REJECTED => 'Your leave was not approved',
            default => 'Your leave was cancelled',
        };
    }

    private function sentence(): string
    {
        $dates = $this->leave->start_date->format('M j').($this->leave->days() > 1 ? ' – '.$this->leave->end_date->format('M j') : '');

        return match ($this->leave->status) {
            LeaveRequest::STATUS_APPROVED => "Your {$this->leave->typeLabel()} for {$dates} was approved.",
            LeaveRequest::STATUS_REJECTED => "Your {$this->leave->typeLabel()} request for {$dates} was not approved.",
            default => "Your {$this->leave->typeLabel()} for {$dates} was cancelled by a manager.",
        };
    }
}
