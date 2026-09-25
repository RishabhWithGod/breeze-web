<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceItemResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Policies\InvoicePolicy;
use App\Services\Billing\EstimateInvoiceSync;
use App\Services\Billing\InvoiceSummaryCalculator;
use App\Services\Clients\ClientDirectory;
use App\Services\Export\InvoicePdfWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices, as the mobile app sees them — the exact same scope, filters,
 * calculations and status workflow as web's `InvoiceController` +
 * `InvoiceDetailController` combined into one controller (mobile has no
 * separate "edit form" page to justify the split). Every action calls the
 * same model methods/services web's own routes do
 * (`Invoice::recalculateTotals()`, `EstimateInvoiceSync`, `ClientDirectory`)
 * — no second implementation of any business rule.
 *
 * Trimmed for mobile, deliberately: no activity-feed/notification
 * side-effects on send/markPaid (mirrors this API's own existing precedent
 * — `TimeEntryController`'s approve/reject don't send them either), and no
 * job-cost/journeyman-hours breakdown on the detail payload (that's
 * Job Costing content embedded on web's invoice page, not Invoice data
 * itself).
 */
class InvoiceController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly InvoiceSummaryCalculator $summary,
        private readonly ClientDirectory $clients,
        private readonly EstimateInvoiceSync $estimateSync,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Invoice::DISPLAY_STATUSES])],
            'client' => ['nullable', 'string', 'max:160'],
            'job_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', Rule::in(Invoice::SORTS)],
        ]);

        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $jobId = $filters['job_id'] ?? null;
        $sort = $filters['sort'] ?? 'date-desc';

        $invoices = Invoice::query()
            ->ownedBy($user)
            ->with('job')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->displayStatus($status))
            ->when($client !== 'all', fn ($query) => $query->where('client', $client))
            ->when($jobId !== null, fn ($query) => $query->where('job_id', $jobId))
            ->issuedBetween($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->amountBetween($filters['amount_min'] ?? null, $filters['amount_max'] ?? null)
            ->sorted($sort)
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'invoices' => InvoiceResource::collection($invoices->getCollection())->resolve($request),
            'meta' => [
                'currentPage' => $invoices->currentPage(),
                'lastPage' => $invoices->lastPage(),
                'total' => $invoices->total(),
            ],
            // Drives the Client/Job filter pickers — same source as web's own.
            'clients' => Invoice::query()->ownedBy($user)->whereNotNull('client')->distinct()->orderBy('client')->pluck('client'),
            'jobs' => Job::query()->ownedBy($user)->orderBy('name')->get(['id', 'name']),
            'summary' => $this->summary->calculate($user),
            'can' => app(InvoicePolicy::class)->abilities($user),
        ]);
    }

    /**
     * Everything the Create form needs in one call: the reserved next
     * number, the client/job/estimate pickers. Mirrors web's `create()`
     * exactly — jobs are NOT pre-filtered to only completed/uninvoiced ones
     * (web's own dropdown shows every job too); eligibility is only
     * enforced at `store()`, surfacing the same field error back.
     */
    public function createOptions(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'nextNumber' => Invoice::nextNumber($user),
            'clients' => $this->clients->options($user)->map(fn (array $c) => [
                'id' => $c['id'],
                'name' => $c['name'],
            ])->all(),
            'jobs' => Job::query()->ownedBy($user)->orderBy('name')->get(['id', 'name', 'client', 'client_id']),
            // `grand_total` is a `decimal:2` cast — serializes as a string
            // by default, so it's mapped explicitly to a real JSON number
            // here (same reason `detail()` below casts every money field).
            'estimates' => Estimate::query()
                ->ownedBy($user)
                ->whereIn('status', ['sent', 'approved'])
                ->whereDoesntHave('invoices')
                ->orderByDesc('issued_on')
                ->get(['id', 'number', 'client', 'client_id', 'job_id', 'grand_total'])
                ->map(fn (Estimate $estimate) => [
                    'id' => $estimate->id,
                    'number' => $estimate->number,
                    'client' => $estimate->client,
                    'client_id' => $estimate->client_id,
                    'job_id' => $estimate->job_id,
                    'grand_total' => (float) $estimate->grand_total,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);
        $user = $request->user();

        $data = $this->validated($request);

        $estimate = ! empty($data['estimate_id'])
            ? Estimate::where('user_id', $user->id)->find($data['estimate_id'])
            : null;

        if (! empty($data['job_id'])) {
            $job = Job::where('user_id', $user->id)->find($data['job_id']);
            abort_unless($job !== null, 404);

            if (! $job->isLocked()) {
                throw ValidationException::withMessages(['job_id' => 'Only a completed job can be invoiced.']);
            }

            if ($job->invoices()->exists()) {
                throw ValidationException::withMessages(['job_id' => 'This job is already invoiced.']);
            }
        }

        $data = $this->clients->withClientSnapshot($data, $user);

        $invoice = Invoice::create([
            ...$data,
            'invoice_number' => Invoice::nextNumber($user),
            'status' => Invoice::STATUS_DRAFT,
            'created_by' => $user->id,
        ]);

        if ($estimate !== null) {
            $this->estimateSync->sync($invoice);
        }

        return $this->created($this->detail($invoice->fresh(), $request), "{$invoice->invoice_number} was created.");
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return $this->ok($this->detail($invoice, $request));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);
        $user = $request->user();

        $data = $this->validated($request);
        $invoice->update($this->clients->withClientSnapshot($data, $user));
        $invoice->recalculateTotals();

        return $this->ok($this->detail($invoice->fresh(), $request), "{$invoice->invoice_number} was updated.");
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return $this->ok(null, "{$invoice->invoice_number} was deleted.");
    }

    /** Undo for the delete above — matches web's plain-int route param (a trashed model never route-binds). */
    public function restore(Request $request, int $invoice): JsonResponse
    {
        $trashed = Invoice::onlyTrashed()->ownedBy($request->user())->findOrFail($invoice);
        $trashed->restore();

        return $this->ok($this->detail($trashed->fresh(), $request), "{$trashed->invoice_number} was restored.");
    }

    public function storeItem(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $validated = $this->validatedItem($request);

        $item = $invoice->items()->create([
            ...$validated,
            'total' => round($validated['quantity'] * $validated['unit_price'], 2),
            'position' => (int) $invoice->items()->max('position') + 1,
        ]);

        $invoice->recalculateTotals();

        return $this->created($this->detail($invoice->fresh(), $request), "\"{$item->description}\" added.");
    }

    public function updateItem(Request $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        $this->authorize('update', $invoice);
        abort_unless($item->invoice_id === $invoice->id, 404);

        $validated = $this->validatedItem($request);

        $item->update([
            ...$validated,
            'total' => round($validated['quantity'] * $validated['unit_price'], 2),
        ]);
        $invoice->recalculateTotals();

        return $this->ok($this->detail($invoice->fresh(), $request), "\"{$item->description}\" updated.");
    }

    public function destroyItem(Request $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        $this->authorize('update', $invoice);
        abort_unless($item->invoice_id === $invoice->id, 404);

        $description = $item->description;
        $item->delete();
        $invoice->recalculateTotals();

        return $this->ok($this->detail($invoice->fresh(), $request), "\"{$description}\" removed.");
    }

    /** Draft → sent. Requires at least one line — nothing goes to a client empty. */
    public function send(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('send', $invoice);

        if ($invoice->items()->doesntExist()) {
            throw ValidationException::withMessages(['items' => 'Add at least one line item before sending this invoice.']);
        }

        $invoice->update(['status' => Invoice::STATUS_SENT, 'sent_at' => now()]);

        return $this->ok($this->detail($invoice->fresh(), $request), "{$invoice->invoice_number} was sent.");
    }

    /** Sent → paid. The entire balance is recorded as collected — the manual, honest-word path. */
    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('markPaid', $invoice);

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => $invoice->total,
            'paid_at' => now(),
        ]);

        PaymentTransaction::create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'status' => PaymentTransaction::STATUS_COMPLETED,
            'client' => $invoice->client,
            'description' => "Invoice {$invoice->invoice_number} marked paid",
            'occurred_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);

        return $this->ok($this->detail($invoice->fresh(), $request), "{$invoice->invoice_number} was marked paid.");
    }

    /** Client-ready PDF — same writer web's own download uses, streamed as raw bytes. */
    public function pdf(Invoice $invoice, InvoicePdfWriter $writer): StreamedResponse
    {
        $this->authorize('view', $invoice);

        $invoice->load('items', 'job');
        $contents = $writer->render($invoice);

        return ResponseFactory::streamDownload(
            fn () => print $contents,
            "invoice-{$invoice->invoice_number}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $userId = $request->user()->id;

        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('user_id', $userId)],
            'job_id' => ['nullable', 'integer', Rule::exists('work_jobs', 'id')->where('user_id', $userId)],
            'estimate_id' => ['nullable', 'integer', Rule::exists('estimates', 'id')->where('user_id', $userId)],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'client_id.required' => 'Client is required',
            'client_id.exists' => 'Pick a client from the list',
            'invoice_date.required' => 'Invoice date is required',
            'due_date.after_or_equal' => 'Due date cannot be before the invoice date',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(Invoice $invoice, Request $request): array
    {
        $invoice->loadMissing(['job', 'estimate', 'items', 'creator']);
        $abilities = app(InvoicePolicy::class);

        return [
            'id' => $invoice->id,
            'invoiceNumber' => $invoice->invoice_number,
            'client' => $invoice->client,
            'clientId' => $invoice->client_id,
            'jobId' => $invoice->job_id,
            'jobName' => $invoice->job?->name,
            'estimateId' => $invoice->estimate_id,
            'estimateNumber' => $invoice->estimate?->number,
            'invoiceDate' => $invoice->invoice_date->toDateString(),
            'dueDate' => $invoice->due_date?->toDateString(),
            'subtotal' => (float) $invoice->subtotal,
            'taxPct' => (float) $invoice->tax_pct,
            'taxTotal' => (float) $invoice->tax_total,
            'total' => (float) $invoice->total,
            'paidAmount' => (float) $invoice->paid_amount,
            'outstanding' => $invoice->outstanding(),
            'status' => $invoice->displayStatus(),
            'isEditable' => $invoice->isEditable(),
            'notes' => $invoice->notes,
            'sentAt' => $invoice->sent_at?->toISOString(),
            'paidAt' => $invoice->paid_at?->toISOString(),
            'createdBy' => $invoice->creator?->name,
            'createdAt' => $invoice->created_at->toISOString(),
            'items' => InvoiceItemResource::collection($invoice->items)->resolve($request),
            'can' => [
                'update' => $abilities->update($request->user(), $invoice),
                'delete' => $abilities->delete($request->user(), $invoice),
                'send' => $abilities->send($request->user(), $invoice),
                'markPaid' => $abilities->markPaid($request->user(), $invoice),
            ],
            'stripeConnected' => PaymentProcessor::query()
                ->where('key', PaymentProcessor::STRIPE)
                ->whereIn('status', [PaymentProcessor::STATUS_ACTIVE, PaymentProcessor::STATUS_LIMITED])
                ->exists(),
        ];
    }
}
