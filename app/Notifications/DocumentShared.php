<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells a user a document was shared with them. Bell only — a heads-up, not a decision. */
class DocumentShared extends Notification
{
    public function __construct(
        public readonly Document $document,
        public readonly User $sharedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('documents.index', ['tab' => 'shared'], absolute: false);

        return [
            'type' => 'document-shared',
            'title' => "{$this->sharedBy->name} shared a document with you",
            'detail' => "“{$this->document->name}” was shared with you.",
            'link' => $link,
            'data' => [
                'actions' => [
                    ['label' => 'View Document', 'href' => route('documents.preview', $this->document, absolute: false)],
                    ['label' => 'Open Documents', 'href' => $link],
                ],
            ],
        ];
    }
}
