<?php

namespace App\Http\Controllers;

use App\Models\BreezeBucksTransaction;
use App\Models\RewardCatalogItem;
use App\Models\RewardRule;
use App\Models\User;
use App\Notifications\BreezeBucksAwarded;
use App\Policies\BreezeBucksPolicy;
use App\Services\BreezeBucks\BreezeBucksLedger;
use App\Services\BreezeBucks\RewardProgressCalculator;
use App\Services\BreezeBucks\RewardRedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Breeze Bucks: the real, ledger-derived balance, the reward catalog,
 * redemption, and (for managers/admins) awarding bonuses and managing the
 * catalog. Every number here comes from `breeze_bucks_transactions` — there
 * is no stored balance column anywhere that could drift from it.
 */
class BreezeBucksController extends Controller
{
    public function __construct(
        private readonly BreezeBucksLedger $ledger,
        private readonly RewardProgressCalculator $progress,
        private readonly RewardRedemptionService $redemptions,
    ) {}

    public function index(Request $request): Response
    {
        RewardRule::ensureDefaults();

        $user = $request->user();
        $policy = app(BreezeBucksPolicy::class);
        $balance = $this->ledger->balanceFor($user);
        $next = $this->progress->nextRewardFor($balance);

        return Inertia::render('BreezeBucks', [
            'balance' => $balance,
            'lifetimeEarned' => $this->ledger->lifetimeEarnedFor($user),
            'lifetimeRedeemed' => $this->ledger->lifetimeRedeemedFor($user),
            'nextReward' => $next ? [
                'name' => $next['reward']->name,
                'pointsRequired' => $next['reward']->points_required,
                'remaining' => $next['remaining'],
                'percent' => round($next['percent'], 1),
            ] : null,
            'rewardsAvailable' => RewardCatalogItem::where('is_active', true)->exists(),
            'can' => ['award' => $policy->award($user), 'manageCatalog' => $policy->manageCatalog($user)],
        ]);
    }

    /** The real Rewards Catalog screen — its own page, not a popup. */
    public function rewards(Request $request): Response
    {
        $user = $request->user();
        $policy = app(BreezeBucksPolicy::class);
        $canManageCatalog = $policy->manageCatalog($user);

        return Inertia::render('BreezeBucksRewards', [
            'balance' => $this->ledger->balanceFor($user),
            'rewards' => RewardCatalogItem::query()
                ->when(! $canManageCatalog, fn ($q) => $q->where('is_active', true))
                ->orderBy('points_required')
                ->get()
                ->map(fn (RewardCatalogItem $reward) => $this->presentReward($reward)),
            'can' => ['manageCatalog' => $canManageCatalog],
        ]);
    }

    /** The real transaction history screen — its own page, not a popup. */
    public function history(Request $request): Response
    {
        $user = $request->user();

        $filterType = $request->string('type')->toString();
        $validTypes = array_keys(BreezeBucksTransaction::TYPE_LABELS);

        $history = BreezeBucksTransaction::where('user_id', $user->id)
            ->when(in_array($filterType, $validTypes, true), fn ($q) => $q->where('type', $filterType))
            ->latest()
            ->latest('id')
            ->paginate(config('breeze_bucks.history_per_page'))
            ->withQueryString();

        return Inertia::render('BreezeBucksHistory', [
            'history' => [
                'data' => $history->through(fn (BreezeBucksTransaction $transaction) => $this->presentTransaction($transaction))->values(),
                'meta' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'total' => $history->total(),
                ],
            ],
            'filters' => ['type' => in_array($filterType, $validTypes, true) ? $filterType : 'all'],
        ]);
    }

    /** The manager-only Award Bonus screen — its own page, not a popup. */
    public function awardForm(Request $request): Response
    {
        abort_unless(app(BreezeBucksPolicy::class)->award($request->user()), 403);

        return Inertia::render('BreezeBucksAward', [
            'teamMembers' => User::where('id', '!=', $request->user()->id)->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function redeem(Request $request, RewardCatalogItem $reward): RedirectResponse
    {
        $redemption = $this->redemptions->redeem($request->user(), $reward);

        return back()->with('success', "Redeemed \"{$redemption->reward->name}\" for {$redemption->points_spent} BB.");
    }

    public function award(Request $request): RedirectResponse
    {
        abort_unless(app(BreezeBucksPolicy::class)->award($request->user()), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $target = User::findOrFail($data['user_id']);

        $transaction = $this->ledger->record(
            $target,
            BreezeBucksTransaction::TYPE_BONUS,
            $data['amount'],
            $data['reason'],
            'manual_award',
            null,
            $request->user()->id,
        );

        $target->notify(new BreezeBucksAwarded($transaction));

        return back()->with('success', "Awarded {$data['amount']} BB to {$target->name}.");
    }

    public function adjust(Request $request): RedirectResponse
    {
        abort_unless(app(BreezeBucksPolicy::class)->manageCatalog($request->user()), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $target = User::findOrFail($data['user_id']);

        $transaction = $this->ledger->record(
            $target,
            BreezeBucksTransaction::TYPE_ADJUSTMENT,
            $data['amount'],
            $data['reason'],
            'manual_adjustment',
            null,
            $request->user()->id,
        );

        $target->notify(new BreezeBucksAwarded($transaction));

        return back()->with('success', 'Adjustment recorded.');
    }

    public function storeReward(Request $request): RedirectResponse
    {
        abort_unless(app(BreezeBucksPolicy::class)->manageCatalog($request->user()), 403);

        $data = $this->validateReward($request);
        RewardCatalogItem::create($data);

        return back()->with('success', "\"{$data['name']}\" added to the reward catalog.");
    }

    public function updateReward(Request $request, RewardCatalogItem $reward): RedirectResponse
    {
        abort_unless(app(BreezeBucksPolicy::class)->manageCatalog($request->user()), 403);

        $data = $this->validateReward($request);
        $reward->update($data);

        return back()->with('success', "\"{$reward->name}\" updated.");
    }

    public function destroyReward(Request $request, RewardCatalogItem $reward): RedirectResponse
    {
        abort_unless(app(BreezeBucksPolicy::class)->manageCatalog($request->user()), 403);

        // Deactivated, never deleted — past redemptions still reference this
        // row, and deleting it would break their history.
        $reward->update(['is_active' => false]);

        return back()->with('warning', "\"{$reward->name}\" deactivated.");
    }

    /** @return array<string, mixed> */
    private function validateReward(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'points_required' => ['required', 'integer', 'min:1', 'max:1000000'],
            'icon' => ['nullable', 'string', 'max:60'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function presentReward(RewardCatalogItem $reward): array
    {
        return [
            'id' => $reward->id,
            'name' => $reward->name,
            'description' => $reward->description,
            'pointsRequired' => $reward->points_required,
            'icon' => $reward->icon,
            'stock' => $reward->stock,
            'isActive' => $reward->is_active,
            'isRedeemable' => $reward->isRedeemable(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentTransaction(BreezeBucksTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'date' => $transaction->created_at->toISOString(),
            'type' => $transaction->type,
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'balanceAfter' => $transaction->balance_after,
            'sourceType' => $transaction->source_type,
            'status' => 'completed',
        ];
    }
}
