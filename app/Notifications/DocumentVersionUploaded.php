<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells interested users a newer version of a document they care about now exists. */
class DocumentVersionUploaded extends Notification
{
    public function __construct(
        public readonly Document $version,
        public readonly User $uploadedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        return [
            'type' => 'document-version-uploaded',
            'title' => "New version of “{$this->version->name}”",
            'detail' => "{$this->uploadedBy->name} uploaded version {$this->version->version}.0.",
            'link' => route('documents.preview', $this->version, absolute: false),
            'data' => [
                'actions' => [
                    ['label' => 'View Document', 'href' => route('documents.preview', $this->version, absolute: false)],
                    ['label' => 'Open Documents', 'href' => route('documents.index', absolute: false)],
                ],
            ],
        ];
    }
}
