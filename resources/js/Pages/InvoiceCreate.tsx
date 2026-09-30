import { useEffect, useMemo, useState } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import {
  Building2,
  Calculator,
  CalendarDays,
  FileText,
  Lock,
  MessageSquare,
  Pencil,
  Plus,
  Receipt,
  Trash2,
  UserRound,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  IconButton,
  Modal,
  SelectField,
  Table,
  TextArea,
  TextInput,
} from '@/components/common'
import { LINE_CATEGORY_OPTIONS, LineTypePill } from '@/components/billing'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES } from '@/constants'
import type {
  ClientOption,
  InvoiceDraftLine,
  InvoiceEstimateOption,
  InvoiceJobOption,
  InvoiceLineCategory,
  TableColumn,
} from '@/types'
import { cn, formatCalendarDate, formatCurrency } from '@/utils'

export interface InvoiceCreateProps {
  /** Reference the server will assign on save. */
  nextNumber: string
  /** The client register. Clients are projects, so this is one list, not two. */
  clients: readonly ClientOption[]
  jobs: readonly InvoiceJobOption[]
  /** Sent/approved estimates not yet converted into an invoice. */
  estimates: readonly InvoiceEstimateOption[]
  /** Set when opened from a completed job's "Create Invoice" button. */
  preselectedJobId: number | null
  /** That job's own not-yet-invoiced estimate, when it has one. */
  preselectedEstimateId: number | null
}

interface InvoiceDraft {
  /** The client. Clients are projects, so this is a `projects` id. */
  client_id: string
  job_id: string
  estimate_id: string
  invoice_date: string
  due_date: string
  tax_pct: string
  notes: string
}

/** A line as it is being edited — strings in the boxes, turned to numbers on the way out. */
interface Line {
  readonly key: number
  readonly description: string
  readonly category: InvoiceLineCategory
  readonly quantity: string
  readonly unitPrice: string
  /** Where it came from: copied from the estimate, or typed here. */
  readonly origin: 'estimate' | 'manual'
}

const BLANK_LINE = { description: '', category: 'other' as InvoiceLineCategory, quantity: '1', unitPrice: '0' }

/**
 * An estimate belongs to a job when it names it — or, for an addendum, when its
 * original does. One that names no job at all belongs to the job's project.
 */
function belongsTo(estimate: InvoiceEstimateOption, target: InvoiceJobOption | undefined): boolean {
  if (target === undefined) return true

  return estimate.owner_job_id !== null
    ? estimate.owner_job_id === target.id
    : estimate.project_id !== null && estimate.project_id === (target.project_id ?? null)
}

const KIND_LABEL = { standalone: 'Original', merged: 'Merged', addendum: 'Addendum' } as const
const STATUS_LABEL = { draft: 'Draft', sent: 'Sent', approved: 'Approved', rejected: 'Rejected' } as const

/** "EST-1002 · Addendum 1 of EST-1001 — Harborview ($16,032) · Draft" */
function estimateLabel(estimate: InvoiceEstimateOption): string {
  const kind =
    estimate.kind === 'addendum'
      ? `Addendum ${estimate.addendum_number ?? ''}${estimate.parent_number ? ` of ${estimate.parent_number}` : ''}`.trim()
      : KIND_LABEL[estimate.kind]

  return `${estimate.number} · ${kind} — ${estimate.client} (${formatCurrency(estimate.grand_total, 0)}) · ${STATUS_LABEL[estimate.status]}`
}

const amountOf = (line: Pick<Line, 'quantity' | 'unitPrice'>) =>
  Math.round((Number(line.quantity) || 0) * (Number(line.unitPrice) || 0) * 100) / 100

/**
 * Prepare Project Invoice — the whole invoice on one screen: who it is for, what
 * it bills, and what it adds up to. Lines come from the estimate (and can be
 * changed) or are typed here; the server works the totals out again on save.
 *
 * Save Draft keeps it as a draft; Issue Invoice sends it straight away, by the same
 * rules as Send — so it needs at least one line.
 */
export default function InvoiceCreate({
  nextNumber,
  clients,
  jobs,
  estimates,
  preselectedJobId,
  preselectedEstimateId,
}: InvoiceCreateProps) {
  const form = useForm<InvoiceDraft>({
    client_id: '',
    job_id: '',
    estimate_id: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    tax_pct: '0',
    notes: '',
  })
  const { data, setData, processing, errors, hasErrors, clearErrors } = form

  const [lines, setLines] = useState<readonly Line[]>([])
  const [editing, setEditing] = useState<{ key: number | null; draft: typeof BLANK_LINE } | null>(null)
  const [previewing, setPreviewing] = useState(false)
  const nextKey = useMemo(() => ({ value: 1 }), [])

  const update = <K extends FormDataKeys<InvoiceDraft>>(field: K, value: FormDataValues<InvoiceDraft, K>) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const job = jobs.find((row) => String(row.id) === data.job_id)
  const estimate = estimates.find((row) => String(row.id) === data.estimate_id)
  // A project names its client, and the client does not change under it.
  const clientLocked = job?.client_id != null
  // Once a project is picked, only its own estimates are on offer.
  const offeredEstimates = estimates.filter((row) => belongsTo(row, job))

  /** The estimate's lines replace any copied from another; typed lines stay. */
  const loadEstimateLines = (estimate: InvoiceEstimateOption | undefined) => {
    setLines((current) => [
      ...(estimate
        ? estimate.items.map((item) => ({
            key: nextKey.value++,
            description: item.description,
            category: item.source_category ?? 'other',
            quantity: String(item.quantity),
            unitPrice: String(item.unit_price),
            origin: 'estimate' as const,
          }))
        : []),
      ...current.filter((line) => line.origin === 'manual'),
    ])
  }

  const applyEstimate = (estimateId: string) => {
    const picked = estimates.find((row) => String(row.id) === estimateId)
    // The estimate names its job, and through it the client: one project, one client.
    const pickedJob = picked?.owner_job_id ? jobs.find((row) => row.id === picked.owner_job_id) : undefined
    const clientId = pickedJob?.client_id ?? picked?.client_id

    setData({
      ...data,
      estimate_id: estimateId,
      client_id: clientId ? String(clientId) : data.client_id,
      job_id: picked?.owner_job_id ? String(picked.owner_job_id) : data.job_id,
    })
    loadEstimateLines(picked)
  }

  /**
   * Picking a project fixes the invoice to it: its client, and only its own
   * estimate. An estimate chosen for another project is dropped, and its lines
   * with it.
   */
  const applyJob = (jobId: string) => {
    const picked = jobs.find((row) => String(row.id) === jobId)
    const keepEstimate = estimate !== undefined && belongsTo(estimate, picked)

    setData({
      ...data,
      job_id: jobId,
      client_id: picked?.client_id ? String(picked.client_id) : data.client_id,
      estimate_id: keepEstimate ? data.estimate_id : '',
    })

    if (!keepEstimate) loadEstimateLines(undefined)
  }

  /*
   * Opened from a completed job's "Create Invoice" button — the same cross-fill a
   * manual pick would do, applied once on arrival. The estimate is preferred when
   * both are present: it already names the job and the client.
   */
  useEffect(() => {
    // A one-time fill on arrival, from what the link named — not derived state.
    /* eslint-disable react-hooks/set-state-in-effect */
    if (preselectedEstimateId) {
      applyEstimate(String(preselectedEstimateId))
    } else if (preselectedJobId) {
      applyJob(String(preselectedJobId))
    }
    /* eslint-enable react-hooks/set-state-in-effect */
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const client = clients.find((row) => String(row.id) === data.client_id)
  const billingAddress = client?.addresses.find((site) => site.isPrimary) ?? client?.addresses[0]

  const subtotal = Math.round(lines.reduce((sum, line) => sum + amountOf(line), 0) * 100) / 100
  const taxPct = Number(data.tax_pct) || 0
  const tax = Math.round(subtotal * (taxPct / 100) * 100) / 100
  const total = Math.round((subtotal + tax) * 100) / 100
  const shareOfEstimate = estimate && estimate.grand_total > 0 ? Math.round((subtotal / estimate.grand_total) * 100) : null

  const payloadLines = (): InvoiceDraftLine[] =>
    lines.map((line) => ({
      description: line.description.trim(),
      source_category: line.category,
      source: line.origin,
      quantity: Number(line.quantity) || 0,
      unit_price: Number(line.unitPrice) || 0,
    }))

  const submit = (issue: boolean) => {
    form.transform((values) => ({ ...values, items: payloadLines(), issue }))
    form.post(ROUTES.invoices)
  }

  /* ------------------------------------------------------------ line editor */

  const openNewLine = () => setEditing({ key: null, draft: BLANK_LINE })
  const openLine = (line: Line) =>
    setEditing({
      key: line.key,
      draft: { description: line.description, category: line.category, quantity: line.quantity, unitPrice: line.unitPrice },
    })

  const saveLine = () => {
    if (!editing || editing.draft.description.trim() === '') return

    const { key, draft } = editing

    setLines((current) =>
      key === null
        ? [...current, { ...draft, key: nextKey.value++, origin: 'manual' }]
        : current.map((line) => (line.key === key ? { ...line, ...draft } : line)),
    )
    setEditing(null)
  }

  const removeLine = (key: number) => setLines((current) => current.filter((line) => line.key !== key))

  const lineColumns: TableColumn<Line>[] = [
    {
      key: 'n',
      header: '#',
      width: 'w-12',
      render: (line) => <span className="text-white/75">{lines.indexOf(line) + 1}</span>,
    },
    { key: 'description', header: 'Description', render: (line) => <span className="text-white">{line.description}</span> },
    {
      key: 'type',
      header: 'Type',
      render: (line) => (
<LineTypePill category={line.category} />
      ),
    },
    {
      key: 'source',
      header: 'Source',
      render: (line) => <span className="text-white/90">{line.origin === 'estimate' ? `Estimate ${estimate?.number ?? ''}`.trim() : 'Manual'}</span>,
    },
    {
      key: 'qty',
      header: 'Qty',
      render: (line) => <span className="tabular-nums text-white">{(Number(line.quantity) || 0).toFixed(2)}</span>,
    },
    {
      key: 'price',
      header: 'Unit Price',
      render: (line) => <span className="tabular-nums whitespace-nowrap text-white">{formatCurrency(Number(line.unitPrice) || 0, 2)}</span>,
    },
    {
      key: 'amount',
      header: 'Amount',
      render: (line) => <span className="tabular-nums whitespace-nowrap text-white">{formatCurrency(amountOf(line), 2)}</span>,
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (line) => (
        <span className="flex items-center gap-1">
          <IconButton icon={Pencil} label={`Edit ${line.description}`} size="sm" variant="ghost" onClick={() => openLine(line)} />
          <IconButton icon={Trash2} label={`Remove ${line.description}`} size="sm" variant="ghost" onClick={() => removeLine(line.key)} />
        </span>
      ),
    },
  ]

  const itemErrors = Object.entries(errors).filter(([key]) => key === 'items' || key.startsWith('items.'))

  return (
    <PageTransition>
      <Head title="Create Invoice" />

      <PageHeader
        title="Prepare Project Invoice"
        subtitle="Create and review your invoice before issuing it to the client."
        breadcrumbs={[
          { label: 'Billing', href: ROUTES.billing },
          { label: 'Invoices', href: ROUTES.invoices },
          { label: 'Create Invoice' },
        ]}
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the invoice" className="mb-5">
          {itemErrors.length > 0
            ? itemErrors.map(([, message]) => message).join(' ')
            : 'Some fields need attention before this invoice can be saved.'}
        </Alert>
      )}

      {/* ================================================ Who, and when ====== */}
      <Card padding="md">
        <div className="grid gap-x-8 gap-y-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.2fr)_minmax(0,1fr)]">
          <Labelled icon={Building2} label="Project / Job">
            <SelectField
              id="invoice-job"
              aria-label="Job"
              options={[
                { label: 'No job', value: '' },
                ...jobs.map((row) => ({
                  label: row.completed === false ? `${row.name} — not completed` : row.name,
                  value: String(row.id),
                })),
              ]}
              value={data.job_id}
              onChange={(event) => applyJob(event.target.value)}
              {...(errors.job_id ? { error: errors.job_id } : {})}
            />
            {job?.project && <p className="mt-1 text-xs text-white/70">{job.project}</p>}
          </Labelled>

          <Labelled icon={UserRound} label="Client *">
            {clientLocked ? (
              <>
                <TextInput
                  id="invoice-client"
                  aria-label="Client"
                  value={client?.name ?? job?.client ?? ''}
                  readOnly
                  disabled
                  {...(errors.client_id ? { error: errors.client_id } : {})}
                />
                <p className="mt-1 flex items-center gap-1.5 text-xs text-white/70">
                  <Lock size={11} aria-hidden /> Set by the project
                </p>
              </>
            ) : (
              <SelectField
                id="invoice-client"
                aria-label="Client"
                options={[
                  { label: 'Select client', value: '' },
                  ...clients.map((row) => ({ label: row.name, value: String(row.id) })),
                ]}
                value={data.client_id}
                onChange={(event) => update('client_id', event.target.value)}
                {...(errors.client_id ? { error: errors.client_id } : {})}
              />
            )}
          </Labelled>

          <div>
            <p className="text-sm text-white/75">Billing To</p>
            {client ? (
              <div className="mt-1 text-sm text-white">
                <p className="font-medium">{client.name}</p>
                <p className="text-white/80">{billingAddress?.display ?? 'No address on file'}</p>
              </div>
            ) : (
              <p className="mt-1 text-sm text-white/60">Pick a client to see who it bills.</p>
            )}
          </div>

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
          <TextInput id="invoice-number" label="Invoice Number" value={nextNumber} readOnly disabled />

          <div className="lg:col-span-3">
            <SelectField
              id="invoice-estimate"
              label={
                job
                  ? `Estimate * — estimates & addenda for ${job.name} (${offeredEstimates.length})`
                  : 'Estimate *'
              }
              hint={
                offeredEstimates.length === 0
                  ? job
                    ? 'This project has no estimate waiting to be invoiced.'
                    : 'There is no estimate waiting to be invoiced.'
                  : undefined
              }
              options={[
                { label: 'Select estimate', value: '' },
                ...offeredEstimates.map((row) => ({
                  label: estimateLabel(row),
                  value: String(row.id),
                })),
              ]}
              value={data.estimate_id}
              onChange={(event) => applyEstimate(event.target.value)}
              {...(errors.estimate_id ? { error: errors.estimate_id } : {})}
            />
          </div>
        </div>
      </Card>

      {/* ============================================== Summary cards ======= */}
      <div className="mt-5 grid gap-4 md:grid-cols-3">
        <Stat
          icon={FileText}
          label="Approved Estimate"
          value={estimate ? formatCurrency(estimate.grand_total, 2) : '—'}
          note={estimate?.number}
          tone="cyan"
        />
        <Stat
          icon={Calculator}
          label="This Invoice"
          value={formatCurrency(subtotal, 2)}
          note={shareOfEstimate !== null ? `${shareOfEstimate}% of the estimate` : `${lines.length} ${lines.length === 1 ? 'line' : 'lines'}`}
          tone="blue"
        />
        <Stat
          icon={Wallet}
          label="Time & Material Actuals"
          value={job?.actual_cost != null ? formatCurrency(job.actual_cost, 2) : '—'}
          note={job ? undefined : 'Pick a job'}
          tone="green"
        />
      </div>

      {/* ================================================ Invoice lines ====== */}
      <Card padding="md" className="mt-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold text-white">Invoice Lines</h2>
          <Button leftIcon={Plus} onClick={openNewLine}>
            Add Line Item
          </Button>
        </div>

        <div className="mt-3 overflow-x-auto">
          {lines.length === 0 ? (
            <EmptyState
              icon={Receipt}
              title="No lines yet"
              description={
                data.estimate_id === ''
                  ? 'Pick the estimate above and its lines come in — then adjust them, or add your own.'
                  : 'Add the first line to start billing.'
              }
            />
          ) : (
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              className="min-w-3xl text-sm [&_th]:text-sm [&_td]:text-sm"
              columns={lineColumns}
              rows={lines}
              getRowId={(line) => line.key}
              caption="Invoice lines"
            />
          )}
        </div>
      </Card>

      {/* ================================================ Notes + totals ===== */}
      <div className="mt-5 grid items-start gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        <Card padding="md">
          <div className="flex items-center gap-3">
            <MessageSquare size={20} aria-hidden className="text-white/85" />
            <h2 className="text-md font-semibold text-white">Notes (Optional)</h2>
          </div>
          <TextArea
            id="invoice-notes"
            aria-label="Notes"
            rows={3}
            placeholder="Add a note to appear on the invoice..."
            value={data.notes}
            onChange={(event) => update('notes', event.target.value)}
            className="mt-3"
          />
        </Card>

        <Card padding="md">
          <dl className="space-y-3 text-md">
            <div className="flex items-center justify-between gap-3">
              <dt className="text-white/90">Subtotal</dt>
              <dd className="tabular-nums text-white">{formatCurrency(subtotal, 2)}</dd>
            </div>
            <div className="flex items-center justify-between gap-3">
              <dt className="flex items-center gap-2 text-white/90">
                Sales Tax
                <span className="flex items-center gap-1">
                  <input
                    id="invoice-tax-pct"
                    type="number"
                    inputMode="decimal"
                    min={0}
                    max={100}
                    step="0.01"
                    aria-label="Sales tax percent"
                    value={data.tax_pct}
                    onChange={(event) => update('tax_pct', event.target.value)}
                    className="w-20 rounded-panel border border-hairline-strong bg-white/8 px-2 py-1 text-right text-sm text-white focus:border-brand focus:outline-none"
                  />
                  <span className="text-sm text-white/75">%</span>
                </span>
              </dt>
              <dd className="tabular-nums text-white">{formatCurrency(tax, 2)}</dd>
            </div>
            {errors.tax_pct && <p className="text-xs text-red-300">{errors.tax_pct}</p>}
            <div className="flex items-center justify-between gap-3 border-t border-hairline-strong pt-3">
              <dt className="text-lg font-semibold text-white">Total</dt>
              <dd className="text-2xl font-bold tabular-nums text-white">{formatCurrency(total, 2)}</dd>
            </div>
          </dl>
        </Card>
      </div>

      {/* ===================================================== Actions ======= */}
      <div className="mt-5 flex flex-wrap items-center justify-end gap-3">
        <Button variant="secondary" isLoading={processing} onClick={() => submit(false)}>
          Save Draft
        </Button>
        <Button variant="outline" onClick={() => setPreviewing(true)}>
          Preview
        </Button>
        <Button isLoading={processing} disabled={lines.length === 0} onClick={() => submit(true)}>
          Issue Invoice
        </Button>
      </div>

      {/* ================================================ Line editor ======== */}
      <Modal
        isOpen={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.key === null ? 'Add line item' : 'Edit line item'}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button disabled={!editing || editing.draft.description.trim() === ''} onClick={saveLine}>
              {editing?.key === null ? 'Add line' : 'Save line'}
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            <TextInput
              id="line-description"
              label="Description *"
              placeholder="e.g. Site preparation"
              value={editing.draft.description}
              onChange={(event) => setEditing({ ...editing, draft: { ...editing.draft, description: event.target.value } })}
              autoFocus
            />
            <SelectField
              id="line-type"
              label="Type"
              options={LINE_CATEGORY_OPTIONS}
              value={editing.draft.category}
              onChange={(event) =>
                setEditing({ ...editing, draft: { ...editing.draft, category: event.target.value as InvoiceLineCategory } })
              }
            />
            <div className="grid grid-cols-2 gap-4">
              <TextInput
                id="line-qty"
                type="number"
                inputMode="decimal"
                min={0}
                step="0.01"
                label="Quantity"
                value={editing.draft.quantity}
                onChange={(event) => setEditing({ ...editing, draft: { ...editing.draft, quantity: event.target.value } })}
              />
              <TextInput
                id="line-price"
                type="number"
                inputMode="decimal"
                min={0}
                step="0.01"
                label="Unit price"
                value={editing.draft.unitPrice}
                onChange={(event) => setEditing({ ...editing, draft: { ...editing.draft, unitPrice: event.target.value } })}
              />
            </div>
            <p className="text-right text-sm text-white/80">
              Amount{' '}
              <strong className="text-white">
                {formatCurrency(amountOf({ quantity: editing.draft.quantity, unitPrice: editing.draft.unitPrice }), 2)}
              </strong>
            </p>
          </div>
        )}
      </Modal>

      {/* ================================================== Preview ========== */}
      <Modal
        isOpen={previewing}
        onClose={() => setPreviewing(false)}
        title={`Invoice ${nextNumber}`}
        description="How this invoice reads — nothing is saved until you save or issue it."
        size="lg"
        footer={
          <Button variant="secondary" onClick={() => setPreviewing(false)}>
            Close
          </Button>
        }
      >
        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <p className="text-xs tracking-wide text-white/70 uppercase">Billed to</p>
            <p className="mt-1 font-semibold text-white">{client?.name ?? 'No client chosen'}</p>
            <p className="text-sm text-white/80">{billingAddress?.display ?? ''}</p>
          </div>
          <div className="sm:text-right">
            <p className="flex items-center gap-2 text-sm text-white/85 sm:justify-end">
              <CalendarDays size={15} aria-hidden />
              {data.invoice_date ? formatCalendarDate(data.invoice_date) : '—'}
              {data.due_date && <span className="text-white/65">· due {formatCalendarDate(data.due_date)}</span>}
            </p>
            {job && <p className="mt-1 text-sm text-white/80">{job.name}</p>}
          </div>
        </div>

        <ul className="mt-4 divide-y divide-hairline rounded-panel border border-hairline">
          {lines.length === 0 ? (
            <li className="p-4 text-sm text-white/70">No lines yet.</li>
          ) : (
            lines.map((line) => (
              <li key={line.key} className="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
                <span className="min-w-0 truncate text-white">{line.description}</span>
                <span className="shrink-0 tabular-nums text-white/85">
                  {(Number(line.quantity) || 0).toFixed(2)} × {formatCurrency(Number(line.unitPrice) || 0, 2)}
                </span>
                <span className="w-28 shrink-0 text-right font-medium tabular-nums text-white">
                  {formatCurrency(amountOf(line), 2)}
                </span>
              </li>
            ))
          )}
        </ul>

        <dl className="mt-4 ml-auto w-full max-w-xs space-y-1.5 text-sm">
          <div className="flex justify-between text-white/90">
            <dt>Subtotal</dt>
            <dd className="tabular-nums">{formatCurrency(subtotal, 2)}</dd>
          </div>
          <div className="flex justify-between text-white/90">
            <dt>Sales Tax ({taxPct}%)</dt>
            <dd className="tabular-nums">{formatCurrency(tax, 2)}</dd>
          </div>
          <div className="flex justify-between border-t border-hairline-strong pt-2 text-lg font-bold text-white">
            <dt>Total</dt>
            <dd className="tabular-nums">{formatCurrency(total, 2)}</dd>
          </div>
        </dl>

        {data.notes.trim() !== '' && (
          <p className="mt-4 border-t border-hairline pt-3 text-sm whitespace-pre-line text-white/85">{data.notes}</p>
        )}
      </Modal>
    </PageTransition>
  )
}

InvoiceCreate.layout = appLayout

function Labelled({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start gap-3">
      <span className="mt-6 grid size-11 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={20} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="mb-1 text-sm text-white/75">{label}</p>
        {children}
      </div>
    </div>
  )
}

const STAT_TONE = {
  cyan: 'border-brand/60 shadow-[0_0_0_1px_rgb(51_227_255/0.25)]',
  blue: 'border-status-blue/60 shadow-[0_0_0_1px_rgb(77_155_255/0.25)]',
  green: 'border-status-success/60 shadow-[0_0_0_1px_rgb(19_246_11/0.2)]',
} as const

const STAT_ICON = {
  cyan: 'bg-brand/15 text-brand',
  blue: 'bg-status-blue/15 text-status-blue',
  green: 'bg-status-success/15 text-status-success',
} as const

function Stat({
  icon: Icon,
  label,
  value,
  note,
  tone,
}: {
  icon: LucideIcon
  label: string
  value: string
  note?: string | undefined
  tone: keyof typeof STAT_TONE
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-4 rounded-card border-2 bg-white/4 p-5 backdrop-blur-sm',
        STAT_TONE[tone],
      )}
    >
      <span className={cn('grid size-14 shrink-0 place-items-center rounded-panel', STAT_ICON[tone])}>
        <Icon size={26} aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-white/80">{label}</p>
        <p className="truncate text-2xl font-bold text-white">{value}</p>
        {note && <p className="text-xs text-white/65">{note}</p>}
      </div>
    </div>
  )
}
