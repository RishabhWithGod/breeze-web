import { useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import type { LucideIcon } from 'lucide-react'
import {
  ArrowLeft,
  Building2,
  Calculator,
  CalendarDays,
  Check,
  ChevronDown,
  CreditCard,
  Download,
  FileText,
  Hourglass,
  MapPin,
  MessageSquare,
  Pencil,
  Receipt,
  Send,
  Trash2,
  UserRound,
  Wallet,
  X,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  CollapsibleCard,
  ConfirmDialog,
  StatusChip,
  TextInput,
  buttonStyles,
} from '@/components/common'
import { InvoiceItemsTable, MetricCard } from '@/components/billing'
import { appLayout, PageTransition } from '@/components/layout'
import { MOTION, ROUTES, routeTo } from '@/constants'
import { LiveChangeBanner, ScopeHistory } from '@/components/jobScope'
import { useDisclosure, useJobScopeSync } from '@/hooks'
import type {
  InvoiceActionAbilities,
  InvoiceDetail,
  InvoiceItemRow,
  JobCostRow,
  ScopePayload,
  JourneymanHoursRow,
  SharedPageProps,
} from '@/types'
import {
  INVOICE_STATUS_LABEL,
  INVOICE_STATUS_TONE,
  cn,
  formatCalendarDate,
  formatCurrency,
  formatDate,
  formatHours,
} from '@/utils'

interface EstimateListRow {
  readonly id: number
  readonly category: string
  readonly description: string
  readonly unit: string
  readonly quantity: number
  readonly unitCost: number
  readonly total: number
}

export interface InvoiceShowProps {
  invoice: InvoiceDetail
  items: readonly InvoiceItemRow[]
  /** Every line of the estimate this invoice was billed against — empty when there is none or it is not viewable. */
  estimateItems: readonly EstimateListRow[]
  /** The job this invoice was raised for's estimate-vs-actual breakdown —
   *  `null` when the invoice has no job, or the field it prices is redacted
   *  for a role without cost visibility. */
  jobCostSummary: JobCostRow | null
  /** Every person with time on that same job and their total hours — the
   *  source `jobCostSummary.actualLaborHours` below is summed from. */
  journeymanHours: readonly JourneymanHoursRow[]
  /** The job's scope version and its newest changes; null when the invoice has no job. */
  scope: ScopePayload | null
  can: InvoiceActionAbilities
  /** Whether a connected Stripe processor exists to actually take an online payment against. */
  stripeConnected: boolean
}

/**
 * Invoice detail — the header, its line items, and the send/mark-paid
 * workflow. Status only ever changes through the dedicated actions below;
 * nothing here lets it be typed in freely.
 */
export default function InvoiceShow({
  invoice,
  items,
  estimateItems,
  jobCostSummary,
  journeymanHours,
  scope,
  can,
  stripeConnected,
}: InvoiceShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()
  const costDetails = useDisclosure()
  // Open to begin with — the lines are what the invoice is — and folds away on request.
  const [linesOpen, setLinesOpen] = useState(true)
  const [estimateOpen, setEstimateOpen] = useState(false)

  // The bill shows the job's latest scope: any estimate, change-order or cost change refreshes it here.
  const { latest } = useJobScopeSync(invoice.jobId)

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  const send = () => router.post(routeTo.invoiceSend(invoice.id), {}, { preserveScroll: true })
  const markPaid = () => router.post(routeTo.invoiceMarkPaid(invoice.id), {}, { preserveScroll: true })
  // The server responds with `Inertia::location(...)` for this one — Stripe's
  // own checkout page isn't a route in this app, so Inertia's client turns
  // that into a real `window.location` navigation instead of trying to fetch
  // it as another page here.
  const payOnline = () => router.post(routeTo.invoicePay(invoice.id))

  const confirmDelete = () => {
    router.delete(routeTo.invoice(invoice.id), {
      onSuccess: () => router.visit(ROUTES.invoices),
    })
    deleteDialog.close()
  }

  const status = INVOICE_STATUS_TONE[invoice.status]
  const shareOfEstimate =
    invoice.estimateTotal !== null && invoice.estimateTotal > 0
      ? `${Math.round((invoice.total / invoice.estimateTotal) * 100)}% of the estimate`
      : undefined

  return (
    <PageTransition>
      <LiveChangeBanner latest={latest} />
      <Head title={`Invoice ${invoice.invoiceNumber}`} />

      {/* ==================================================== Header ========= */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <Link
            href={ROUTES.invoices}
            className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
          >
            <ArrowLeft size={17} aria-hidden />
            Billing Overview
          </Link>

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <h1 className="text-3xl font-bold text-white sm:text-4xl">Invoice {invoice.invoiceNumber}</h1>
            <StatusChip pill hideDot tone={status} label={INVOICE_STATUS_LABEL[invoice.status]} />
          </div>

          <p className="mt-2 text-md text-white/85">
            {invoice.client}
            {invoice.projectName || invoice.jobName ? ` · ${invoice.projectName ?? invoice.jobName}` : ''} · Created{' '}
            {formatDate(invoice.createdAt)}
            {invoice.createdBy ? ` by ${invoice.createdBy}` : ''}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {can.send && (
            <Button leftIcon={Send} onClick={send}>
              Send Invoice
            </Button>
          )}
          {can.markPaid && stripeConnected && (
            <Button leftIcon={CreditCard} onClick={payOnline}>
              Pay Now
            </Button>
          )}
          {can.markPaid && (
            <Button variant={stripeConnected ? 'secondary' : 'primary'} leftIcon={Check} onClick={markPaid}>
              Mark as Paid
            </Button>
          )}
          {/* A real file download, not an Inertia page — an Inertia <Link> here
              would send an XHR visit and never actually download it. */}
          <a href={routeTo.invoicePdf(invoice.id)} target="_blank" rel="noreferrer" className={buttonStyles({ variant: 'white' })}>
            <Download size={18} aria-hidden />
            Download PDF
          </a>
          {can.update && (
            <ButtonLink href={routeTo.invoiceEdit(invoice.id)} variant="secondary" leftIcon={Pencil}>
              Edit
            </ButtonLink>
          )}
          {can.delete && (
            <Button variant="secondary" leftIcon={Trash2} onClick={deleteDialog.open}>
              Delete
            </Button>
          )}
        </div>
      </div>

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mt-4"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ============================================ Who, what, when ======== */}
      <Card padding="md" className="mt-5">
        <dl className="grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
          <InfoItem icon={Building2} label="Project" value={invoice.projectName ?? invoice.jobName ?? '—'}>
            {invoice.jobId !== null && (
              <Link href={routeTo.job(invoice.jobId)} className="text-xs font-medium text-brand hover:underline">
                Open job{invoice.jobName && invoice.projectName ? ` · ${invoice.jobName}` : ''}
              </Link>
            )}
          </InfoItem>
          <InfoItem icon={UserRound} label="Client" value={invoice.client} />
          <InfoItem icon={MapPin} label="Billing To" value={invoice.billingAddress ?? 'No address on file'} />
          <InfoItem icon={FileText} label="Invoice Number" value={invoice.invoiceNumber}>
            {invoice.estimateId !== null && invoice.estimateNumber && (
              <Link href={routeTo.estimate(invoice.estimateId)} className="text-xs font-medium text-brand hover:underline">
                From estimate {invoice.estimateNumber}
              </Link>
            )}
          </InfoItem>
          <InfoItem icon={CalendarDays} label="Invoice Date" value={formatCalendarDate(invoice.invoiceDate, 'MM/dd/yyyy')} />
          <InfoItem
            icon={CalendarDays}
            label="Due Date"
            value={invoice.dueDate ? formatCalendarDate(invoice.dueDate, 'MM/dd/yyyy') : '—'}
          />
          <InfoItem icon={Send} label="Sent" value={invoice.sentAt ? formatDate(invoice.sentAt) : 'Not sent'} />
          <InfoItem icon={Check} label="Paid" value={invoice.paidAt ? formatDate(invoice.paidAt) : 'Not paid'} />
        </dl>
      </Card>

      {/* ============================================== Summary cards ======== */}
      <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          icon={FileText}
          label="Approved Estimate"
          value={invoice.estimateTotal !== null ? formatCurrency(invoice.estimateTotal, 2) : '—'}
          note={invoice.estimateNumber ?? undefined}
          tone="cyan"
        />
        <MetricCard icon={Calculator} label="This Invoice" value={formatCurrency(invoice.total, 2)} note={shareOfEstimate} tone="blue" />
        <MetricCard icon={Wallet} label="Paid" value={formatCurrency(invoice.paidAmount, 2)} tone="green" />
        <MetricCard
          icon={Hourglass}
          label="Balance Due"
          value={formatCurrency(invoice.outstanding, 2)}
          tone={invoice.status === 'overdue' ? 'red' : 'cyan'}
          note={invoice.status === 'overdue' ? 'Past its due date' : undefined}
        />
      </div>

      {/* ================================================ Invoice lines ====== */}
      <CollapsibleCard
        className="mt-5"
        title="Invoice Lines"
        subtitle={invoice.estimateNumber ? `Billed against estimate ${invoice.estimateNumber}` : 'Lines on this invoice'}
        icon={Receipt}
        tone="blue"
        plain
        summary={
          <span className="tabular-nums">
            {items.length} {items.length === 1 ? 'line' : 'lines'} · {formatCurrency(invoice.subtotal, 2)}
          </span>
        }
        isOpen={linesOpen}
        onToggle={() => setLinesOpen((open) => !open)}
      >
        <InvoiceItemsTable
          invoiceId={invoice.id}
          items={items}
          editable={can.update}
          estimateNumber={invoice.estimateNumber}
        />
      </CollapsibleCard>

      {/* ============================================ Whole estimate ======== */}
      {estimateItems.length > 0 && (
        <CollapsibleCard
          className="mt-5"
          title="Estimate List"
          subtitle={`Every line of estimate ${invoice.estimateNumber ?? ''}`.trim()}
          icon={FileText}
          tone="info"
          plain
          summary={
            <span className="tabular-nums">
              {estimateItems.length} {estimateItems.length === 1 ? 'line' : 'lines'}
              {invoice.estimateTotal !== null && ` · ${formatCurrency(invoice.estimateTotal, 2)}`}
            </span>
          }
          isOpen={estimateOpen}
          onToggle={() => setEstimateOpen((open) => !open)}
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left text-md">
              <thead className="text-sm text-white/70">
                <tr>
                  <th className="px-3 py-2 font-medium">Description</th>
                  <th className="px-3 py-2 font-medium">Category</th>
                  <th className="px-3 py-2 text-right font-medium">Qty</th>
                  <th className="px-3 py-2 font-medium">Unit</th>
                  <th className="px-3 py-2 text-right font-medium">Unit cost</th>
                  <th className="px-3 py-2 text-right font-medium">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline">
                {estimateItems.map((line) => (
                  <tr key={line.id} className="text-white/90">
                    <td className="px-3 py-2">{line.description}</td>
                    <td className="px-3 py-2 capitalize">{line.category}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{line.quantity}</td>
                    <td className="px-3 py-2">{line.unit}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(line.unitCost, 2)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(line.total, 2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CollapsibleCard>
      )}

      {/* ================================================ Notes + totals ===== */}
      <div className="mt-5 grid items-start gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        <Card padding="md">
          <div className="flex items-center gap-3">
            <MessageSquare size={20} aria-hidden className="text-white/85" />
            <h2 className="text-md font-semibold text-white">Notes</h2>
          </div>
          <p className={cn('mt-3 text-md whitespace-pre-line', invoice.notes ? 'text-white/90' : 'text-white/60')}>
            {invoice.notes ?? 'No note on this invoice.'}
          </p>
        </Card>

        <Card padding="md">
          <dl className="space-y-3 text-md">
            <TotalRow label="Subtotal" value={formatCurrency(invoice.subtotal, 2)} />
            <TotalRow label={`Sales Tax (${invoice.taxPct}%)`} value={formatCurrency(invoice.taxTotal, 2)} />
            <div className="flex items-center justify-between gap-3 border-t border-hairline-strong pt-3">
              <dt className="text-lg font-semibold text-white">Total</dt>
              <dd className="text-2xl font-bold tabular-nums text-white">{formatCurrency(invoice.total, 2)}</dd>
            </div>
            <TotalRow label="Paid" value={formatCurrency(invoice.paidAmount, 2)} />
            <TotalRow label="Balance Due" value={formatCurrency(invoice.outstanding, 2)} strong />
          </dl>
        </Card>
      </div>

      {invoice.jobId !== null && (
        <Card padding="md" className="mt-5">
          <h2 className="text-lg font-semibold text-white">Journeyman Labor Hours</h2>
          <p className="mt-0.5 text-xs text-white/70">
            Every person&apos;s total hours on this job — counted the moment they&apos;re logged, no approval wait
          </p>
          <div className="mt-4 flex flex-col gap-2">
            {journeymanHours.length === 0 ? (
              <p className="text-md text-white/70">No time logged on this job yet.</p>
            ) : (
              journeymanHours.map((row) => (
                <JourneymanHourRow
                  key={row.userId}
                  jobId={invoice.jobId as number}
                  row={row}
                  canEdit={can.manageJobCosts}
                />
              ))
            )}
          </div>
        </Card>
      )}

      {jobCostSummary && (
        <Card padding="md" className="mt-5">
          <button
            type="button"
            className="flex w-full items-center justify-between gap-3 text-left"
            aria-expanded={costDetails.isOpen}
            onClick={costDetails.toggle}
          >
            <h2 className="text-lg font-semibold text-white">Estimated vs Actual</h2>
            <ChevronDown
              size={18}
              className={`shrink-0 text-white/70 transition-transform ${costDetails.isOpen ? 'rotate-180' : ''}`}
              aria-hidden
            />
          </button>

          <AnimatePresence initial={false}>
            {costDetails.isOpen && (
              <motion.div
                key="job-cost-details"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: MOTION.base }}
                className="overflow-hidden"
              >
                <ScopeVariance summary={jobCostSummary} />
              </motion.div>
            )}
          </AnimatePresence>
        </Card>
      )}

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete ${invoice.invoiceNumber}?`}
        description="This cannot be undone from here — restore it from the invoices list right after if needed."
        confirmLabel="Delete invoice"
        confirmVariant="danger"
        onConfirm={confirmDelete}
        onCancel={deleteDialog.close}
      />
      {scope && (
        <div className="mt-5">
          <ScopeHistory changes={scope.history} title="Scope Change History" emptyText="The job's estimate, change orders and costs have not changed since it was created." />
        </div>
      )}
    </PageTransition>
  )
}

function InfoItem({
  icon: Icon,
  label,
  value,
  children,
}: {
  icon: LucideIcon
  label: string
  value: string
  children?: React.ReactNode
}) {
  return (
    <div className="flex items-start gap-3">
      <span className="grid size-10 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={18} aria-hidden />
      </span>
      <div className="min-w-0">
        <dt className="text-xs text-white/70">{label}</dt>
        <dd className="text-md font-medium text-white">{value}</dd>
        {children}
      </div>
    </div>
  )
}

/**
 * One person's total hours on the job — a saved figure a manager typed
 * directly (`isOverridden`), or just the raw sum of their time entries
 * otherwise. Editing writes the same "saved figure" a manager can always see
 * and correct here, in place of the approval workflow this used to wait on.
 */
/** Splits a decimal hours total into whole hours + minutes, for the edit fields. */
function toHrsAndMin(hours: number): { hrs: string; min: string } {
  const totalMinutes = Math.round(hours * 60)

  return {
    hrs: String(Math.floor(totalMinutes / 60)),
    min: String(totalMinutes % 60),
  }
}

function JourneymanHourRow({
  jobId,
  row,
  canEdit,
}: {
  jobId: number
  row: JourneymanHoursRow
  canEdit: boolean
}) {
  const [editing, setEditing] = useState(false)
  const [{ hrs, min }, setFields] = useState(() => toHrsAndMin(row.hours))
  const [processing, setProcessing] = useState(false)

  const save = () => {
    const hours = (Number(hrs) || 0) + (Number(min) || 0) / 60

    router.put(
      routeTo.journeymanHours(jobId, row.userId),
      { hours },
      {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => setEditing(false),
      },
    )
  }

  return (
    <div className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 px-4 py-3">
      <div className="min-w-0">
        <p className="truncate text-md font-medium text-white">{row.name}</p>
        {row.role && <p className="text-2xs text-white/60">{row.role}</p>}
      </div>

      {editing ? (
        <div className="flex items-center gap-2">
          <TextInput
            id={`journeyman-hrs-${row.userId}`}
            type="number"
            min={0}
            value={hrs}
            onChange={(event) => setFields((current) => ({ ...current, hrs: event.target.value }))}
            className="w-24"
            controlClassName="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            rightSlot={<span className="pr-3 text-2xs text-white/60">hrs</span>}
          />
          <TextInput
            id={`journeyman-min-${row.userId}`}
            type="number"
            min={0}
            max={59}
            value={min}
            onChange={(event) => setFields((current) => ({ ...current, min: event.target.value }))}
            className="w-24"
            controlClassName="[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            rightSlot={<span className="pr-3 text-2xs text-white/60">min</span>}
          />
          <Button size="sm" isLoading={processing} onClick={save}>
            Save
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            leftIcon={X}
            onClick={() => {
              setFields(toHrsAndMin(row.hours))
              setEditing(false)
            }}
          >
            Cancel
          </Button>
        </div>
      ) : (
        <div className="flex items-center gap-3">
          <span className="tabular-nums text-md font-semibold text-white">
            {formatHours(row.hours)}
            {row.isOverridden && <span className="ml-1 text-2xs font-normal text-white/60">(edited)</span>}
          </span>
          {canEdit && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              leftIcon={Pencil}
              onClick={() => {
                setFields(toHrsAndMin(row.hours))
                setEditing(true)
              }}
            >
              Edit
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

/** One side of the estimated/actual comparison — hours plus every cost
 *  category, laid out the same way for both so they read as a pair. */
/**
 * What the job was estimated at when it was raised, against what it stands at
 * now — the latest estimate (final once the job is completed), the hours
 * worked and anything logged or adjusted on an invoice — and the difference.
 */
function ScopeVariance({ summary }: { summary: JobCostRow }) {
  const money = (value: number | null) => (value === null ? '—' : formatCurrency(value, 2))
  const rows: { label: string; estimated: number | null; actual: number | null; hours?: boolean }[] = [
    { label: 'Labor Hours', estimated: summary.estimatedLaborHours, actual: summary.actualLaborHours, hours: true },
    { label: 'Labor', estimated: summary.estimatedLaborCost, actual: summary.actualLaborCost },
    { label: 'Materials', estimated: summary.estimatedMaterialCost, actual: summary.actualMaterialCost },
    { label: 'Equipment', estimated: summary.estimatedEquipmentCost, actual: summary.actualEquipmentCost },
    { label: 'Other', estimated: summary.estimatedOtherCost, actual: summary.actualOtherCost },
    { label: 'Total', estimated: summary.estimatedTotalCost, actual: summary.actualTotalCost },
  ]

  return (
    <div className="mt-4 border-t border-hairline pt-4">
      {summary.hasBaseline && summary.baselineTotal !== null && summary.currentScopeTotal !== null && summary.scopeChange !== null && (
        <p className="mb-3 text-sm text-white/80">
          Estimate when the job was created {formatCurrency(summary.baselineTotal, 2)} → {summary.isFinal ? 'final' : 'latest'} {formatCurrency(summary.currentScopeTotal, 2)}
          {summary.scopeChange !== 0 && (
            <span className={summary.scopeChange > 0 ? 'font-medium text-status-danger' : 'font-medium text-status-success'}>
              {' '}({summary.scopeChange > 0 ? '+' : ''}{formatCurrency(summary.scopeChange, 2)})
            </span>
          )}
        </p>
      )}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[420px] text-md">
          <thead>
            <tr className="text-left text-xs tracking-wide text-white/70 uppercase">
              <th className="py-2 font-medium" />
              <th className="py-2 text-right font-medium">Estimated</th>
              <th className="py-2 text-right font-medium">{summary.isFinal ? 'Final actual' : 'Actual (latest)'}</th>
              <th className="py-2 text-right font-medium">Difference</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const diff = row.estimated !== null && row.actual !== null ? row.actual - row.estimated : null
              const format = (v: number | null) => (row.hours ? (v === null ? '—' : formatHours(v)) : money(v))
              const strong = row.label === 'Total'
              return (
                <tr key={row.label} className={strong ? 'border-t border-hairline font-semibold text-white' : 'text-white/90'}>
                  <td className="py-2">{row.label}</td>
                  <td className="py-2 text-right tabular-nums">{format(row.estimated)}</td>
                  <td className="py-2 text-right tabular-nums">{format(row.actual)}</td>
                  <td className={`py-2 text-right tabular-nums ${diff !== null && diff > 0 ? 'text-status-danger' : diff !== null && diff < 0 ? 'text-status-success' : ''}`}>
                    {diff === null ? '—' : `${diff > 0 ? '+' : ''}${row.hours ? formatHours(diff) : formatCurrency(diff, 2)}`}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function TotalRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={strong ? 'flex items-center justify-between gap-3 border-t border-hairline pt-2' : 'flex items-center justify-between gap-3'}>
      <dt className={strong ? 'text-md font-semibold text-white' : 'text-md text-white/90'}>{label}</dt>
      <dd className={strong ? 'text-lg font-bold text-white' : 'text-md font-medium text-white/90'}>{value}</dd>
    </div>
  )
}

InvoiceShow.layout = appLayout
