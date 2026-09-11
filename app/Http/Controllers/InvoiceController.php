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

    /** Full-page create form. */
    public function create(Request $request): Response
    {
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
