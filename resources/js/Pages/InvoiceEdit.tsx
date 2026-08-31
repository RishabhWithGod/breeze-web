import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
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
import { ROUTES, routeTo } from '@/constants'
import type { InvoiceDetail, InvoiceJobOption } from '@/types'

export interface InvoiceEditProps {
  invoice: InvoiceDetail
  clients: readonly string[]
  jobs: readonly InvoiceJobOption[]
}

interface InvoiceEditForm {
  client: string
  job_id: string
  invoice_date: string
  due_date: string
  tax_pct: string
  notes: string
}

/**
 * Edit Invoice — the header fields only. Status moves forward through Send
 * and Mark as Paid on the detail screen, never through this form.
 */
export default function InvoiceEdit({ invoice, clients, jobs }: InvoiceEditProps) {
  const { data, setData, put, processing, errors, hasErrors, clearErrors } =
    useForm<InvoiceEditForm>({
      client: invoice.client,
      job_id: invoice.jobId ? String(invoice.jobId) : '',
      invoice_date: invoice.invoiceDate,
      due_date: invoice.dueDate ?? '',
      tax_pct: String(invoice.taxPct),
      notes: invoice.notes ?? '',
    })

  const update = <K extends FormDataKeys<InvoiceEditForm>>(
    field: K,
    value: FormDataValues<InvoiceEditForm, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    put(routeTo.invoice(invoice.id), { preserveScroll: true })
  }

  return (
    <PageTransition>
      <Head title={`Edit ${invoice.invoiceNumber}`} />

      <PageHeader
        title="Edit Invoice"
        subtitle={invoice.invoiceNumber}
        breadcrumbs={[
          { label: 'Billing', href: ROUTES.billing },
          { label: 'Invoices', href: ROUTES.invoices },
          { label: invoice.invoiceNumber, href: routeTo.invoice(invoice.id) },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink href={routeTo.invoice(invoice.id)} variant="secondary" leftIcon={ArrowLeft}>
            Back to invoice
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this invoice can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading title="Invoice details" />

          <div className="space-y-5">
            <div>
              <TextInput
                id="invoice-client"
                label="Client *"
                list="invoice-client-options"
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
              onChange={(event) => update('job_id', event.target.value)}
            />

            <div className="grid gap-5 sm:grid-cols-2">
              <TextInput
                id="invoice-date"
                type="date"
                label="Invoice Date *"
                value={data.invoice_date}
                onChange={(event) => update('invoice_date', event.target.value)}
                {...(errors.invoice_date ? { error: errors.invoice_date } : {})}
              />
              <TextInput
                id="invoice-due-date"
                type="date"
                label="Due Date"
                value={data.due_date}
                onChange={(event) => update('due_date', event.target.value)}
                {...(errors.due_date ? { error: errors.due_date } : {})}
              />
            </div>

            <TextInput
              id="invoice-tax-pct"
              type="number"
              inputMode="decimal"
              min={0}
              max={100}
              step="0.1"
              label="Tax (%)"
              value={data.tax_pct}
              onChange={(event) => update('tax_pct', event.target.value)}
              {...(errors.tax_pct ? { error: errors.tax_pct } : {})}
            />

            <TextArea
              id="invoice-notes"
              label="Notes"
              rows={3}
              value={data.notes}
              onChange={(event) => update('notes', event.target.value)}
            />
          </div>

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            <ButtonLink href={routeTo.invoice(invoice.id)} variant="white">
              Cancel
            </ButtonLink>
            <Button type="submit" leftIcon={Save} isLoading={processing}>
              Save Changes
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

InvoiceEdit.layout = appLayout
