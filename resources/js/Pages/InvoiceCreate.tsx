import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Briefcase, Building2, CalendarDays, FileText, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  SectionHeading,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type { InvoiceEstimateOption, InvoiceJobOption } from '@/types'
import { formatCurrency, formatDate } from '@/utils'

export interface InvoiceCreateProps {
  /** Reference the server will assign on save. */
  nextNumber: string
  /** Every client already known to the app — from jobs, estimates and past invoices. */
  clients: readonly string[]
  jobs: readonly InvoiceJobOption[]
  /** Sent/approved estimates not yet converted into an invoice. */
  estimates: readonly InvoiceEstimateOption[]
}

interface InvoiceDraft {
  client: string
  job_id: string
  estimate_id: string
  invoice_date: string
  due_date: string
  tax_pct: string
  notes: string
}

/**
 * Create Invoice — a full screen for the header only. Line items are added
 * from the invoice's own detail screen once it exists, the same two-step
 * flow Estimates already use for theirs.
 */
export default function InvoiceCreate({ nextNumber, clients, jobs, estimates }: InvoiceCreateProps) {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<InvoiceDraft>({
      client: '',
      job_id: '',
      estimate_id: '',
      invoice_date: new Date().toISOString().slice(0, 10),
      due_date: '',
      tax_pct: '0',
      notes: '',
    })

  const update = <K extends FormDataKeys<InvoiceDraft>>(
    field: K,
    value: FormDataValues<InvoiceDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const applyEstimate = (estimateId: string) => {
    const estimate = estimates.find((row) => String(row.id) === estimateId)
    setData({
      ...data,
      estimate_id: estimateId,
      client: estimate ? estimate.client : data.client,
      job_id: estimate?.job_id ? String(estimate.job_id) : data.job_id,
    })
  }

  const applyJob = (jobId: string) => {
    const job = jobs.find((row) => String(row.id) === jobId)
    setData({
      ...data,
      job_id: jobId,
      client: job?.client && !data.client ? job.client : data.client,
    })
  }

  const submit = (event?: React.FormEvent) => {
    event?.preventDefault()
    post(ROUTES.invoices, { preserveScroll: true })
  }

  const chosenEstimate = estimates.find((row) => String(row.id) === data.estimate_id)

  return (
    <PageTransition>
      <Head title="Create Invoice" />

      <PageHeader
        title="Create Invoice"
        subtitle="Add a client invoice — line items are added once it's created."
        breadcrumbs={[
          { label: 'Billing', href: ROUTES.billing },
          { label: 'Invoices', href: ROUTES.invoices },
          { label: 'Create' },
        ]}
        actions={
          <ButtonLink href={ROUTES.invoices} variant="secondary" leftIcon={ArrowLeft}>
            Back to invoices
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this invoice can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] xl:items-start">
          <Card padding="lg">
            <SectionHeading
              title="Invoice details"
              subtitle={`Reference ${nextNumber} is reserved for this invoice.`}
            />

            <div className="space-y-5">
              {estimates.length > 0 && (
                <div>
                  <SelectField
                    id="invoice-estimate"
                    label="Generate from Estimate (optional)"
                    options={[
                      { label: 'None — start blank', value: '' },
                      ...estimates.map((estimate) => ({
                        label: `${estimate.number} — ${estimate.client} (${formatCurrency(estimate.grand_total, 0)})`,
                        value: String(estimate.id),
                      })),
                    ]}
                    value={data.estimate_id}
                    onChange={(event) => applyEstimate(event.target.value)}
                  />
                  <p className="mt-1.5 text-sm text-white/70">
                    Copies the estimate&rsquo;s line items onto this invoice.
                  </p>
                </div>
              )}

              <div>
                <TextInput
                  id="invoice-client"
                  label="Client *"
                  list="invoice-client-options"
                  placeholder="e.g. Apex Construction"
                  autoComplete="off"
                  value={data.client}
                  onChange={(event) => update('client', event.target.value)}
                  {...(errors.client ? { error: errors.client } : {})}
                />
                <datalist id="invoice-client-options">
                  {clients.map((client) => (
                    <option key={client} value={client} />
                  ))}
                </datalist>
              </div>

              <SelectField
                id="invoice-job"
                label="Job (optional)"
                options={[
                  { label: 'No job', value: '' },
                  ...jobs.map((job) => ({
                    label: job.client ? `${job.name} — ${job.client}` : job.name,
                    value: String(job.id),
                  })),
                ]}
                value={data.job_id}
                onChange={(event) => applyJob(event.target.value)}
              />

              <TextInput
                id="invoice-date"
                type="date"
                label="Invoice Date *"
                value={data.invoice_date}
                onChange={(event) => update('invoice_date', event.target.value)}
                {...(errors.invoice_date ? { error: errors.invoice_date } : {})}
              />

              <TextInput
                id="invoice-tax-pct"
                type="number"
                inputMode="decimal"
                min={0}
                max={100}
                step="0.1"
                label="Tax (%)"
                hint="Applied to the subtotal once line items are added."
                value={data.tax_pct}
                onChange={(event) => update('tax_pct', event.target.value)}
                {...(errors.tax_pct ? { error: errors.tax_pct } : {})}
              />

              <TextArea
                id="invoice-notes"
                label="Notes"
                rows={3}
                placeholder="Payment terms, scope covered, etc."
                value={data.notes}
                onChange={(event) => update('notes', event.target.value)}
              />
            </div>

            <div className="mt-8 flex flex-wrap items-center gap-3 border-t border-hairline pt-6">
              <Button type="submit" leftIcon={Save} isLoading={processing}>
                Create Invoice
              </Button>
              <ButtonLink href={ROUTES.invoices} variant="secondary">
                Cancel
              </ButtonLink>
            </div>
          </Card>

          <Card padding="lg" className="xl:sticky xl:top-24">
            <SectionHeading
              title="Summary"
              subtitle="Updates as you type — nothing is saved until you submit."
            />

            <dl className="space-y-4">
              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <FileText size={14} aria-hidden className="text-brand" />
                  Invoice #
                </dt>
                <dd className="mt-2 font-mono text-lg font-semibold text-white">{nextNumber}</dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <Building2 size={14} aria-hidden className="text-brand" />
                  Client
                </dt>
                <dd className="mt-2 text-md text-white">
                  {data.client.trim() || <span className="text-white/60">Not set</span>}
                </dd>
              </div>

              {data.job_id && (
                <div className="rounded-panel border border-hairline bg-white/4 p-4">
                  <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                    <Briefcase size={14} aria-hidden className="text-brand" />
                    Job
                  </dt>
                  <dd className="mt-2 text-md text-white">
                    {jobs.find((job) => String(job.id) === data.job_id)?.name ?? '—'}
                  </dd>
                </div>
              )}

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <CalendarDays size={14} aria-hidden className="text-brand" />
                  Invoice Date
                </dt>
                <dd className="mt-2 text-md text-white">
                  {data.invoice_date ? formatDate(data.invoice_date) : <span className="text-white/60">Not set</span>}
                </dd>
              </div>

              {chosenEstimate && (
                <div className="rounded-panel border border-brand/30 bg-brand/10 p-4">
                  <dt className="text-xs tracking-wide text-white/70 uppercase">Copying from</dt>
                  <dd className="mt-2 text-md text-white">
                    {chosenEstimate.number} · {formatCurrency(chosenEstimate.grand_total, 2)}
                  </dd>
                </div>
              )}
            </dl>
          </Card>
        </div>
      </form>
    </PageTransition>
  )
}

InvoiceCreate.layout = appLayout
