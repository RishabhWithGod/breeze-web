import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { Eye, SearchX, SlidersHorizontal, Trash2, Undo2 } from 'lucide-react'
import {
  Alert,
  Button,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
  TextInput,
} from '@/components/common'
import { EstimateCard } from '@/components/estimates'
import { appLayout, PageTransition } from '@/components/layout'
import {
  ESTIMATE_SORT_OPTIONS,
  ESTIMATE_STATUS_FILTERS,
  MOTION,
  ROUTES,
  routeTo,
  type EstimateSort,
  type EstimateStatusFilter,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  Estimate,
  Paginated,
  SharedPageProps,
  TableColumn,
} from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
} from '@/utils'

const STATUS_FILTER_OPTIONS = ESTIMATE_STATUS_FILTERS.map((option) => ({
  label: option.label,
  value: option.value,
}))

const SORT_OPTIONS = ESTIMATE_SORT_OPTIONS.map((option) => ({
  label: option.label,
  value: option.value,
}))

interface EstimateFilters {
  search: string
  status: EstimateStatusFilter
  client: string
  project: string
  date_from: string
  date_to: string
  sort: EstimateSort
}

export interface EstimatesProps {
  estimates: Paginated<Estimate>
  filters: EstimateFilters
  clients: readonly string[]
  projects: readonly string[]
}

/**
 * Estimates — every client-facing estimate in one place.
 *
 * Filtering, sorting and pagination all run in the database against
 * query-string state; create, delete and undo go through EstimateController.
 */
export default function Estimates({
  estimates,
  filters,
  clients,
  projects,
}: EstimatesProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [pendingDelete, setPendingDelete] = useState<Estimate | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [bannerDismissed, setBannerDismissed] = useState(false)

  // The reference screen shows its filter bar open; "More Filters" reveals the
  // secondary row beneath it.
  // Closed by default: most visits are to read the list, not to narrow it.
  const filterBar = useDisclosure()
  const deleteDialog = useDisclosure()

  // Derived from the flash rather than mirrored into state. Undo is offered only
  // while the delete's own warning is the current message.
  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed
  const canUndo = lastDeletedId !== null && Boolean(flash.warning)

  const clientOptions = [
    { label: 'All Clients', value: 'all' },
    ...clients.map((client) => ({ label: client, value: client })),
  ]

  const projectOptions = [
    { label: 'All Projects', value: 'all' },
    ...projects.map((project) => ({ label: project, value: project })),
  ]

  /**
   * Merges `changes` into the *current* query string rather than into a props
   * snapshot. Reading the URL at call time means an in-flight request can never
   * be re-sent with stale filters — which is what made Reset leave a date
   * bound applied. Defaults ('' / 'all') are dropped so URLs stay clean, and
   * any filter change returns to page 1.
   */
  const applyFilters = useCallback(
    (changes: Partial<EstimateFilters & { page: number }>) => {
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
        queryString ? `${ROUTES.estimates}?${queryString}` : ROUTES.estimates,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
      )
    },
    [],
  )


  const requestDelete = useCallback(
    (estimate: Estimate) => {
      setPendingDelete(estimate)
      deleteDialog.open()
    },
    [deleteDialog],
  )

  const handleDeleteConfirmed = useCallback(() => {
    if (!pendingDelete) return

    const { id } = pendingDelete

    router.delete(routeTo.estimate(id), {
      preserveScroll: true,
      onSuccess: () => setLastDeletedId(id),
    })

    setPendingDelete(null)
    deleteDialog.close()
  }, [pendingDelete, deleteDialog])

  const handleUndo = useCallback(() => {
    if (lastDeletedId === null) return

    router.post(routeTo.estimateRestore(lastDeletedId), {}, { preserveScroll: true })
    setLastDeletedId(null)
  }, [lastDeletedId])

  const resetFilters = useCallback(() => {
    setQuery('')
    // Clean slate — no params at all, so nothing can survive the reset.
    router.get(ROUTES.estimates, {}, { preserveState: true, preserveScroll: true, replace: true })
  }, [])

  const columns: TableColumn<Estimate>[] = [
    {
      key: 'number',
      header: 'Estimate #',
      render: (estimate) => (
        <span className="font-medium whitespace-nowrap text-white">{estimate.number}</span>
      ),
    },
    {
      key: 'project',
      header: 'Project',
      render: (estimate) => <span className="text-white">{estimate.project ?? '—'}</span>,
    },
    {
      key: 'client',
      header: 'Client',
      render: (estimate) => <span className="text-white">{estimate.client}</span>,
    },
    {
      key: 'amount',
      header: 'Amount',
      render: (estimate) => (
        <span className="whitespace-nowrap tabular-nums text-white">
          {formatCurrency(estimate.amount, 2)}
        </span>
      ),
    },
    {
      key: 'revision',
      header: 'Revision',
      render: (estimate) => <span className="tabular-nums text-white">{estimate.revision}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (estimate) => (
        <StatusChip
          hideDot
          pill
          tone={ESTIMATE_STATUS_TONE[estimate.status]}
          label={ESTIMATE_STATUS_LABEL[estimate.status]}
        />
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-28',
      render: (estimate) => (
        <MoreMenu
          variant="minimal"
          ariaLabel={`Actions for ${estimate.number}`}
          items={[
            {
              label: 'View',
              icon: Eye,
              onSelect: () => router.visit(routeTo.estimate(estimate.id)),
            },
            {
              label: 'Delete',
              icon: Trash2,
              destructive: true,
              onSelect: () => requestDelete(estimate),
            },
          ]}
        />
      ),
    },
  ]

  const rows = estimates.data
  const { meta } = estimates

  return (
    <PageTransition>
      <Head title="Estimates" />

      {/* =========================================== Estimates panel ========= */}
      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <header className="flex flex-col gap-4 border-b border-hairline grad-ocean-soft px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <h1 className="text-2xl font-bold text-white sm:text-3xl">Estimates</h1>

          <Button
            variant={filterBar.isOpen ? 'primary' : 'white'}
            leftIcon={SlidersHorizontal}
            aria-expanded={filterBar.isOpen}
            onClick={filterBar.toggle}
            className="lg:shrink-0"
          >
            Filters
          </Button>
        </header>

        <div className="p-5 sm:p-6">
          {!bannerDismissed && (
            <Alert
              tone="info"
              className="mb-5 [&_p]:text-md [&_div]:text-sm"
              title="Addendums create estimate revisions"
              onDismiss={() => setBannerDismissed(true)}
            >
              Apply revised or additional drawings to an existing estimate. Breeze.AI
              identifies changes, updates the takeoff, and creates a new revision while
              preserving the complete estimate history.
            </Alert>
          )}

          {/* Search + the three quick filters, always in view. */}
          <div className="mb-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto_auto]">
            <SearchBox
              id="estimate-search"
              value={query}
              onValueChange={setQuery}
              onSearch={(value) => applyFilters({ search: value })}
              placeholder="Search estimates, projects, or clients..."
              aria-label="Search estimates"
              containerClassName="lg:max-w-xl"
            />
            <SelectField
              id="estimate-status-filter"
              aria-label="Status"
              options={STATUS_FILTER_OPTIONS}
              value={filters.status}
              onChange={(event) =>
                applyFilters({ status: event.target.value as EstimateStatusFilter })
              }
              className="lg:w-52"
            />
            <SelectField
              id="estimate-client-filter"
              aria-label="Client"
              options={clientOptions}
              value={filters.client}
              onChange={(event) => applyFilters({ client: event.target.value })}
              className="lg:w-44"
            />
            <SelectField
              id="estimate-project-filter"
              aria-label="Project"
              options={projectOptions}
              value={filters.project}
              onChange={(event) => applyFilters({ project: event.target.value })}
              className="lg:w-44"
            />
          </div>

          <AnimatePresence initial={false}>
            {filterBar.isOpen && (
              <motion.div
                key="filter-bar"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: MOTION.base }}
                className="mb-5 overflow-hidden"
              >
                <div className="grid gap-4 rounded-panel border border-hairline bg-white/4 p-4 sm:grid-cols-2 xl:grid-cols-4">
                  <TextInput
                    id="estimate-date-from"
                    type="date"
                    label="Issued on or after"
                    value={filters.date_from}
                    onChange={(event) => applyFilters({ date_from: event.target.value })}
                  />
                  <TextInput
                    id="estimate-date-to"
                    type="date"
                    label="Issued on or before"
                    value={filters.date_to}
                    onChange={(event) => applyFilters({ date_to: event.target.value })}
                  />
                  <SelectField
                    id="estimate-sort"
                    label="Sort by"
                    options={SORT_OPTIONS}
                    value={filters.sort}
                    onChange={(event) => applyFilters({ sort: event.target.value as EstimateSort })}
                  />
                  <div className="flex items-end">
                    <Button variant="secondary" onClick={resetFilters}>
                      Reset
                    </Button>
                  </div>
                </div>
              </motion.div>
            )}
          </AnimatePresence>

          {/* Action feedback */}
          <AnimatePresence initial={false}>
            {notice && (
              <Alert
                key={notice}
                tone={canUndo ? 'warning' : 'success'}
                className="mb-5"
                onDismiss={() => setDismissed(notice)}
              >
                <span className="flex flex-wrap items-center gap-3">
                  {notice}
                  {canUndo && (
                    <Button
                      variant="secondary"
                      size="sm"
                      leftIcon={Undo2}
                      onClick={handleUndo}
                    >
                      Undo
                    </Button>
                  )}
                </span>
              </Alert>
            )}
          </AnimatePresence>

          {rows.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title="No estimates found"
              description="No estimates match your current filters. Try another status or clear the search."
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  Reset filters
                </Button>
              }
            />
          ) : (
            <>
              {/* Table view — xl and up, where all seven columns fit */}
              <div className="hidden xl:block">
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  className="text-sm [&_th]:text-sm [&_td]:text-sm [&_td_span]:text-sm"
                  columns={columns}
                  rows={rows}
                  getRowId={(estimate) => estimate.id}
                  caption="All client estimates"
                />
              </div>

              {/* Card view — below xl */}
              <ul className="space-y-3 xl:hidden">
                {rows.map((estimate, index) => (
                  <EstimateCard
                    key={estimate.id}
                    estimate={estimate}
                    index={index}
                    onDelete={requestDelete}
                  />
                ))}
              </ul>
            </>
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => applyFilters({ page })}
            summary={
              meta.total === 0
                ? 'No estimates to display'
                : `Showing ${meta.from} to ${meta.to} of ${meta.total} estimates`
            }
          />
        </div>
      </section>


      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete ${pendingDelete?.number ?? ''}?`}
        description="The estimate is removed from your list. You can undo this straight after."
        confirmLabel="Delete estimate"
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

Estimates.layout = appLayout
