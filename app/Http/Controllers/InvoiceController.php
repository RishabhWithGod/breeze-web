<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Policies\InvoicePolicy;
use App\Services\Billing\InvoiceSummaryCalculator;
use App\Services\Clients\ClientDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Invoices list: filtering, the Invoice Summary card, and creating a new
 * invoice. Once one exists, `InvoiceDetailController` owns it.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceSummaryCalculator $summary,
        private readonly ClientDirectory $clients,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Invoice::DISPLAY_STATUSES])],
            'client' => ['nullable', 'string', 'max:160'],
            'job' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', Rule::in(Invoice::SORTS)],
        ]);

        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $job = $filters['job'] ?? null;
        $sort = $filters['sort'] ?? 'date-desc';

        $invoices = Invoice::query()
            ->ownedBy($request->user())
            ->with('job')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->displayStatus($status))
            ->when($client !== 'all', fn ($query) => $query->where('client', $client))
            ->when($job !== null, fn ($query) => $query->where('job_id', $job))
            ->issuedBetween($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->amountBetween($filters['amount_min'] ?? null, $filters['amount_max'] ?? null)
            ->sorted($sort)
            ->paginate(config('billing.per_page'))
            ->withQueryString();

        return Inertia::render('Invoices', [
            'invoices' => InvoiceResource::collection($invoices),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'client' => $client,
                'job' => $job,
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'amount_min' => $filters['amount_min'] ?? '',
                'amount_max' => $filters['amount_max'] ?? '',
                'sort' => $sort,
            ],
            // Drives the Client filter; only clients an invoice has actually been raised for.
            'clients' => Invoice::query()->ownedBy($request->user())->distinct()->orderBy('client')->pluck('client'),
            'jobs' => Job::query()->ownedBy($request->user())->orderBy('name')->get(['id', 'name']),
            'summary' => $this->summary->calculate($request->user()),
            'can' => app(InvoicePolicy::class)->abilities($request->user()),
        ]);
    }

    /**
     * Full-page create form — also the destination of a completed job's
     * "Create Invoice" button (`?job=`), which arrives pre-filled with that
     * job and, when it has one, its own not-yet-invoiced estimate.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $preselectedJobId = null;
        $preselectedEstimateId = null;

        $jobId = $request->integer('job') ?: null;

        if ($jobId !== null) {
            // Scoped to this manager's own jobs — a hand-made `?job=` cannot
            // pre-fill an invoice from someone else's.
            $job = Job::where('user_id', $request->user()->id)->find($jobId);

            // Not completed, or not this manager's job: the button that sends
            // people here never offers either case, so silently falling back
            // to a blank form (rather than erroring) is enough — this only
            // happens from a stale link or a hand-edited URL.
            if ($job !== null && $job->isLocked()) {
                $existing = $job->invoices()->first();

                // Already invoiced — open that invoice rather than starting a
                // second one. Enforced again in `store()`, not just here.
                if ($existing !== null) {
                    return redirect()->route('invoices.show', $existing);
                }

                $preselectedJobId = $job->id;
                $preselectedEstimateId = $job->estimates()
                    ->whereIn('status', ['sent', 'approved'])
                    ->whereDoesntHave('invoices')
                    ->orderByDesc('issued_on')
                    ->value('id');
            }
        }

        return Inertia::render('InvoiceCreate', [
            'nextNumber' => Invoice::nextNumber($request->user()),
            'clients' => $this->clients->options($request->user()),
            'jobs' => Job::query()->ownedBy($request->user())->orderBy('name')->get(['id', 'name', 'client', 'client_id']),
            // Sent/approved estimates not yet converted into an invoice — the
            // real "generate invoice from estimate" starting point.
            'estimates' => Estimate::query()
                ->ownedBy($request->user())
                ->whereIn('status', ['sent', 'approved'])
                ->whereDoesntHave('invoices')
                ->orderByDesc('issued_on')
                ->get(['id', 'number', 'client', 'client_id', 'job_id', 'grand_total']),
            'preselectedJobId' => $preselectedJobId,
            'preselectedEstimateId' => $preselectedEstimateId,
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validated();

        // Scoped as well as validated: the request rule already refuses another
        // manager's estimate, and this refuses to read one even if that rule
        // were ever loosened.
        $estimate = ! empty($data['estimate_id'])
            ? Estimate::where('user_id', $request->user()->id)->find($data['estimate_id'])
            : null;

        // A job is only billed once it is actually done, and only once —
        // both refused here, not only by hiding the button, so a hand-made
        // request naming a job id (real status/ownership notwithstanding)
        // can't raise an invoice against work that isn't finished, or a
        // second one against work already billed.
        if (! empty($data['job_id'])) {
            // Re-checked rather than trusted from the already-validated id:
            // the request rule only confirms ownership, not status.
            $job = Job::where('user_id', $request->user()->id)->find($data['job_id']);
            abort_unless($job !== null, 404);

            if (! $job->isLocked()) {
                return back()->withErrors([
                    'job_id' => 'Only a completed job can be invoiced.',
                ]);
            }

            if ($job->invoices()->exists()) {
                return back()->withErrors([
                    'job_id' => 'This job is already invoiced.',
                ]);
            }
        }

        // `client` is a snapshot of the picked client's name, never typed.
        $data = $this->clients->withClientSnapshot($data, $request->user());

        $invoice = Invoice::create([
            ...$data,
            'invoice_number' => Invoice::nextNumber($request->user()),
            'status' => Invoice::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        // Converting from an estimate copies its real lines rather than
        // asking anyone to retype them — the estimate's own data stays the
        // single source, this is just a starting point the invoice owns from
        // here on.
        if ($estimate !== null) {
            $estimate->loadMissing('items');

            foreach ($estimate->items as $position => $item) {
                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_cost,
                    'total' => $item->total,
                    'position' => $position,
                ]);
            }

            $invoice->recalculateTotals();
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', "{$invoice->invoice_number} was created.");
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return back()->with('warning', "{$invoice->invoice_number} was deleted.");
    }

    /** Undo for the delete above. */
    public function restore(Request $request, int $invoice): RedirectResponse
    {
        $trashed = Invoice::onlyTrashed()->ownedBy($request->user())->findOrFail($invoice);
        $trashed->restore();

        return back()->with('success', "{$trashed->invoice_number} was restored.");
    }
}
