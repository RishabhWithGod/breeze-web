import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ArrowLeft,
  Briefcase,
  Check,
  ChevronDown,
  CreditCard,
  Download,
  Pencil,
  Send,
  Trash2,
  X,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  SectionHeading,
  StatusChip,
  TextInput,
} from '@/components/common'
import { InvoiceItemsTable } from '@/components/billing'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { MOTION, ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  InvoiceActionAbilities,
  InvoiceDetail,
  InvoiceItemRow,
  JobCostRow,
  JourneymanHoursRow,
  SharedPageProps,
} from '@/types'
import { INVOICE_STATUS_LABEL, INVOICE_STATUS_TONE, formatCurrency, formatDate, formatHours } from '@/utils'

export interface InvoiceShowProps {
  invoice: InvoiceDetail
  items: readonly InvoiceItemRow[]
  /** The job this invoice was raised for's estimate-vs-actual breakdown —
   *  `null` when the invoice has no job, or the field it prices is redacted
   *  for a role without cost visibility. */
  jobCostSummary: JobCostRow | null
  /** Every person with time on that same job and their total hours — the
   *  source `jobCostSummary.actualLaborHours` below is summed from. */
  journeymanHours: readonly JourneymanHoursRow[]
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
  jobCostSummary,
  journeymanHours,
  can,
  stripeConnected,
}: InvoiceShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()
  const costDetails = useDisclosure()

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

  return (
    <PageTransition>
      <Head title={`Invoice ${invoice.invoiceNumber}`} />

      <PageHeader
        title={`Invoice ${invoice.invoiceNumber}`}
        subtitle={`${invoice.client}${invoice.jobName ? ` · ${invoice.jobName}` : ''}`}
        breadcrumbs={[
          { label: 'Billing', href: ROUTES.billing },
          { label: 'Invoices', href: ROUTES.invoices },
          { label: invoice.invoiceNumber },
        ]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ButtonLink href={ROUTES.invoices} variant="secondary" size="sm" leftIcon={ArrowLeft}>
              Back to Invoices
            </ButtonLink>
            {/* A real file download, not an Inertia page — an Inertia <Link>
                here would send an XHR visit and never actually download it. */}
            <Button
              variant="secondary"
              size="sm"
              leftIcon={Download}
              onClick={() => window.open(routeTo.invoicePdf(invoice.id), '_blank')}
            >
              Download PDF
            </Button>
            {can.update && (
              <ButtonLink href={routeTo.invoiceEdit(invoice.id)} size="sm" leftIcon={Pencil}>
                Edit
              </ButtonLink>
            )}
            {can.delete && (
              <Button size="sm" variant="danger" leftIcon={Trash2} onClick={deleteDialog.open}>
                Delete
              </Button>
            )}
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mb-6"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      <div className="mb-6 flex flex-wrap items-center gap-3">
        <StatusChip tone={INVOICE_STATUS_TONE[invoice.status]} label={INVOICE_STATUS_LABEL[invoice.status]} />
        <span className="text-sm text-white/70">
          Created {formatDate(invoice.createdAt)}
          {invoice.createdBy ? ` by ${invoice.createdBy}` : ''}
        </span>
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card accent="brand" padding="lg">
          <SectionHeading as="h3" title="Invoice Information" />
          <dl className="grid grid-cols-2 gap-4">
            <Field label="Client" value={invoice.client} />
            <Field label="Job" value={invoice.jobName ?? '—'} />
            <Field label="Invoice Date" value={formatDate(invoice.invoiceDate)} />
            <Field label="Due Date" value={invoice.dueDate ? formatDate(invoice.dueDate) : '—'} />
            {invoice.estimateNumber && <Field label="From Estimate" value={invoice.estimateNumber} />}
            <Field label="Sent" value={invoice.sentAt ? formatDate(invoice.sentAt) : 'Not sent'} />
            <Field label="Paid" value={invoice.paidAt ? formatDate(invoice.paidAt) : 'Not paid'} />
          </dl>
          {invoice.jobId && (
            <div className="mt-4 border-t border-hairline pt-4">
              <ButtonLink href={routeTo.job(invoice.jobId)} variant="secondary" size="sm" leftIcon={Briefcase}>
                Open job
              </ButtonLink>
            </div>
          )}
        </Card>

        <Card accent="success" padding="lg">
          <SectionHeading as="h3" title="Totals & Tax" />
          <dl className="flex flex-col gap-2">
            <TotalRow label="Subtotal" value={formatCurrency(invoice.subtotal, 2)} />
            <TotalRow label={`Tax (${invoice.taxPct}%)`} value={formatCurrency(invoice.taxTotal, 2)} />
            <TotalRow label="Total" value={formatCurrency(invoice.total, 2)} strong />
            <TotalRow label="Paid" value={formatCurrency(invoice.paidAmount, 2)} />
            <TotalRow label="Balance Due" value={formatCurrency(invoice.outstanding, 2)} strong />
          </dl>

          {(can.send || can.markPaid) && (
            <div className="mt-4 flex flex-wrap gap-3 border-t border-hairline pt-4">
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
            </div>
          )}
        </Card>
      </div>

      <Card accent="warning" padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Line Items" />
        <InvoiceItemsTable invoiceId={invoice.id} items={items} editable={can.update} />
      </Card>

      {invoice.jobId !== null && (
        <Card accent="info" padding="lg" className="mt-6">
          <SectionHeading
            as="h3"
            title="Journeyman Labor Hours"
            subtitle="Every person's total hours on this job — counted the moment they're logged, no approval wait"
          />
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
        <Card accent="brand" padding="lg" className="mt-6">
          <button
            type="button"
            className="flex w-full items-center justify-between gap-3 text-left"
            aria-expanded={costDetails.isOpen}
            onClick={costDetails.toggle}
          >
            <SectionHeading as="h3" title="Job Cost Details" className="mb-0" />
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
                <div className="mt-4 grid gap-4 border-t border-hairline pt-4 sm:grid-cols-2">
                  <CostColumn
                    title="Estimated"
                    hours={jobCostSummary.estimatedLaborHours}
                    laborCost={jobCostSummary.estimatedLaborCost}
                    materialCost={jobCostSummary.estimatedMaterialCost}
                    equipmentCost={jobCostSummary.estimatedEquipmentCost}
                    otherCost={jobCostSummary.estimatedOtherCost}
                    totalCost={jobCostSummary.estimatedTotalCost}
                  />
                  <CostColumn
                    title="Actual"
                    hours={jobCostSummary.actualLaborHours}
                    laborCost={jobCostSummary.actualLaborCost}
                    materialCost={jobCostSummary.actualMaterialCost}
                    equipmentCost={jobCostSummary.actualEquipmentCost}
                    otherCost={jobCostSummary.actualOtherCost}
                    totalCost={jobCostSummary.actualTotalCost}
                  />
                </div>
              </motion.div>
            )}
          </AnimatePresence>
        </Card>
      )}

      {invoice.notes && (
        <Card accent="neutral" padding="lg" className="mt-6">
          <SectionHeading as="h3" title="Notes" />
          <p className="text-md text-white/90">{invoice.notes}</p>
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
    </PageTransition>
  )
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-2xs tracking-wide text-white/80 uppercase">{label}</dt>
      <dd className="mt-1 truncate text-md text-white" title={value}>
        {value}
      </dd>
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
            className="w-20"
            rightSlot={<span className="pr-3 text-2xs text-white/60">hrs</span>}
          />
          <TextInput
            id={`journeyman-min-${row.userId}`}
            type="number"
            min={0}
            max={59}
            value={min}
            onChange={(event) => setFields((current) => ({ ...current, min: event.target.value }))}
            className="w-20"
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
function CostColumn({
  title,
  hours,
  laborCost,
  materialCost,
  equipmentCost,
  otherCost,
  totalCost,
}: {
  title: string
  hours: number
  laborCost: number | null
  materialCost: number | null
  equipmentCost: number | null
  otherCost: number | null
  totalCost: number | null
}) {
  const money = (value: number | null) => (value === null ? '—' : formatCurrency(value, 2))

  return (
    <div className="rounded-panel border border-hairline bg-white/4 p-4">
      <p className="mb-3 text-sm font-semibold tracking-wide text-white/80 uppercase">{title}</p>
      <dl className="flex flex-col gap-2">
        <TotalRow label="Labor Hours" value={formatHours(hours)} />
        <TotalRow label="Labor" value={money(laborCost)} />
        <TotalRow label="Materials" value={money(materialCost)} />
        <TotalRow label="Equipment" value={money(equipmentCost)} />
        <TotalRow label="Other" value={money(otherCost)} />
        <TotalRow label="Total" value={money(totalCost)} strong />
      </dl>
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
