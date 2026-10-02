import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { GitBranch, Plus, SearchX, Trash2, Undo2 } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
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
import { useDisclosure, usePermissions } from '@/hooks'
import type {
  Paginated,
  SelectOption,
  SharedPageProps,
  TableColumn,
  TakeoffHistoryRow,
} from '@/types'
import {
  HISTORY_STATUS_LABEL,
  HISTORY_STATUS_TONE,
  formatDate,
} from '@/utils'

interface HistoryFilters {
  search: string
  status: HistoryFilter
  client: string
  project: string
  sort: HistorySort
}

export interface HistoryProps {
  projects: Paginated<TakeoffHistoryRow>
  filters: HistoryFilters
  clients: readonly string[]
  projectOptions: readonly { id: number; name: string }[]
}

/**
 * AI Takeoff.
 *
 * Search, status/client/project filters, sort and pagination are all
 * query-string driven, so the database does the work and every view is a
 * shareable URL. Deletes are soft, which is what makes "Undo" a real restore
 * rather than a re-insert.
 */
export default function History({ projects, filters, clients, projectOptions }: HistoryProps) {
  const { can: permitted } = usePermissions()
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [pendingDelete, setPendingDelete] = useState<TakeoffHistoryRow | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const deleteDialog = useDisclosure()

  // The notice is derived from the flash rather than mirrored into state, so it
  // needs no effect and can't fall out of step with the last response.
  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed
  const canUndo = lastDeletedId !== null && Boolean(flash.warning)

  const clientOptions: SelectOption[] = [
    { label: 'All Clients', value: 'all' },
    ...clients.map((client) => ({ label: client, value: client })),
  ]

  const projectFilterOptions: SelectOption[] = [
    { label: 'All Projects', value: 'all' },
    ...projectOptions.map((project) => ({ label: project.name, value: String(project.id) })),
  ]

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

  // A restart, not a retry — `retry()` refuses once a run has finished in any
  // state, including failed, so a genuinely failed run needs the full restart.
  const handleRetry = useCallback((row: TakeoffHistoryRow) => {
    router.post(routeTo.processingRestart(row.id), {}, { preserveScroll: true })
  }, [])

  const resetFilters = useCallback(() => {
    setQuery('')
    applyFilters({ search: '', status: 'all', client: 'all', project: 'all' })
  }, [applyFilters])

  const columns: TableColumn<TakeoffHistoryRow>[] = [
    {
      key: 'drawingSet',
      header: 'Drawing Set',
      render: (row) => (
        <div className="flex items-center gap-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-panel bg-purple-400/15 text-purple-300 ring-1 ring-purple-400/40">
            <GitBranch size={17} aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="truncate font-bold text-white">
              {row.drawingName ?? 'Untitled drawing'}
            </p>
            {row.format && <p className="truncate text-xs text-white/60">{row.format}</p>}
          </div>
        </div>
      ),
    },
    {
      key: 'project',
      header: 'Project',
      render: (row) => (
        <div className="min-w-0">
          <p className="truncate font-semibold text-white">{row.name}</p>
          <p className="truncate text-xs text-white/60">{row.client}</p>
        </div>
      ),
    },
    {
      key: 'uploadedAt',
      header: 'Uploaded',
      render: (row) => (
        <span className="whitespace-nowrap text-white/90">{formatDate(row.uploadedAt)}</span>
      ),
    },
    {
      key: 'pageCount',
      header: 'Pages',
      render: (row) => <span className="text-white/90">{row.pageCount}</span>,
    },
    {
      key: 'reviewStatus',
      header: 'Review Status',
      render: (row) => (
        <StatusChip
          pill
          tone={HISTORY_STATUS_TONE[row.reviewStatus]}
          label={HISTORY_STATUS_LABEL[row.reviewStatus]}
        />
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-52',
      render: (row) => (
        <div className="flex items-center gap-2">
          <ButtonLink href={`${routeTo.drawingDetails(row.id)}?from=history`} variant="purple" size="sm">
            View
          </ButtonLink>

          {row.reviewStatus === 'failed' && (
            <Button variant="white" size="sm" onClick={() => handleRetry(row)}>
              Retry
            </Button>
          )}

          <MoreMenu
            variant="minimal"
            ariaLabel={`More actions for ${row.name}`}
            items={[
              {
                label: 'Delete',
                icon: Trash2,
                destructive: true,
                onSelect: () => requestDelete(row),
              },
            ]}
          />
        </div>
      ),
    },
  ]

  const rows = projects.data
  const { meta } = projects

  return (
    <PageTransition>
      <Head title="AI Takeoff" />

      {/* ============================================= History panel ========= */}
      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <header className="flex flex-col gap-4 border-b border-hairline grad-ocean-soft px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold text-white sm:text-3xl">AI Takeoff</h1>
            <p className="mt-1 text-md text-white/90">
              Automatically analyze electrical drawings and generate detailed takeoff data for faster, more accurate estimating
            </p>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center lg:shrink-0">
            <SearchBox
              value={query}
              onValueChange={setQuery}
              onSearch={(value) => applyFilters({ search: value })}
              placeholder="Search drawings or projects..."
              containerClassName="sm:w-72"
              aria-label="Search drawings or projects"
            />
            {permitted('takeoff.create') && (
              <ButtonLink href={ROUTES.upload} variant="dark" leftIcon={Plus}>
                Upload Drawings
              </ButtonLink>
            )}
          </div>
        </header>

        <div className="p-5 sm:p-6">
          {/* Filters + sort */}
          <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:flex xl:flex-wrap">
              <SelectField
                aria-label="Filter by status"
                options={HISTORY_FILTERS}
                value={filters.status}
                onChange={(event) =>
                  applyFilters({ status: event.target.value as HistoryFilter })
                }
                className="xl:w-44"
              />
              <SelectField
                aria-label="Filter by project"
                options={projectFilterOptions}
                value={filters.project}
                onChange={(event) => applyFilters({ project: event.target.value })}
                className="xl:w-44"
              />
              <SelectField
                aria-label="Filter by client"
                options={clientOptions}
                value={filters.client}
                onChange={(event) => applyFilters({ client: event.target.value })}
                className="xl:w-44"
              />
            </div>

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
              title="No drawing sets found"
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
                  caption="Previous AI takeoff drawing sets"
                />
              </div>

              {/* Card view — below xl, so status and the actions stay
                  reachable without scrolling the table sideways. */}
              <ul className="space-y-3 xl:hidden">
                {rows.map((row, rowIndex) => (
                  <ProjectHistoryCard
                    key={row.id}
                    project={row}
                    index={rowIndex}
                    onDelete={requestDelete}
                    onRetry={handleRetry}
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
                ? 'No drawing sets to display'
                : `Showing ${rows.length} of ${meta.total} drawing sets`
            }
          />
        </div>
      </section>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete “${pendingDelete?.name ?? ''}”?`}
        description="The project is removed from your history. You can undo this straight after."
        confirmLabel="Delete"
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
