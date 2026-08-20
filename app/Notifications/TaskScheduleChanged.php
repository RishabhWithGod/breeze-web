<?php

namespace App\Notifications;

use App\Models\JobTask;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the people on a task that something about it changed.
 *
 * One notification with a reason rather than four near-identical classes: the
 * recipient, the link and the delivery are the same in every case, and only the
 * sentence differs. A new reason is a line in a match, not a new file.
 */
class TaskScheduleChanged extends Notification
{
    public const ASSIGNED = 'assigned';

    public const RESCHEDULED = 'rescheduled';

    public const DEADLINE_NEAR = 'deadline-near';

    public const COMPLETED = 'completed';

    public const DELAYED = 'delayed';

    public const REASONS = [
        self::ASSIGNED,
        self::RESCHEDULED,
        self::DEADLINE_NEAR,
        self::COMPLETED,
        self::DELAYED,
    ];

    public function __construct(
        public readonly JobTask $task,
        public readonly string $reason,
        /** Extra context, e.g. the old dates on a reschedule. */
        public readonly ?string $detail = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        /*
         * The bell always; email only for the things that change someone's day. A
         * completion is a record, not news — it does not need to reach an inbox.
         */
        return $this->reason === self::COMPLETED
            ? [AppNotificationChannel::class]
            : ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jobName = $this->task->job?->name ?? 'a job';

        return (new MailMessage)
            ->subject("{$this->headline()}: {$this->task->title}")
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->headline()} on {$jobName}.")
            ->line("Task: {$this->task->title}")
            ->line($this->sentence())
            ->action('Open the schedule', $this->url(absolute: true))
            ->line('Dates and assignments are always live on the schedule.');
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $taskUrl = $this->url(absolute: false);
        $jobUrl = route('jobs.show', $this->task->job_id, absolute: false);

        return [
            'type' => "task-{$this->reason}",
            'title' => "{$this->headline()}: {$this->task->title}",
            'detail' => $this->sentence(),
            'link' => $taskUrl,
            'data' => [
                'actions' => $this->reason === self::ASSIGNED
                    ? [['label' => 'View Task', 'href' => $taskUrl], ['label' => 'View Job', 'href' => $jobUrl]]
                    : [['label' => 'View Schedule', 'href' => $taskUrl], ['label' => 'View Job', 'href' => $jobUrl]],
            ],
        ];
    }

    /* ------------------------------------------------------------- internals */

    private function headline(): string
    {
        return match ($this->reason) {
            self::ASSIGNED => 'Task assigned',
            self::RESCHEDULED => 'Task rescheduled',
            self::DEADLINE_NEAR => 'Deadline approaching',
            self::COMPLETED => 'Task completed',
            self::DELAYED => 'Task delayed',
            default => 'Task updated',
        };
    }

    private function sentence(): string
    {
        if ($this->detail !== null) {
            return $this->detail;
        }

        $due = $this->task->ends_on?->format('M j, Y');

        return match ($this->reason) {
            self::ASSIGNED => $due ? "You are on this task, due {$due}." : 'You are on this task.',
            self::RESCHEDULED => $due ? "It now finishes {$due}." : 'Its dates changed.',
            self::DEADLINE_NEAR => $due ? "It is due {$due}." : 'It is due shortly.',
            self::COMPLETED => 'It has been marked complete.',
            self::DELAYED => $due ? "It has slipped to {$due}." : 'It has been marked delayed.',
            default => 'The task changed.',
        };
    }

    private function url(bool $absolute): string
    {
        return route('jobs.schedule.show', $this->task->job_id, absolute: $absolute)
            ."#task-{$this->task->id}";
    }
}
