<?php

namespace App\Services\ChangeOrders;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderEvent;
use App\Models\ChangeOrderLine;
use App\Models\Job;
use App\Models\User;
use App\Notifications\ChangeOrderStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A change order's life: made, changed, submitted, then approved or rejected. Every step is
 * written to its history; its totals are always worked out here from its lines, never trusted
 * from the browser.
 */
class ChangeOrders
{
    public function __construct(
        private readonly ChangeOrderAccess $access,
        private readonly ChangeOrderBilling $billing,
    ) {}

    /**
     * @param  array<string, mixed>  $data  description, reason, source, markup_pct
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(User $user, Job $job, array $data, array $lines): ChangeOrder
    {
        return DB::transaction(function () use ($user, $job, $data, $lines) {
            $ownerId = $this->access->ownerId($user);
            $next = (int) ChangeOrder::query()->where('owner_id', $ownerId)->orderByDesc('number')->lockForUpdate()->value('number') + 1;

            $co = ChangeOrder::create([
                ...$this->details($user, $data),
                'owner_id' => $ownerId,
                'job_id' => $job->id,
                'number' => $next,
                'created_by' => $user->id,
                'client_key' => $data['client_key'] ?? null,
            ]);

            $this->writeLines($co, $lines);
            $this->event($co, $user, 'created');

            return $co->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(ChangeOrder $co, User $user, array $data, array $lines): ChangeOrder
    {
        return DB::transaction(function () use ($co, $user, $data, $lines) {
            $wasRejected = $co->status === ChangeOrder::STATUS_REJECTED;

            $co->fill($this->details($user, $data));
            if ($wasRejected) {
                // Changing a rejected change order takes it back to draft, ready to be sent again.
                $co->forceFill(['status' => ChangeOrder::STATUS_DRAFT, 'decided_by' => null, 'decided_at' => null]);
            }
            $co->save();

            $this->writeLines($co, $lines);
            $this->event($co, $user, 'updated', $wasRejected ? 'Revised after it was rejected' : null);

            return $co->refresh();
        });
    }

    public function submit(ChangeOrder $co, User $user): void
    {
        if (! $co->lines()->exists()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one material or labor line before submitting.']);
        }

        DB::transaction(function () use ($co, $user) {
            $co->forceFill(['status' => ChangeOrder::STATUS_SUBMITTED, 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_note' => null])->save();
            $this->event($co, $user, 'submitted');
        });

        $this->notify($this->managersFor($co, $user), $co, ChangeOrder::STATUS_SUBMITTED);
    }

    /** Takes a submitted change order back to draft, to be changed. */
    public function withdraw(ChangeOrder $co, User $user): void
    {
        DB::transaction(function () use ($co, $user) {
            $co->forceFill(['status' => ChangeOrder::STATUS_DRAFT, 'submitted_at' => null])->save();
            $this->event($co, $user, 'withdrawn');
        });
    }

    public function approve(ChangeOrder $co, User $user, ?string $note): void
    {
        DB::transaction(function () use ($co, $user, $note) {
            $co->forceFill(['status' => ChangeOrder::STATUS_APPROVED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => filled($note) ? trim($note) : null])->save();
            $this->event($co, $user, 'approved', $note);
            // Its amount goes onto the job's billing.
            $this->billing->attach($co->refresh());
        });

        $this->notify($this->author($co, $user), $co, ChangeOrder::STATUS_APPROVED);
    }

    public function reject(ChangeOrder $co, User $user, string $note): void
    {
        DB::transaction(function () use ($co, $user, $note) {
            $co->forceFill(['status' => ChangeOrder::STATUS_REJECTED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => trim($note)])->save();
            $this->event($co, $user, 'rejected', $note);
        });

        $this->notify($this->author($co, $user), $co, ChangeOrder::STATUS_REJECTED);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(User $user, array $data): array
    {
        return [
            'description' => trim($data['description']),
            'reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
            'reason_code' => $data['reason_code'] ?? null,
            'customer_requested' => (bool) ($data['customer_requested'] ?? false),
            // A foreman's is always raised from the field; a manager says which it is.
            'source' => $this->access->isManager($user) ? ($data['source'] ?? ChangeOrder::SOURCE_OFFICE) : ChangeOrder::SOURCE_FIELD,
            'markup_pct' => $data['markup_pct'] ?? 0,
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(ChangeOrder $co, array $lines): void
    {
        $co->lines()->delete();

        foreach (array_values($lines) as $position => $line) {
            $co->lines()->create([
                'kind' => $line['kind'],
                'description' => trim($line['description']),
                'quantity' => $line['quantity'],
                'unit' => filled($line['unit'] ?? null) ? trim($line['unit']) : null,
                'unit_cost' => $line['unit_cost'],
                'total' => round((float) $line['quantity'] * (float) $line['unit_cost'], 2),
                'position' => $position,
            ]);
        }

        $labor = $co->lines()->where('kind', ChangeOrderLine::LABOR);
        $laborCost = round((float) (clone $labor)->sum('total'), 2);
        $materialCost = round((float) $co->lines()->where('kind', ChangeOrderLine::MATERIAL)->sum('total'), 2);
        $cost = round($laborCost + $materialCost, 2);

        $co->forceFill([
            'labor_hours' => round((float) (clone $labor)->sum('quantity'), 2),
            'labor_cost' => $laborCost,
            'material_cost' => $materialCost,
            'cost_total' => $cost,
            'sell_total' => round($cost * (1 + (float) $co->markup_pct / 100), 2),
        ])->save();
    }

    private function event(ChangeOrder $co, User $user, string $type, ?string $note = null): void
    {
        ChangeOrderEvent::create([
            'change_order_id' => $co->id,
            'user_id' => $user->id,
            'type' => $type,
            'note' => filled($note) ? trim($note) : null,
            'sell_total' => $co->sell_total,
        ]);
    }

    /** The company's managers, other than whoever just acted. @return list<User> */
    private function managersFor(ChangeOrder $co, User $actor): array
    {
        $owner = User::query()->find($co->owner_id);

        return User::query()
            ->where(fn ($q) => $q->where('id', $co->owner_id)->when($owner?->company_id, fn ($q, $company) => $q->orWhere('company_id', $company)))
            ->whereKeyNot($actor->id)
            ->get()
            ->filter(fn (User $user) => $this->access->isManager($user))
            ->values()
            ->all();
    }

    /** @return list<User> */
    private function author(ChangeOrder $co, User $actor): array
    {
        $author = $co->author;

        return $author !== null && $author->id !== $actor->id ? [$author] : [];
    }

    /** @param  list<User>  $people */
    private function notify(array $people, ChangeOrder $co, string $status): void
    {
        foreach ($people as $person) {
            $person->notify(new ChangeOrderStatusChanged($co->loadMissing('job'), $status));
        }
    }
}
