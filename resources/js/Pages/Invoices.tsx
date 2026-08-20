import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  DollarSign,
  Hourglass,
  Plus,
  SearchX,
  SlidersHorizontal,
  Timer,
  Undo2,
  Wallet,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
  TextInput,
} from '@/components/common'
import { StatCard } from '@/components/dashboard'
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
import { useDebouncedValue, useDisclosure } from '@/hooks'
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
  formatCurrency,
  formatDate,
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
  can: InvoiceAbilities
}

/**
 * Invoices — every client invoice in one place: filterable, exportable as a
 * PDF per record, and summarised at the bottom against real payment data.
 */
export default function Invoices({ invoices, filters, clients, jobs, summary, can }: InvoicesProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [draft, setDraft] = useState(filters)
  const [selected, setSelected] = useState<ReadonlySet<number>>(new Set())
  const [pendingDelete, setPendingDelete] = useState<Invoice | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)

  const filterBar = useDisclosure(true)
  const deleteDialog = useDisclosure()
  const debouncedQuery = useDebouncedValue(query)

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

  useEffect(() => {
    if (debouncedQuery === filters.search) return
    applyFilters({ search: debouncedQuery })
  }, [debouncedQuery, filters.search, applyFilters])

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

  // Selection is a page-local UI concern — nothing on the server reads it, and
  // no bulk action exists yet to act on it, so it never survives a navigation.
  const selectableIds = rows.filter((row) => row.isEditable || can.manage).map((row) => row.id)
  const allSelected = selectableIds.length > 0 && selectableIds.every((id) => selected.has(id))
  const someSelected = selectableIds.some((id) => selected.has(id)) && !allSelected

  const toggleAll = () => {
    setSelected(allSelected ? new Set() : new Set(selectableIds))
  }

  const toggleOne = (id: number) => {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const columns: TableColumn<Invoice>[] = [
    {
      key: 'select',
      header: '',
      width: 'w-10',
      render: (invoice) => (
        <Checkbox
          id={`invoice-select-${invoice.id}`}
          label=""
          aria-label={`Select ${invoice.invoiceNumber}`}
          checked={selected.has(invoice.id)}
          onChange={() => toggleOne(invoice.id)}
        />
      ),
    },
    {
      key: 'invoice',
      header: 'Invoice',
      render: (invoice) => (
        <ButtonLink href={routeTo.invoice(invoice.id)} variant="ghost" size="sm" className="px-0 font-bold text-brand hover:underline">
          #{invoice.invoiceNumber}
        </ButtonLink>
      ),
    },
    {
      key: 'client',
      header: 'Client',
      render: (invoice) => <span className="text-white">{invoice.client}</span>,
    },
    {
      key: 'amount',
      header: 'Amount',
      render: (invoice) => (
        <span className="whitespace-nowrap tabular-nums text-white">
          {formatCurrency(invoice.total, 2)}
        </span>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      render: (invoice) => (
        <span className="whitespace-nowrap text-white/90">{formatDate(invoice.date)}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (invoice) => (
        <StatusChip hideDot tone={INVOICE_STATUS_TONE[invoice.status]} label={INVOICE_STATUS_LABEL[invoice.status]} />
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-40',
      render: (invoice) => (
        <div className="flex items-center gap-2">
          <ButtonLink href={routeTo.invoice(invoice.id)} size="sm">
            View
          </ButtonLink>
          <Button
            variant="white"
            size="sm"
            disabled={!invoice.isEditable}
            className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white disabled:opacity-50"
            onClick={() => requestDelete(invoice)}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ]

  const summaryCards: readonly { label: string; value: string; icon: typeof Wallet; tone?: 'brand' | 'danger' | 'success' | 'info' }[] = [
    { label: 'Total Outstanding', value: formatCurrency(summary.totalOutstanding, 2), icon: Wallet, tone: 'brand' },
    { label: 'Overdue', value: formatCurrency(summary.overdue, 2), icon: Timer, tone: 'danger' },
    { label: 'Paid This Month', value: formatCurrency(summary.paidThisMonth, 2), icon: DollarSign, tone: 'success' },
    {
      label: 'Average Days to Pay',
      value: summary.averageDaysToPay !== null ? `${summary.averageDaysToPay} days` : '—',
      icon: Hourglass,
      tone: 'info',
    },
  ]

  return (
    <PageTransition>
      <Head title="Invoices" />

      <PageHeader
        title="Invoices"
        subtitle="Manage and track all your client invoices"
        breadcrumbs={[{ label: 'Billing', href: ROUTES.billing }, { label: 'Invoices' }]}
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={SlidersHorizontal}
              aria-expanded={filterBar.isOpen}
              onClick={filterBar.toggle}
            >
              Filter
            </Button>
            {can.create && (
              <ButtonLink href={ROUTES.invoiceCreate} leftIcon={Plus}>
                Create Invoice
              </ButtonLink>
            )}
          </>
        }
      />

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
            <div className="mb-4">
              <SearchBox
                value={query}
                onValueChange={setQuery}
                placeholder="Search invoice # or client…"
                aria-label="Search invoices"
                className="max-w-sm"
              />
            </div>

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
      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
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
            <>
              <div className="mb-3">
                <Checkbox
                  id="invoice-select-all"
                  label={`Select All${selected.size > 0 ? ` (${selected.size} selected)` : ''}`}
                  checked={allSelected}
                  aria-checked={someSelected ? 'mixed' : allSelected}
                  onChange={toggleAll}
                />
              </div>
              <Table
                dense
                variant="lined"
                headerVariant="plain"
                columns={columns}
                rows={rows}
                getRowId={(invoice) => invoice.id}
                caption="Client invoices"
              />
            </>
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
      </div>

      {/* ================================================= Invoice summary ===== */}
      <div className="mt-6">
        <h2 className="mb-4 text-xl font-semibold text-white">Invoice Summary</h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {summaryCards.map((card, index) => (
            <StatCard key={card.label} index={index} label={card.label} value={card.value} icon={card.icon} tone={card.tone} />
          ))}
        </div>
      </div>

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
