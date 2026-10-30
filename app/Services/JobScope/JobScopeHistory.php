<?php

namespace App\Services\JobScope;

use App\Models\Job;
use App\Models\JobScopeChange;

/**
 * A job's scope timeline as a screen or the phone reads it — newest first,
 * with every dollar figure left out for a role that may not see costs.
 */
class JobScopeHistory
{
    /** The job's current version: the cheap thing a screen compares to know it has the latest. */
    public function version(Job $job): int
    {
        return (int) JobScopeChange::query()->where('job_id', $job->id)->max('version');
    }

    /** @return list<array<string, mixed>> */
    public function for(Job $job, bool $canViewCosts, int $limit = 30): array
    {
        $estimateIds = $job->estimates()->reorder()->pluck('id');

        return JobScopeChange::query()
            ->with('author:id,name')
            ->where(fn ($q) => $q->where('job_id', $job->id)->orWhereIn('estimate_id', $estimateIds))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (JobScopeChange $c) => $this->present($c, $canViewCosts))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function forEstimate(\App\Models\Estimate $estimate, bool $canViewCosts, int $limit = 30): array
    {
        return JobScopeChange::query()
            ->with('author:id,name')
            ->where('estimate_id', $estimate->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (JobScopeChange $c) => $this->present($c, $canViewCosts))
            ->all();
    }

    /**
     * What a screen or the phone needs to stay current: the job's version and
     * its newest changes — only those after `$since` when it already has some.
     *
     * @return array{version: int, history: list<array<string, mixed>>}
     */
    public function payload(Job $job, bool $canViewCosts, ?int $since = null, int $limit = 15): array
    {
        $history = $this->for($job, $canViewCosts, $limit);

        return [
            'version' => $this->version($job),
            'history' => $since === null ? $history : array_values(array_filter($history, fn (array $row) => ($row['version'] ?? 0) > $since)),
        ];
    }

    /** @return array<string, mixed> */
    public function present(JobScopeChange $change, bool $canViewCosts): array
    {
        $lines = collect($change->item_changes ?? [])->map(function (array $line) use ($canViewCosts) {
            if (! $canViewCosts) {
                unset($line['total_before'], $line['total_after']);
            }

            return $line;
        })->all();

        return [
            'id' => $change->id,
            'version' => $change->version,
            'kind' => $change->kind,
            'source' => $change->source,
            'by' => $change->author?->name,
            'at' => $change->created_at?->toIso8601String(),
            'estimateId' => $change->estimate_id,
            'summary' => $change->summary,
            'lines' => $lines,
            'amount' => $canViewCosts ? (float) $change->amount : null,
            'totalBefore' => $canViewCosts ? ($change->totals_before['grand_total'] ?? null) : null,
            'totalAfter' => $canViewCosts ? ($change->totals_after['grand_total'] ?? null) : null,
        ];
    }
}
