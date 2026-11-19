import { useCallback, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  CheckCircle2,
  Eye,
  Layers,
  Plus,
  SearchX,
  SlidersHorizontal,
  Trophy,
  TrendingDown,
  Trash2,
  Undo2,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SelectField,
  Seg,
  Stat,
  Table,
  TextInput,
  ToolbarSearch,
  UnderlineTabs,
} from '@/components/common'
import { BidBoard, EstimateCard } from '@/components/estimates'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  ESTIMATE_SORT_OPTIONS,
  ESTIMATE_STATUS_FILTERS,
  MOTION,
  ROUTES,
  routeTo,
  type EstimateSort,
  type EstimateStatusFilter,
} from '@/constants'
import { useDisclosure, usePermissions } from '@/hooks'
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
  formatDate,
} from '@/utils'

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
  /** `board` (the default) or `list`. */
  view: 'board' | 'list'
  /** Every estimate for the bid board — unpaged, and not narrowed by status. */
  board: readonly Estimate[]
  estimates: Paginated<Estimate>
  filters: EstimateFilters
  clients: readonly string[]
  projects: readonly string[]
  /** Per status: how many, and what they add up to — over the search, not the status filter. */
  statusCounts: Readonly<Record<string, { readonly count: number; readonly amount: number }>>
}

/**
 * Estimates — every client-facing estimate in one place.
 *
 * Filtering, sorting and pagination all run in the database against
 * query-string state; create, delete and undo go through EstimateController.
 */
export default function Estimates({
  view,
  board,
  estimates,
  filters,
  clients,
  projects,
  statusCounts,
}: EstimatesProps) {
  const { flash } = usePage<SharedPageProps>().props
  const { can: permitted } = usePermissions()

  const [query, setQuery] = useState(filters.search)
  const [pendingDelete, setPendingDelete] = useState<Estimate | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)
  // (`bannerDismissed` state for the hidden banner below: const [bannerDismissed, setBannerDismissed] = useState(false))

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
    (changes: Partial<EstimateFilters & { page: number; view: string }>) => {
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
      header: 'Estimate',
      render: (estimate) => (
        <div className="min-w-0">
          <Link href={routeTo.estimate(estimate.id)} className="block font-bold whitespace-nowrap text-white hover:text-brand">
            {estimate.number}
          </Link>
          <p className="text-[13px] text-[var(--text-3)]">Revision {estimate.revision}</p>
        </div>
      ),
    },
    {
      key: 'project',
      header: 'Project',
      render: (estimate) => (
        <div className="min-w-0">
          <p className="truncate text-white">{estimate.project ?? '—'}</p>
          <p className="truncate text-[13px] text-[var(--text-3)]">{estimate.client}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (estimate) => (
        <Badge tone={ESTIMATE_STATUS_TONE[estimate.status]}>{ESTIMATE_STATUS_LABEL[estimate.status]}</Badge>
      ),
    },
    {
      key: 'issued',
      header: 'Issued',
      render: (estimate) => <span className="whitespace-nowrap">{formatDate(estimate.date)}</span>,
    },
    {
      key: 'amount',
      header: 'Total',
      align: 'right',
      render: (estimate) => (
        <span className="font-semibold whitespace-nowrap tabular-nums text-white">
          {formatCurrency(estimate.amount, 2)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      width: 'w-12',
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

  const bucket = (...statuses: string[]) => ({
    count: statuses.reduce((sum, status) => sum + (statusCounts[status]?.count ?? 0), 0),
    amount: statuses.reduce((sum, status) => sum + (statusCounts[status]?.amount ?? 0), 0),
  })
  const open = bucket('draft', 'sent')
  const approved = bucket('approved')
  const rejected = bucket('rejected')
  const decided = approved.count + rejected.count
  const allCount = Object.values(statusCounts).reduce((sum, entry) => sum + entry.count, 0)

  const rows = estimates.data
  const { meta } = estimates

  return (
    <PageTransition>
      <Head title="Estimates" />

      <PageHeader
        title="Estimates"
        subtitle="Your bid board: what's out, what's won, and what is still being prepared."
        actions={
          <>
            <Seg
              ariaLabel="View"
              value={view}
              onChange={(next) => applyFilters({ view: next === 'list' ? 'list' : '' })}
              options={[
                { key: 'board', label: 'Bid board' },
                { key: 'list', label: 'List' },
              ]}
            />
            {view === 'list' && (
              <Button
                variant={filterBar.isOpen ? 'primary' : 'secondary'}
                leftIcon={SlidersHorizontal}
                aria-expanded={filterBar.isOpen}
                onClick={filterBar.toggle}
              >
                Filters
              </Button>
            )}
            {permitted('estimates.create') && (
              <ButtonLink href={ROUTES.estimateCreate} leftIcon={Plus}>
                New estimate
              </ButtonLink>
            )}
          </>
        }
      />

      {/* The figures over the whole register — read straight from the status counts. */}
      <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat
          label="Open bids"
          icon={<Layers size={15} aria-hidden />}
          value={formatCurrency(open.amount, 0)}
          delta={`${open.count} preparing or submitted`}
        />
        <Stat
          label="Approved"
          icon={<CheckCircle2 size={15} aria-hidden />}
          value={formatCurrency(approved.amount, 0)}
          delta={`${approved.count} ${approved.count === 1 ? 'estimate' : 'estimates'} approved`}
        />
        <Stat
          label="Win rate"
          icon={<Trophy size={15} aria-hidden />}
          value={decided > 0 ? `${Math.round((approved.count / decided) * 100)}%` : '—'}
          delta={`${approved.count} approved, ${rejected.count} rejected`}
        />
        <Stat
          label="Rejected"
          icon={<TrendingDown size={15} aria-hidden />}
          value={String(rejected.count)}
          delta={rejected.count > 0 ? `${formatCurrency(rejected.amount, 0)} not won` : 'No rejected estimates'}
        />
      </div>

      {/* The "Addendums create estimate revisions" banner is hidden for now. To bring it back, restore this block:
      {!bannerDismissed && (
        <div
          role="note"
          className="relative mb-4 flex flex-wrap items-center gap-x-4 gap-y-3 overflow-hidden rounded-card border border-hairline bg-surface-glass px-5 py-4 shadow-card"
        >
          <span
            aria-hidden
            className="pointer-events-none absolute -top-10 -right-10 size-[140px] rounded-full bg-[radial-gradient(circle,var(--glow-a),transparent_70%)]"
          />

          <span className="relative grid size-10 shrink-0 place-items-center rounded-xl bg-[var(--brand-bg)] text-brand">
            <FileStack size={20} aria-hidden />
          </span>

          <div className="relative min-w-0 flex-1 basis-80">
            <p className="font-display text-[1.0625rem] leading-[1.25] font-[620] tracking-[-0.01em] text-white">
              Addendums create estimate revisions
            </p>
            <p className="mt-0.5 text-[13px] text-[var(--text-2)]">
              Apply revised or additional drawings to an existing estimate. Breeze.AI identifies changes, updates the
              takeoff, and creates a new revision while preserving the complete estimate history.
            </p>
          </div>

          <div className="relative flex shrink-0 items-center gap-1">
            <ButtonLink href={ROUTES.addenda} variant="secondary" size="sm" rightIcon={ArrowRight}>
              Open addendums
            </ButtonLink>
            <button
              type="button"
              aria-label="Dismiss"
              onClick={() => setBannerDismissed(true)}
              className="grid size-8 place-items-center rounded-full text-[var(--text-3)] transition-colors hover:bg-white/8 hover:text-white"
            >
              <X size={16} aria-hidden />
            </button>
          </div>
        </div>
      )}
      */}

      {view === 'board' ? (
        <BidBoard estimates={board} />
      ) : (
        <>
      <UnderlineTabs
        value={filters.status}
        onChange={(status) => applyFilters({ status: status as EstimateStatusFilter })}
        tabs={[
          { key: 'all', label: 'All', count: allCount },
          ...ESTIMATE_STATUS_FILTERS.filter((option) => option.value !== 'all').map((option) => ({
            key: option.value,
            label: option.label,
            count: statusCounts[option.value]?.count ?? 0,
          })),
        ]}
      />

      <Card padding="none" className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-hairline px-4 py-3">
          <ToolbarSearch
            value={query}
            onChange={setQuery}
            onSubmit={(value) => applyFilters({ search: value })}
            applied={filters.search !== ''}
            placeholder="Search estimates"
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
          <span className="ml-auto text-[13px] text-[var(--text-3)]">
            {meta.total} {meta.total === 1 ? 'estimate' : 'estimates'}
          </span>
        </div>

        <AnimatePresence initial={false}>
          {filterBar.isOpen && (
            <motion.div
              key="filter-bar"
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: 'auto' }}
              exit={{ opacity: 0, height: 0 }}
              transition={{ duration: MOTION.base }}
              className="overflow-hidden"
            >
              <div className="grid gap-4 border-b border-hairline bg-[var(--surface-2)] p-4 sm:grid-cols-2 xl:grid-cols-4">
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
              className="m-4"
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
            {/* Table view — xl and up, where every column fits */}
            <div className="hidden xl:block">
              <Table
                variant="lined"
                columns={columns}
                rows={rows}
                getRowId={(estimate) => estimate.id}
                caption="All client estimates"
                onRowClick={(estimate) => router.visit(routeTo.estimate(estimate.id))}
              />
            </div>

            {/* Card view — below xl */}
            <ul className="space-y-3 p-4 xl:hidden">
              {rows.map((estimate, index) => (
                <EstimateCard key={estimate.id} estimate={estimate} index={index} onDelete={requestDelete} />
              ))}
            </ul>
          </>
        )}
      </Card>

      <Pagination
        withLabels
        className="mt-5"
        page={meta.current_page}
        pageCount={meta.last_page}
        onPageChange={(page) => applyFilters({ page })}
        summary={
          meta.total === 0
            ? 'No estimates to display'
            : `Showing ${meta.from} to ${meta.to} of ${meta.total} estimates`
        }
      />
        </>
      )}

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
