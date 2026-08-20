<?php

namespace App\Notifications;

use App\Models\Job;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/**
 * Tells a manager a job just went over budget — labor, material, or the
 * total. Bell only, the same as a time-entry submission: this is a heads-up
 * to go look, not a decision that needs an inbox.
 */
class JobCostOverrun extends Notification
{
    public function __construct(
        public readonly Job $job,
        public readonly string $reason,
        public readonly float $amount,
        public readonly ?float $pct,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('job-costing.show', $this->job, absolute: false);

        return [
            'type' => 'job-cost-overrun',
            'title' => "{$this->job->name} is over budget",
            'detail' => $this->sentence(),
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Job Costing', 'href' => $link]]],
        ];
    }

    private function sentence(): string
    {
        $amount = number_format($this->amount, 2);
        $pct = $this->pct !== null ? ' ('.$this->pct.'% over)' : '';

        return match ($this->reason) {
            'labor_hours', 'labor_cost' => "Labor costs are exceeding budget by \${$amount}{$pct}.",
            'material_cost' => "Material costs are exceeding budget by \${$amount}{$pct}.",
            default => "Total cost is exceeding budget by \${$amount}{$pct}.",
        };
    }
}
