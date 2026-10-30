<?php

namespace App\Services\JobScope;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JobScopeChange;
use Illuminate\Support\Collection;

/**
 * Notices what changed on an estimate between its lines being touched and
 * its totals being worked out, and hands one entry to the job's timeline.
 *
 * Lines are written one at a time — and the Estimate Builder deletes and
 * rewrites its own on every save — so the line-by-line events cannot be the
 * entries themselves. Instead the first touch in a request takes a picture
 * of the estimate's lines as they were, and when its totals are next worked
 * out (`Estimate::recalculateTotals()`, which every writer ends with) the
 * lines are compared against that picture: what was added, removed, or
 * changed. A rewrite that lands on the same lines is therefore no change.
 */
class EstimateChangeRecorder
{
    /** Past this many, the entry says how many were left out instead of listing them all. */
    private const MAX_LISTED = 200;

    private const TOTALS = ['material_total', 'labor_total', 'equipment_total', 'subtotal', 'grand_total'];

    /** @var array<int, array{items: Collection, settings: array<string, array{from: mixed, to: mixed}>}> */
    private static array $pending = [];

    private static bool $registered = false;

    /** Called just before any of an estimate's lines is created, changed or removed. */
    public static function touch(?int $estimateId): void
    {
        if ($estimateId === null || isset(self::$pending[$estimateId])) {
            return;
        }

        self::$pending[$estimateId] = ['items' => self::lines($estimateId), 'settings' => []];

        // A change with no recalculation after it is still a change: written when the request ends.
        if (! self::$registered) {
            self::$registered = true;
            app()->terminating(fn () => self::flushAll());
        }
    }

    /** A markup or tax rate that moved — part of the next entry, not an entry of its own. */
    public static function noteSetting(Estimate $estimate, string $field, mixed $from, mixed $to): void
    {
        self::touch($estimate->id);
        self::$pending[$estimate->id]['settings'][$field] = ['from' => $from, 'to' => $to];
    }

    public static function hasPending(int $estimateId): bool
    {
        return isset(self::$pending[$estimateId]);
    }

    /** True when this save of the estimate moved any of its totals. */
    public static function totalsMoved(Estimate $estimate): bool
    {
        return $estimate->wasChanged(self::TOTALS);
    }

    /**
     * Writes the entry for this estimate, if anything actually changed.
     *
     * @param  array<string, mixed>  $before  the estimate's attributes as they were
     */
    public static function flush(Estimate $estimate, array $before): ?JobScopeChange
    {
        $pending = self::$pending[$estimate->id] ?? null;
        unset(self::$pending[$estimate->id]);

        $created = $estimate->wasRecentlyCreated || ($before['id'] ?? null) === null;
        $totalsBefore = self::totals($before);
        $totalsAfter = self::totals($estimate->getAttributes());
        $changes = $pending ? self::diff($pending['items'], self::lines($estimate->id)) : [];
        $settings = $pending['settings'] ?? [];

        if ($changes === [] && $settings === [] && $totalsBefore === $totalsAfter) {
            return null;
        }

        $kinds = collect($changes)->countBy('action');
        $parts = array_filter([
            $kinds['added'] ?? 0 ? "{$kinds['added']} line".(($kinds['added'] > 1) ? 's' : '').' added' : null,
            $kinds['changed'] ?? 0 ? "{$kinds['changed']} changed" : null,
            $kinds['removed'] ?? 0 ? "{$kinds['removed']} removed" : null,
            $settings !== [] ? implode(' & ', array_map(fn ($f) => str_replace('_pct', ' rate', $f), array_keys($settings))).' updated' : null,
        ]);
        $summary = $parts !== [] ? ucfirst(implode(', ', $parts)) : 'Estimate totals updated';
        if ($created && ($kinds['added'] ?? 0) > 0 && ($before['grand_total'] ?? 0) == 0) {
            $summary = "Estimate priced with {$kinds['added']} line".($kinds['added'] > 1 ? 's' : '');
        }

        $listed = array_slice($changes, 0, self::MAX_LISTED);
        if (count($changes) > self::MAX_LISTED) {
            $summary .= ' (first '.self::MAX_LISTED.' listed)';
        }

        return app(JobScopeLog::class)->record($estimate->job, JobScopeChange::KIND_ESTIMATE, $summary, [
            'estimate_id' => $estimate->id,
            'item_changes' => [...$listed, ...($settings !== [] ? [['action' => 'settings', 'settings' => $settings]] : [])],
            'totals_before' => $totalsBefore,
            'totals_after' => $totalsAfter,
            'amount' => round(($totalsAfter['grand_total'] ?? 0) - ($totalsBefore['grand_total'] ?? 0), 2),
        ]);
    }

    /** Whatever is still waiting when the request ends. */
    public static function flushAll(): void
    {
        foreach (array_keys(self::$pending) as $estimateId) {
            $estimate = Estimate::find($estimateId);
            if ($estimate !== null) {
                self::flush($estimate, $estimate->getAttributes());
            }
        }

        self::$pending = [];
    }

    /** For tests: forget anything pending. */
    public static function reset(): void
    {
        self::$pending = [];
    }

    /** @return array<string, float> */
    private static function totals(array $attributes): array
    {
        return collect(self::TOTALS)
            ->mapWithKeys(fn (string $key) => [$key => round((float) ($attributes[$key] ?? 0), 2)])
            ->all();
    }

    /** @return Collection<int, EstimateItem> */
    private static function lines(int $estimateId): Collection
    {
        return EstimateItem::query()
            ->where('estimate_id', $estimateId)
            ->get(['id', 'estimate_id', 'builder_line_id', 'category', 'description', 'unit', 'quantity', 'unit_cost', 'total']);
    }

    /**
     * @param  Collection<int, EstimateItem>  $before
     * @param  Collection<int, EstimateItem>  $after
     * @return list<array<string, mixed>>
     */
    private static function diff(Collection $before, Collection $after): array
    {
        $before = self::keyed($before);
        $after = self::keyed($after);
        $changes = [];

        foreach ($after as $key => $item) {
            $old = $before->get($key);

            if ($old === null) {
                $changes[] = self::entry('added', $item, null);
            } elseif (self::differs($old, $item)) {
                $changes[] = self::entry('changed', $item, $old);
            }
        }

        foreach ($before->diffKeys($after) as $item) {
            $changes[] = self::entry('removed', null, $item);
        }

        return $changes;
    }

    /**
     * A line has no stable id across a rewrite, so it is known by what it is:
     * the builder row it came from, else its description — with a counter so
     * two identical lines stay two.
     *
     * @param  Collection<int, EstimateItem>  $items
     * @return Collection<string, EstimateItem>
     */
    private static function keyed(Collection $items): Collection
    {
        $seen = [];

        return $items->mapWithKeys(function (EstimateItem $item) use (&$seen) {
            $base = $item->category.'|'.($item->builder_line_id ?? mb_strtolower(trim((string) $item->description))).'|'.$item->unit;
            $seen[$base] = ($seen[$base] ?? 0) + 1;

            return [$base.'#'.$seen[$base] => $item];
        });
    }

    private static function differs(EstimateItem $a, EstimateItem $b): bool
    {
        return round((float) $a->quantity, 4) !== round((float) $b->quantity, 4)
            || round((float) $a->unit_cost, 4) !== round((float) $b->unit_cost, 4)
            || round((float) $a->total, 2) !== round((float) $b->total, 2)
            || trim((string) $a->description) !== trim((string) $b->description);
    }

    /** @return array<string, mixed> */
    private static function entry(string $action, ?EstimateItem $now, ?EstimateItem $was): array
    {
        $item = $now ?? $was;

        return [
            'action' => $action,
            'category' => $item->category,
            'description' => $item->description,
            'unit' => $item->unit,
            'qty_before' => $was ? (float) $was->quantity : null,
            'qty_after' => $now ? (float) $now->quantity : null,
            'total_before' => $was ? (float) $was->total : null,
            'total_after' => $now ? (float) $now->total : null,
        ];
    }
}
