import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  ArrowLeft,
  Briefcase,
  Check,
  CreditCard,
  Download,
  Pencil,
  Send,
  Trash2,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  SectionHeading,
  StatusChip,
} from '@/components/common'
import { InvoiceItemsTable } from '@/components/billing'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import { useDisclosure } from '@/hooks'
import type { InvoiceActionAbilities, InvoiceDetail, InvoiceItemRow, SharedPageProps } from '@/types'
import { INVOICE_STATUS_LABEL, INVOICE_STATUS_TONE, formatCurrency, formatDate } from '@/utils'

export interface InvoiceShowProps {
  invoice: InvoiceDetail
  items: readonly InvoiceItemRow[]
  can: InvoiceActionAbilities
  /** Whether a connected Stripe processor exists to actually take an online payment against. */
  stripeConnected: boolean
}

/**
 * Invoice detail — the header, its line items, and the send/mark-paid
 * workflow. Status only ever changes through the dedicated actions below;
 * nothing here lets it be typed in freely.
 */
export default function InvoiceShow({ invoice, items, can, stripeConnected }: InvoiceShowProps) {
  const { flash } = usePage<SharedPageProps>().props
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()

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
        <Card padding="lg">
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

        <Card padding="lg">
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

      <Card padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Line Items" />
        <InvoiceItemsTable invoiceId={invoice.id} items={items} editable={can.update} />
      </Card>

      {invoice.notes && (
        <Card padding="lg" className="mt-6">
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

function TotalRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={strong ? 'flex items-center justify-between gap-3 border-t border-hairline pt-2' : 'flex items-center justify-between gap-3'}>
      <dt className={strong ? 'text-md font-semibold text-white' : 'text-md text-white/90'}>{label}</dt>
      <dd className={strong ? 'text-lg font-bold text-white' : 'text-md font-medium text-white/90'}>{value}</dd>
    </div>
  )
}

InvoiceShow.layout = appLayout
