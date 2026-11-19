import { useCallback, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  ChevronDown,
  Eye,
  Filter,
  Plus,
  SearchX,
  Trash2,
  Undo2,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SelectField,
  Stat,
  StatusChip,
  Table,
  TextInput,
  ToolbarSearch,
  UnderlineTabs,
  Card,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  INVOICE_SORT_OPTIONS,
  INVOICE_STATUS_FILTERS,
  MOTION,
  ROUTES,
  routeTo,
  type InvoiceSort,
  type InvoiceStatusFilter,
} from '@/constants'
import { useDisclosure, usePermissions } from '@/hooks'
import type {
  Invoice,
  InvoiceAbilities,
  InvoiceJobOption,
  InvoiceSummary,
  Paginated,
  SharedPageProps,
  TableColumn,
} from '@/types'
import {
  INVOICE_STATUS_LABEL,
  INVOICE_STATUS_TONE,
  formatCalendarDate,
  formatCurrency,
} from '@/utils'

interface InvoiceFilters {
  search: string
  status: InvoiceStatusFilter
  client: string
  job: number | null
  date_from: string
  date_to: string
  amount_min: string
  amount_max: string
  sort: InvoiceSort
}

export interface InvoicesProps {
  invoices: Paginated<Invoice>
  filters: InvoiceFilters
  clients: readonly string[]
  jobs: readonly InvoiceJobOption[]
  summary: InvoiceSummary
  /** Per status tab, over everything the person owns. */
  statusCounts: Record<string, number>
  can: InvoiceAbilities
}

/**
 * Billing Overview — every client invoice in one place, filterable, with the
 * key figures (outstanding, overdue, paid, days to pay) above it from real payment data.
 */
export default function Invoices({ invoices, filters, clients, jobs, summary, statusCounts, can }: InvoicesProps) {
  const { can: permitted } = usePermissions()
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [draft, setDraft] = useState(filters)
  const [pendingDelete, setPendingDelete] = useState<Invoice | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)

  const filterBar = useDisclosure()
  const deleteDialog = useDisclosure()

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed
  const canUndo = lastDeletedId !== null && Boolean(flash.warning)

  const rows = invoices.data
  const { meta } = invoices

  const clientOptions = [
    { label: 'All Clients', value: 'all' },
    ...clients.map((client) => ({ label: client, value: client })),
  ]
  const jobOptions = [
    { label: 'All Jobs', value: 'all' },
    ...jobs.map((job) => ({ label: job.name, value: String(job.id) })),
  ]

  /** Merges into the *current* query string, keyed by the URL not a stale snapshot. */
  const applyFilters = useCallback(
    (changes: Partial<InvoiceFilters & { page: number }>) => {
      const params = new URLSearchParams(window.location.search)

      for (const [key, value] of Object.entries(changes)) {
        if (value === '' || value === 'all' || value === undefined || value === null) {
          params.delete(key)
        } else {
          params.set(key, String(value))
        }
      }

      if (!('page' in changes)) params.delete('page')

      const queryString = params.toString()

      router.get(
        queryString ? `${ROUTES.invoices}?${queryString}` : ROUTES.invoices,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
      )
    },
    [],
  )

  const resetFilters = useCallback(() => {
    const cleared: InvoiceFilters = {
      search: '',
      status: 'all',
      client: 'all',
      job: null,
      date_from: '',
      date_to: '',
      amount_min: '',
      amount_max: '',
      sort: 'date-desc',
    }
    setQuery('')
    setDraft(cleared)
    router.get(ROUTES.invoices, {}, { preserveState: true, preserveScroll: true, replace: true })
  }, [])

  const requestDelete = useCallback(
    (invoice: Invoice) => {
      setPendingDelete(invoice)
      deleteDialog.open()
    },
    [deleteDialog],
  )

  const handleDeleteConfirmed = useCallback(() => {
    if (!pendingDelete) return

    const { id } = pendingDelete

    router.delete(routeTo.invoice(id), {
      preserveScroll: true,
      onSuccess: () => setLastDeletedId(id),
    })

    setPendingDelete(null)
    deleteDialog.close()
  }, [pendingDelete, deleteDialog])

  const handleUndo = useCallback(() => {
    if (lastDeletedId === null) return

    router.post(routeTo.invoiceRestore(lastDeletedId), {}, { preserveScroll: true })
    setLastDeletedId(null)
  }, [lastDeletedId])

  const columns: TableColumn<Invoice>[] = [
    { key: 'client', header: 'Client', render: (invoice) => <span className="text-white">{invoice.client}</span> },
    {
      key: 'project',
      header: 'Project',
      render: (invoice) => <span className="text-white">{invoice.jobName ?? '—'}</span>,
    },
    {
      key: 'invoice',
      header: 'Invoice #',
      render: (invoice) => (
        <Link
          href={routeTo.invoice(invoice.id)}
          className="font-semibold whitespace-nowrap text-brand hover:underline"
        >
          #{invoice.invoiceNumber}
        </Link>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      render: (invoice) => (
        <span className="whitespace-nowrap tabular-nums text-white">{formatCurrency(invoice.total, 2)}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (invoice) => (
        <StatusChip pill hideDot tone={INVOICE_STATUS_TONE[invoice.status]} label={INVOICE_STATUS_LABEL[invoice.status]} />
      ),
    },
    {
      key: 'due',
      header: 'Due Date',
      render: (invoice) => (
        <span className="whitespace-nowrap text-white/90">
          {invoice.dueDate ? formatCalendarDate(invoice.dueDate, 'MM/dd/yyyy') : '—'}
        </span>
      ),
    },
    {
      key: 'open',
      header: 'Open Project',
      render: (invoice) => (
        <span className="text-white">{invoice.projectOpen === null ? '—' : invoice.projectOpen ? 'Yes' : 'No'}</span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-24',
      render: (invoice) => (
        <MoreMenu
          variant="minimal"
          ariaLabel={`Actions for ${invoice.invoiceNumber}`}
          items={[
            { label: 'View', icon: Eye, onSelect: () => router.visit(routeTo.invoice(invoice.id)) },
            {
              label: 'Delete',
              icon: Trash2,
              destructive: true,
              disabled: !invoice.isEditable,
              onSelect: () => requestDelete(invoice),
            },
          ]}
        />
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Invoices" />

      <PageHeader
        title="Billing Overview"
        subtitle="View key metrics and invoice details across all clients and projects."
        breadcrumbs={[{ label: 'Billing' }, { label: 'Billing Overview' }]}
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={Filter}
              rightIcon={ChevronDown}
              aria-expanded={filterBar.isOpen}
              onClick={filterBar.toggle}
            >
              Filters
            </Button>
            {can.create && permitted('billing.create') && (
              <ButtonLink href={ROUTES.invoiceCreate} leftIcon={Plus}>
                Create Invoice
              </ButtonLink>
            )}
          </>
        }
      />

      {/* ================================================== Key figures ====== */}
      <div className="mb-5 grid grid-cols-2 gap-4 xl:grid-cols-4">
        <Stat label="Total Outstanding" value={formatCurrency(summary.totalOutstanding, 2)} className="h-full" />
        <Stat label="Overdue" value={formatCurrency(summary.overdue, 2)} className="h-full" />
        <Stat label="Paid This Month" value={formatCurrency(summary.paidThisMonth, 2)} className="h-full" />
        <Stat
          label="Average Days to Pay"
          value={summary.averageDaysToPay !== null ? `${summary.averageDaysToPay} days` : '0 days'}
          className="h-full"
        />
      </div>

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={canUndo ? 'warning' : 'success'}
            className="mb-6"
            onDismiss={() => setDismissed(notice)}
          >
            <span className="flex flex-wrap items-center gap-3">
              {notice}
              {canUndo && (
                <Button variant="secondary" size="sm" leftIcon={Undo2} onClick={handleUndo}>
                  Undo
                </Button>
              )}
            </span>
          </Alert>
        )}
      </AnimatePresence>

      {/* ==================================================== Filters ========= */}
      <AnimatePresence initial={false}>
        {filterBar.isOpen && (
          <motion.div
            key="filter-bar"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: MOTION.base }}
            className="mb-6 overflow-hidden rounded-card border border-hairline glass p-5 shadow-panel sm:p-6"
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <SelectField
                id="invoice-status-filter"
                label="Status"
                options={INVOICE_STATUS_FILTERS}
                value={draft.status}
                onChange={(event) => setDraft({ ...draft, status: event.target.value as InvoiceStatusFilter })}
              />
              <SelectField
                id="invoice-client-filter"
                label="Client"
                options={clientOptions}
                value={draft.client}
                onChange={(event) => setDraft({ ...draft, client: event.target.value })}
              />
              <SelectField
                id="invoice-job-filter"
                label="Job"
                options={jobOptions}
                value={draft.job !== null ? String(draft.job) : 'all'}
                onChange={(event) =>
                  setDraft({ ...draft, job: event.target.value === 'all' ? null : Number(event.target.value) })
                }
              />
              <SelectField
                id="invoice-sort"
                label="Sort by"
                options={INVOICE_SORT_OPTIONS}
                value={draft.sort}
                onChange={(event) => setDraft({ ...draft, sort: event.target.value as InvoiceSort })}
              />
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <TextInput
                id="invoice-date-from"
                type="date"
                label="Date From"
                value={draft.date_from}
                onChange={(event) => setDraft({ ...draft, date_from: event.target.value })}
              />
              <TextInput
                id="invoice-date-to"
                type="date"
                label="Date To"
                value={draft.date_to}
                onChange={(event) => setDraft({ ...draft, date_to: event.target.value })}
              />
              <TextInput
                id="invoice-amount-min"
                type="number"
                min={0}
                label="Amount Min"
                value={draft.amount_min}
                onChange={(event) => setDraft({ ...draft, amount_min: event.target.value })}
              />
              <TextInput
                id="invoice-amount-max"
                type="number"
                min={0}
                label="Amount Max"
                value={draft.amount_max}
                onChange={(event) => setDraft({ ...draft, amount_max: event.target.value })}
              />
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button size="sm" onClick={() => applyFilters({ ...draft })}>
                Apply Filters
              </Button>
              <Button variant="white" size="sm" onClick={resetFilters}>
                Reset
              </Button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* ================================================= Invoices table ====== */}
      <UnderlineTabs
        tabs={[
          { key: 'all', label: 'All', count: (statusCounts['draft'] ?? 0) + (statusCounts['sent'] ?? 0) + (statusCounts['paid'] ?? 0) },
          { key: 'draft', label: 'Draft', count: statusCounts['draft'] ?? 0 },
          { key: 'sent', label: 'Pending', count: statusCounts['sent'] ?? 0 },
          { key: 'paid', label: 'Paid', count: statusCounts['paid'] ?? 0 },
          { key: 'overdue', label: 'Overdue', count: statusCounts['overdue'] ?? 0 },
        ]}
        value={filters.status}
        onChange={(next) => {
          setDraft({ ...draft, status: next as InvoiceStatusFilter })
          applyFilters({ status: next as InvoiceStatusFilter })
        }}
      />

      <Card padding="none" className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-hairline px-4 py-3">
          <ToolbarSearch
            value={query}
            onChange={setQuery}
            onSubmit={(value) => applyFilters({ search: value })}
            applied={filters.search !== ''}
            placeholder="Search clients, projects, or invoice numbers"
          />
          <span className="ml-auto text-[13px] text-[var(--text-3)]">
            {meta.total} {meta.total === 1 ? 'invoice' : 'invoices'}
          </span>
        </div>
        <div className="p-4 sm:p-5">
          {rows.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title="No invoices found"
              description="No invoices match your current filters. Try another status or clear the search."
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  Reset filters
                </Button>
              }
            />
          ) : (
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              className="text-sm [&_th]:text-sm [&_td]:text-sm"
              columns={columns}
              rows={rows}
              getRowId={(invoice) => invoice.id}
              onRowClick={(invoice) => router.visit(routeTo.invoice(invoice.id))}
              caption="Client invoices"
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => applyFilters({ page })}
            summary={meta.total === 0 ? 'No invoices to display' : `Showing ${rows.length} of ${meta.total} invoices`}
          />
        </div>
      </Card>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete ${pendingDelete?.invoiceNumber ?? ''}?`}
        description="The invoice is removed from your list. You can undo this straight after."
        confirmLabel="Delete invoice"
        confirmVariant="danger"
        onConfirm={handleDeleteConfirmed}
        onCancel={() => {
          setPendingDelete(null)
          deleteDialog.close()
        }}
      />
    </PageTransition>
  )
}

Invoices.layout = appLayout
