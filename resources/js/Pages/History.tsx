import { useCallback, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Plus, SearchX, UploadCloud, Undo2 } from 'lucide-react'
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Pagination,
  SelectField,
  ToolbarSearch,
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
  TakeoffHistoryRow,
} from '@/types'

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

  const rows = projects.data
  const { meta } = projects

  return (
    <PageTransition>
      <Head title="AI Takeoff" />

      {/* ================================================== Hero =========== */}
      <section className="blueprint mb-5 grid items-center gap-8 p-6 sm:p-9 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
        <span className="bp-corner tl" aria-hidden />
        <span className="bp-corner br" aria-hidden />

        <div className="min-w-0">
          <span className="bp-eyebrow">AI Takeoff</span>
          <h1 className="mt-3.5 mb-3 max-w-[14ch] text-[clamp(1.875rem,3.4vw,2.75rem)] leading-[1.1] font-semibold">
            Count a whole drawing set <em className="auth-grad-text">in minutes.</em>
          </h1>
          <p className="max-w-[46ch] text-[15px] leading-relaxed text-[#c3cfea]">
            Automatically analyze electrical drawings and generate detailed takeoff data for faster, more accurate
            estimating
          </p>
          <div className="mt-6 text-[13px] text-[#c3cfea]">
            <b className="block font-display text-[1.625rem] leading-[1.1] font-[650] tracking-[-0.02em] text-white">
              {meta.total}
            </b>
            drawing {meta.total === 1 ? 'set' : 'sets'} on record
          </div>
        </div>

        {permitted('takeoff.create') && (
          <Link
            href={ROUTES.upload}
            aria-label="Upload a PDF drawing set"
            className="group relative flex min-h-[250px] flex-col items-center justify-center gap-2.5 rounded-[18px] border-[1.5px] border-dashed border-[rgb(126_170_255/0.45)] bg-gradient-to-b from-white/6 to-white/2 p-6 text-center transition-[border-color,background-color,box-shadow] duration-200 hover:border-[#3ee6ff] hover:bg-[rgb(62_230_255/0.07)] hover:shadow-[0_0_0_6px_rgb(62_230_255/0.06),0_20px_50px_-20px_rgb(62_230_255/0.45)] focus-visible:border-[#3ee6ff] focus-visible:outline-none"
          >
            <span className="mb-1.5 grid size-16 place-items-center rounded-[20px] bg-brand text-brand-ink shadow-[0_16px_40px_-12px_rgb(47_91_255/0.9),inset_0_1px_0_rgb(255_255_255/0.3)] [background-image:var(--brand-grad)]">
              <UploadCloud size={28} aria-hidden />
            </span>
            <span className="font-display text-[19px] font-[620] text-white">Upload a drawing set</span>
            <span className="text-[13px] text-[#c3cfea]">Click to choose a PDF</span>
            <span className="mt-2 rounded-md border border-[rgb(126_170_255/0.25)] px-2 py-[3px] font-mono text-[11px] text-[#c3cfea]">
              E-sheets, power plans, RCPs, panel schedules
            </span>
          </Link>
        )}
      </section>

      {/* ================================================ Toolbar ========== */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <ToolbarSearch
          value={query}
          onChange={setQuery}
          onSubmit={(value) => applyFilters({ search: value })}
          applied={filters.search !== ''}
          placeholder="Search drawings or projects"
        />
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:flex xl:flex-wrap">
          <SelectField
            aria-label="Filter by status"
            options={HISTORY_FILTERS}
            value={filters.status}
            onChange={(event) => applyFilters({ status: event.target.value as HistoryFilter })}
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

        <div className="flex items-center gap-2.5 sm:ml-auto">
          <label htmlFor="history-sort" className="text-[13px] font-medium whitespace-nowrap text-[var(--text-2)]">
            Sort by:
          </label>
          <SelectField
            id="history-sort"
            options={HISTORY_SORT_OPTIONS}
            value={filters.sort}
            onChange={(event) => applyFilters({ sort: event.target.value as HistorySort })}
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
            className="mb-4"
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

      {/* ============================================ Drawing sets ========= */}
      <div className="mt-2 mb-3.5 flex items-baseline justify-between gap-3 px-0.5">
        <h2 className="font-display text-[1.1875rem] font-[620]">Your drawing sets</h2>
        <span className="text-[13px] text-[var(--text-3)]">
          {meta.total} {meta.total === 1 ? 'takeoff' : 'takeoffs'}
        </span>
      </div>

      {rows.length === 0 ? (
        <Card padding="lg">
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
        </Card>
      ) : (
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-4">
          {rows.map((row, rowIndex) => (
            <ProjectHistoryCard
              key={row.id}
              project={row}
              index={rowIndex}
              onDelete={requestDelete}
              onRetry={handleRetry}
            />
          ))}

          {permitted('takeoff.create') && (
            <li>
              <Link
                href={ROUTES.upload}
                className="flex h-full min-h-[200px] flex-col items-center justify-center gap-2 rounded-card border-[1.5px] border-dashed border-hairline-strong bg-surface-glass/50 p-6 text-center transition-colors hover:bg-[var(--brand-bg)] hover:text-brand"
              >
                <span className="grid size-[52px] place-items-center rounded-2xl bg-[var(--brand-bg)] text-brand">
                  <Plus size={24} aria-hidden />
                </span>
                <b className="font-semibold text-white">Start a new takeoff</b>
                <span className="text-xs text-[var(--text-3)]">Upload a PDF drawing set</span>
              </Link>
            </li>
          )}
        </ul>
      )}

      <Pagination
        withLabels
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
