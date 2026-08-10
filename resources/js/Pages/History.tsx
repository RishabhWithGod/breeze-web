import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { FileText, Plus, SearchX, Undo2 } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  FilterTabs,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
import { DashboardPanel, IconListRow } from '@/components/dashboard'
import { ProjectHistoryCard } from '@/components/history'
import { appLayout, PageTransition } from '@/components/layout'
import {
  HISTORY_FILTERS,
  HISTORY_SORT_OPTIONS,
  ROUTES,
  routeTo,
  type HistoryFilter,
  type HistorySort,
} from '@/constants'
import { useDebouncedValue, useDisclosure } from '@/hooks'
import type {
  FeedItem,
  Paginated,
  SharedPageProps,
  TableColumn,
  TakeoffHistoryRow,
} from '@/types'
import {
  TAKEOFF_STATUS_LABEL,
  TAKEOFF_STATUS_TONE,
  formatDate,
  formatNumber,
} from '@/utils'

interface HistoryFilters {
  search: string
  status: HistoryFilter
  sort: HistorySort
}

export interface HistoryProps {
  projects: Paginated<TakeoffHistoryRow>
  filters: HistoryFilters
  activity: readonly FeedItem[]
}

/**
 * AI Takeoff History.
 *
 * Search, status filter, sort and pagination are query-string driven, so the
 * database does the work and every view is a shareable URL. Deletes are soft,
 * which is what makes "Undo" a real restore rather than a re-insert.
 */
export default function History({ projects, filters, activity }: HistoryProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [pendingDelete, setPendingDelete] = useState<TakeoffHistoryRow | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()
  const debouncedQuery = useDebouncedValue(query)

  // The notice is derived from the flash rather than mirrored into state, so it
  // needs no effect and can't fall out of step with the last response.
  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed
  const canUndo = lastDeletedId !== null && Boolean(flash.warning)

  /** Reload with changed filters, leaving scroll position and focus alone. */
  const applyFilters = useCallback(
    (changes: Partial<HistoryFilters & { page: number }>) => {
      router.get(
        ROUTES.history,
        { ...filters, ...changes },
        { preserveState: true, preserveScroll: true, replace: true },
      )
    },
    [filters],
  )

  // Search is debounced so typing doesn't fire a request per keystroke.
  useEffect(() => {
    if (debouncedQuery === filters.search) return
    applyFilters({ search: debouncedQuery })
  }, [debouncedQuery, filters.search, applyFilters])

  const handleDeleteConfirmed = useCallback(() => {
    if (!pendingDelete) return

    const { id } = pendingDelete

    router.delete(routeTo.takeoff(id), {
      preserveScroll: true,
      onSuccess: () => setLastDeletedId(id),
    })

    setPendingDelete(null)
    deleteDialog.close()
  }, [pendingDelete, deleteDialog])

  const handleUndo = useCallback(() => {
    if (lastDeletedId === null) return

    router.post(routeTo.takeoffRestore(lastDeletedId), {}, { preserveScroll: true })
    setLastDeletedId(null)
  }, [lastDeletedId])

  const requestDelete = useCallback(
    (row: TakeoffHistoryRow) => {
      setPendingDelete(row)
      deleteDialog.open()
    },
    [deleteDialog],
  )

  const resetFilters = useCallback(() => {
    setQuery('')
    applyFilters({ search: '', status: 'all' })
  }, [applyFilters])

  const columns: TableColumn<TakeoffHistoryRow>[] = [
    {
      key: 'name',
      header: 'Project Name',
      render: (row) => (
        <div className="flex items-center gap-3">
          <span className="grid size-8 shrink-0 place-items-center rounded-sm bg-ocean-600 text-white">
            <FileText size={15} aria-hidden />
          </span>
          <span className="font-bold text-white">{row.name}</span>
        </div>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      render: (row) => (
        <span className="whitespace-nowrap text-white/90">{formatDate(row.date)}</span>
      ),
    },
    {
      key: 'client',
      header: 'Client',
      render: (row) => <span className="text-white/90">{row.client}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusChip
          hideDot
          tone={TAKEOFF_STATUS_TONE[row.status]}
          label={TAKEOFF_STATUS_LABEL[row.status]}
        />
      ),
    },
    {
      key: 'items',
      header: 'Items',
      width: 'w-24',
      render: (row) => (
        <span className="tabular-nums text-white">{formatNumber(row.items)}</span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-44',
      // Text-only buttons, matching the reference's compact action cluster.
      render: (row) => (
        <div className="flex items-center gap-2">
          <ButtonLink href={routeTo.drawingDetails(row.id)} size="sm">
            View
          </ButtonLink>
          <Button
            variant="white"
            size="sm"
            className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
            onClick={() => requestDelete(row)}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ]

  const rows = projects.data
  const { meta } = projects

  return (
    <PageTransition>
      <Head title="AI Takeoff History" />

      {/* ============================================= History panel ========= */}
      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <header className="flex flex-col gap-4 border-b border-hairline grad-ocean-soft px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold text-white sm:text-3xl">
              AI Takeoff History
            </h1>
            <p className="mt-1 text-md text-white/90">
              View and manage your previous takeoff projects
            </p>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center lg:shrink-0">
            <SearchBox
              value={query}
              onValueChange={setQuery}
              placeholder="Search projects..."
              containerClassName="sm:w-72"
              aria-label="Search projects"
            />
            <ButtonLink href={ROUTES.upload} variant="dark" leftIcon={Plus}>
              New Takeoff
            </ButtonLink>
          </div>
        </header>

        <div className="p-5 sm:p-6">
          {/* Filters + sort */}
          <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <FilterTabs
              solid
              options={HISTORY_FILTERS}
              value={filters.status}
              onChange={(status) => applyFilters({ status })}
            />

            <div className="flex items-center gap-3">
              <label
                htmlFor="history-sort"
                className="text-md font-medium whitespace-nowrap text-white/90"
              >
                Sort by:
              </label>
              <SelectField
                id="history-sort"
                options={HISTORY_SORT_OPTIONS}
                value={filters.sort}
                onChange={(event) =>
                  applyFilters({ sort: event.target.value as HistorySort })
                }
                className="w-48"
              />
            </div>
          </div>

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
              title="No projects found"
              description="No takeoffs match your current filters. Try another status or clear the search."
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  Reset filters
                </Button>
              }
            />
          ) : (
            <>
              {/* Table view — xl and up, where all six columns fit the panel */}
              <div className="hidden xl:block">
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  columns={columns}
                  rows={rows}
                  getRowId={(row) => row.id}
                  caption="Previous AI takeoff projects"
                />
              </div>

              {/* Card view — below xl, so status, items and the actions stay
                  reachable without scrolling the table sideways. */}
              <ul className="space-y-3 xl:hidden">
                {rows.map((row, rowIndex) => (
                  <ProjectHistoryCard
                    key={row.id}
                    project={row}
                    index={rowIndex}
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
                ? 'No projects to display'
                : `Showing ${rows.length} of ${meta.total} projects`
            }
          />
        </div>
      </section>

      {/* ============================================ Recent activity ======== */}
      <DashboardPanel title="Recent Activity" className="mt-6" index={1}>
        <ul className="space-y-4">
          {activity.map((row, index) => (
            <IconListRow key={row.id} row={row} index={index} />
          ))}
        </ul>
      </DashboardPanel>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete “${pendingDelete?.name ?? ''}”?`}
        description="The project is removed from your history. You can undo this straight after."
        confirmLabel="Delete project"
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

History.layout = appLayout
