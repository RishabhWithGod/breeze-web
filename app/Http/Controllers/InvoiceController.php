<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Policies\InvoicePolicy;
use App\Services\Billing\EstimateInvoiceSync;
use App\Services\Billing\InvoiceSummaryCalculator;
use App\Services\ChangeOrders\ChangeOrderBilling;
use App\Services\Clients\ClientDirectory;
use App\Services\JobCosting\JobCostSummary;
use App\Support\Ownership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Invoices list: filtering, the Invoice Summary card, and creating a new
 * invoice. Once one exists, `InvoiceDetailController` owns it.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceSummaryCalculator $summary,
        private readonly ClientDirectory $clients,
        private readonly EstimateInvoiceSync $estimateSync,
        private readonly JobCostSummary $costSummary,
    ) {}

    /** The list's filters, validated — shared by the list and its export so they never disagree. */
    private function listFilters(Request $request): array
    {
        return $request->validate([
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
    }

    /** @param  array<string, mixed>  $filters */
    private function filteredQuery(Request $request, array $filters)
    {
        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $job = $filters['job'] ?? null;

        return Invoice::query()
            ->ownedBy($request->user())
            ->with('job')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->displayStatus($status))
            ->when($client !== 'all', fn ($query) => $query->where('client', $client))
            ->when($job !== null, fn ($query) => $query->where('job_id', $job))
            ->issuedBetween($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->amountBetween($filters['amount_min'] ?? null, $filters['amount_max'] ?? null)
            ->sorted($filters['sort'] ?? 'date-desc');
    }

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);

        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $job = $filters['job'] ?? null;
        $sort = $filters['sort'] ?? 'date-desc';

        $invoices = $this->filteredQuery($request, $filters)
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
            $job = Job::whereIn('user_id', Ownership::userIds($request->user()))->find($jobId);

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

        $canViewCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);

        return Inertia::render('InvoiceCreate', [
            'nextNumber' => Invoice::nextNumber($request->user()),
            'clients' => $this->clients->options($request->user()),
            'jobs' => Job::query()->ownedBy($request->user())->with('project:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'client', 'client_id', 'status', 'project_id'])
                ->map(fn (Job $job) => [
                    'id' => $job->id,
                    'name' => $job->name,
                    'client' => $job->client,
                    'client_id' => $job->client_id,
                    'project_id' => $job->project_id,
                    'project' => $job->project?->name,
                    // Only a finished job, and only once, can be invoiced.
                    'completed' => $job->isLocked(),
                    // What the job really cost — for whoever may see costs, on the jobs
                    // that can be invoiced at all (the rest never reach this screen).
                    'actual_cost' => $canViewCosts && $job->isLocked()
                        ? $this->costSummary->for($job)['actualTotalCost'] ?? null
                        : null,
                ]),
            // Every estimate and addendum not yet invoiced — with its lines, so the
            // screen can show (and adjust) what will be billed, and with enough about
            // where it sits (its kind, its parent, the job that owns it) that picking a
            // job can offer just that job's own.
            'estimates' => Estimate::query()
                ->ownedBy($request->user())
                ->whereDoesntHave('invoices')
                ->with(['items', 'parentEstimate:id,number,job_id'])
                ->orderBy('number')
                ->get(['id', 'number', 'client', 'client_id', 'job_id', 'project_id', 'grand_total', 'kind', 'status', 'parent_estimate_id', 'addendum_number'])
                ->map(fn (Estimate $estimate) => [
                    'id' => $estimate->id,
                    'number' => $estimate->number,
                    'client' => $estimate->client,
                    'client_id' => $estimate->client_id,
                    'job_id' => $estimate->job_id,
                    // An addendum has no job of its own: it belongs to its original's.
                    'owner_job_id' => $estimate->job_id ?? $estimate->parentEstimate?->job_id,
                    'project_id' => $estimate->project_id,
                    'grand_total' => (float) $estimate->grand_total,
                    'kind' => $estimate->kind,
                    'status' => $estimate->status,
                    'addendum_number' => $estimate->addendum_number,
                    'parent_number' => $estimate->parentEstimate?->number,
                    'items' => $estimate->items->map(fn ($item) => [
                        'description' => $item->description,
                        'source_category' => $this->estimateSync->categoryFor($item->category),
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_cost,
                    ])->values()->all(),
                ]),
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
            ? Estimate::whereIn('user_id', Ownership::userIds($request->user()))->find($data['estimate_id'])
            : null;

        // A job is only billed once it is actually done, and only once —
        // both refused here, not only by hiding the button, so a hand-made
        // request naming a job id (real status/ownership notwithstanding)
        // can't raise an invoice against work that isn't finished, or a
        // second one against work already billed.
        if (! empty($data['job_id'])) {
            // Re-checked rather than trusted from the already-validated id:
            // the request rule only confirms ownership, not status.
            $job = Job::whereIn('user_id', Ownership::userIds($request->user()))->find($data['job_id']);
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

        // An invoice raised on a project is for that project's client and bills that
        // project's own estimate — the screen fixes both, and this refuses anything
        // else, so a hand-made request cannot mix one project's client or estimate
        // into another's invoice.
        if (! empty($data['job_id'])) {
            $job ??= Job::whereIn('user_id', Ownership::userIds($request->user()))->find($data['job_id']);

            if ($job?->client_id !== null && (int) $job->client_id !== (int) $data['client_id']) {
                return back()->withErrors(['client_id' => "The client comes from the project — this one is for {$job->client}."]);
            }

            if ($estimate !== null && ! $this->estimateBelongsTo($estimate, $job)) {
                return back()->withErrors(['estimate_id' => 'That estimate is not for this project.']);
            }
        }

        // `client` is a snapshot of the picked client's name, never typed.
        $data = $this->clients->withClientSnapshot($data, $request->user());
        $lines = $data['items'] ?? [];
        unset($data['items'], $data['issue']);

        $invoice = Invoice::create([
            ...$data,
            'invoice_number' => Invoice::nextNumber($request->user()),
            'status' => Invoice::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        if (is_array($request->input('items'))) {
            // The lines as the screen had them — an estimate's, edited, plus any
            // typed — so nothing is copied from the estimate over the top.
            foreach (array_values($lines) as $position => $line) {
                $invoice->items()->create([
                    'description' => $line['description'],
                    'source_category' => $line['source_category'] ?? null,
                    'source' => $line['source'] ?? InvoiceItem::SOURCE_MANUAL,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'position' => $position,
                ]);
            }

            $invoice->recalculateTotals();
        } elseif ($estimate !== null) {
            // Converting from an estimate copies its real lines rather than
            // asking anyone to retype them — a one-time copy, after which each
            // line is the invoice's own (see `EstimateInvoiceSync`).
            $this->estimateSync->sync($invoice);
        }

        // What has been approved as added work on this job is billed with it.
        app(ChangeOrderBilling::class)->attachApprovedTo($invoice);

        // "Issue Invoice": sent straight away, by the same rules as Send.
        if ($request->boolean('issue')) {
            app(InvoiceDetailController::class)->send($request, $invoice);

            return redirect()
                ->route('invoices.show', $invoice)
                ->with('success', "{$invoice->invoice_number} was issued to {$invoice->client}.");
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', "{$invoice->invoice_number} was created.");
    }

    /** An estimate is for a job when it names it — or, for an addendum, its original does — else when it shares the job's project and nobody's job. */
    private function estimateBelongsTo(Estimate $estimate, ?Job $job): bool
    {
        if ($job === null) {
            return true;
        }

        $owner = $estimate->job_id ?? $estimate->parentEstimate?->job_id;

        return $owner !== null
            ? (int) $owner === (int) $job->id
            : $estimate->project_id !== null && (int) $estimate->project_id === (int) $job->project_id;
    }

    /** The invoices the list is showing under its current filters, as a spreadsheet. */
    public function export(Request $request): StreamedResponse
    {
        $invoices = $this->filteredQuery($request, $this->listFilters($request))->get();

        return response()->streamDownload(function () use ($invoices) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice #', 'Client', 'Project', 'Invoice Date', 'Due Date', 'Total', 'Paid', 'Outstanding', 'Status']);

            foreach ($invoices as $invoice) {
                fputcsv($out, [
                    $invoice->invoice_number,
                    $invoice->client,
                    $invoice->job?->name ?? '',
                    $invoice->invoice_date->format('m/d/Y'),
                    $invoice->due_date?->format('m/d/Y') ?? '',
                    number_format((float) $invoice->total, 2, '.', ''),
                    number_format((float) $invoice->paid_amount, 2, '.', ''),
                    number_format($invoice->outstanding(), 2, '.', ''),
                    $invoice->displayStatus(),
                ]);
            }

            fclose($out);
        }, 'invoices-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
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
