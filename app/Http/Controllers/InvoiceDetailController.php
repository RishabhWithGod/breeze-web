<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceItemResource;
use App\Models\FeedItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Models\TimeEntry;
use App\Notifications\InvoiceStatusChanged;
use App\Policies\InvoicePolicy;
use App\Policies\JobCostingPolicy;
use App\Services\Activity\FeedItemRecorder;
use App\Services\Clients\ClientDirectory;
use App\Services\Export\InvoicePdfWriter;
use App\Services\JobCosting\JobCostSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The invoice screen: line items, the totals derived from them, and the
 * send/mark-paid workflow.
 *
 * Totals are never stored independently of the lines — every item write
 * recomputes them, the same convention `EstimateDetailController` uses for
 * estimates. Status only ever moves forward through `send()`/`markPaid()`;
 * nothing here lets it be typed in freely, so "who marked this paid and when"
 * is always a real, deliberate action, not an edit that happened to touch it.
 */
class InvoiceDetailController extends Controller
{
    public function __construct(
        private readonly FeedItemRecorder $activity,
        private readonly ClientDirectory $clients,
        private readonly JobCostSummary $costSummary,
    ) {}

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load(['job', 'estimate', 'items', 'creator']);
        $abilities = app(InvoicePolicy::class);

        $jobCostSummary = null;
        $journeymanHours = [];

        if ($invoice->job !== null) {
            $canViewJobCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);
            $summary = $this->costSummary->for($invoice->job);
            $jobCostSummary = $canViewJobCosts ? $summary : JobCostSummary::redact($summary);
            $journeymanHours = $canViewJobCosts
                ? $this->costSummary->journeymanHours($invoice->job)->values()->all()
                : [];
        }

        return Inertia::render('InvoiceShow', [
            'invoice' => $this->present($invoice),
            'items' => InvoiceItemResource::collection($invoice->items)->resolve($request),
            // The estimate-vs-actual breakdown behind this invoice's job, so a
            // manager can see what was billed against what was estimated and
            // what actually happened — without leaving the invoice screen.
            'jobCostSummary' => $jobCostSummary,
            // Every person with time on the job and their total hours — no
            // approval wait, and directly editable here; `actualLaborHours`
            // above is this same breakdown summed, so the two can never disagree.
            'journeymanHours' => $journeymanHours,
            'can' => [
                'update' => $abilities->update($request->user(), $invoice),
                'delete' => $abilities->delete($request->user(), $invoice),
                'send' => $abilities->send($request->user(), $invoice),
                'markPaid' => $abilities->markPaid($request->user(), $invoice),
                'manageJobCosts' => $invoice->job !== null && app(JobCostingPolicy::class)->manage($request->user()),
            ],
            'stripeConnected' => PaymentProcessor::query()
                ->where('key', PaymentProcessor::STRIPE)
                ->whereIn('status', [PaymentProcessor::STATUS_ACTIVE, PaymentProcessor::STATUS_LIMITED])
                ->exists(),
        ]);
    }

    /** Full-page edit form for the invoice's header — status is never editable here. */
    public function edit(Request $request, Invoice $invoice): Response
    {
        $this->authorize('update', $invoice);

        $invoice->load(['job', 'estimate']);

        return Inertia::render('InvoiceEdit', [
            'invoice' => $this->present($invoice),
            'clients' => $this->clients->options($request->user()),
            'jobs' => Job::query()->ownedBy($request->user())->orderBy('name')->get(['id', 'name', 'client']),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        // `client` is a snapshot of the picked client's name, never typed.
        $invoice->update($this->clients->withClientSnapshot($request->validated(), $request->user()));
        $invoice->recalculateTotals();

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', "{$invoice->invoice_number} was updated.");
    }

    public function storeItem(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $item = $invoice->items()->create([
            ...$validated,
            'total' => round($validated['quantity'] * $validated['unit_price'], 2),
            'position' => (int) $invoice->items()->max('position') + 1,
        ]);

        $invoice->recalculateTotals();

        return back()->with('success', "\"{$item->description}\" added.");
    }

    public function updateItem(Request $request, Invoice $invoice, InvoiceItem $item): RedirectResponse
    {
        $this->authorize('update', $invoice);
        abort_unless($item->invoice_id === $invoice->id, 404);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $item->update([
            ...$validated,
            'total' => round($validated['quantity'] * $validated['unit_price'], 2),
        ]);
        $invoice->recalculateTotals();

        return back()->with('success', "\"{$item->description}\" updated.");
    }

    public function destroyItem(Invoice $invoice, InvoiceItem $item): RedirectResponse
    {
        $this->authorize('update', $invoice);
        abort_unless($item->invoice_id === $invoice->id, 404);

        $description = $item->description;
        $item->delete();
        $invoice->recalculateTotals();

        return back()->with('warning', "\"{$description}\" removed.");
    }

    /** Draft → sent. Requires at least one line — nothing goes to a client empty. */
    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('send', $invoice);

        if ($invoice->items()->doesntExist()) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one line item before sending this invoice.',
            ]);
        }

        $invoice->update(['status' => Invoice::STATUS_SENT, 'sent_at' => now()]);
        $this->notifyCreator($request, $invoice, InvoiceStatusChanged::SENT);
        $this->activity->record($request->user(), FeedItem::DASHBOARD_ACTIVITY, "Invoice {$invoice->invoice_number} sent to {$invoice->client}", 'file-text', 'lilac');

        return back()->with('success', "{$invoice->invoice_number} was sent.");
    }

    /** Sent → paid. The entire balance is recorded as collected. */
    public function markPaid(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('markPaid', $invoice);

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => $invoice->total,
            'paid_at' => now(),
        ]);

        // No processor is attached — this is a manually-recorded payment
        // (a check, a wire, cash), not one a connected processor handled.
        // A processor-initiated payment is only ever recorded from that
        // processor's own webhook confirming it, never from this button.
        PaymentTransaction::create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'status' => PaymentTransaction::STATUS_COMPLETED,
            'client' => $invoice->client,
            'description' => "Invoice {$invoice->invoice_number} marked paid",
            'occurred_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);

        $this->notifyCreator($request, $invoice, InvoiceStatusChanged::PAID);
        $this->activity->record($request->user(), FeedItem::DASHBOARD_ACTIVITY, "Invoice {$invoice->invoice_number} marked paid", 'file-text', 'butter');

        return back()->with('success', "{$invoice->invoice_number} was marked paid.");
    }

    /** The person who raised the invoice is the one who cares that it moved — skipped when they did it themselves. */
    private function notifyCreator(Request $request, Invoice $invoice, string $status): void
    {
        $creator = $invoice->creator;

        if ($creator && $creator->id !== $request->user()->id) {
            $creator->notify(new InvoiceStatusChanged($invoice, $status));
        }
    }

    /** Client-ready PDF. */
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
    private function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoiceNumber' => $invoice->invoice_number,
            'client' => $invoice->client,
            'projectId' => $invoice->project_id,
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
        ];
    }
}
