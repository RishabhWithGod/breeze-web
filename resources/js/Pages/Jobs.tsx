import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Eye,
  PencilLine,
  Plus,
  Receipt,
  SearchX,
  SlidersHorizontal,
  Trash2,
  Undo2,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
} from '@/components/common'
import { JobCard } from '@/components/jobs'
import { appLayout, PageTransition } from '@/components/layout'
import {
  JOB_SORT_OPTIONS,
  JOB_STATUS_FILTERS,
  JOB_STATUS_OPTIONS,
  JOB_TYPE_FILTERS,
  MOTION,
  ROUTES,
  routeTo,
  type JobSort,
  type JobStatusFilter,
  type JobTypeFilter,
  type JobView,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  Job,
  Paginated,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { JOB_STATUS_LABEL, JOB_STATUS_TONE, formatCalendarDate } from '@/utils'

const STATUS_FILTER_OPTIONS = JOB_STATUS_FILTERS.map((option) => ({
  label: option.label,
  value: option.value,
}))

const TYPE_FILTER_OPTIONS = JOB_TYPE_FILTERS.map((option) => ({
  label: option.label,
  value: option.value,
}))

const SORT_OPTIONS = JOB_SORT_OPTIONS.map((option) => ({
  label: option.label,
  value: option.value,
}))

const BULK_STATUS_OPTIONS = [
  { label: 'Set status to…', value: '' },
  ...JOB_STATUS_OPTIONS,
]

interface JobFilters {
  search: string
  status: JobStatusFilter
  foreman: string
  type: JobTypeFilter
  sort: JobSort
  view: JobView
  /** 'open' while the filter drawer is expanded. */
  panel: string
}

export interface JobsProps {
  jobs: Paginated<Job>
  filters: JobFilters
  /** Role-only — whether this user may raise an invoice at all. Per-job "already invoiced" is `job.hasInvoice`. */
  canCreateInvoice: boolean
}

/**
 * Jobs — every electrical project in one place.
 *
 * Search, filters, sorting and pagination all run in the database against
 * query-string state. Row actions and bulk actions go through JobController.
 */
export default function Jobs({ jobs, filters, canCreateInvoice }: JobsProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [selected, setSelected] = useState<number[]>([])
  const [pendingDelete, setPendingDelete] = useState<Job | null>(null)
  const [lastDeletedId, setLastDeletedId] = useState<number | null>(null)
  const [dismissed, setDismissed] = useState<string | null>(null)

  const deleteDialog = useDisclosure()

  /**
   * The drawer's open state lives in the query string. Every filter change is a
   * server visit, and Inertia does not carry local component state across one —
   * so keeping it in the URL is what stops the drawer snapping shut on each
   * change (and makes a filtered view shareable).
   */
  const isFiltersOpen = filters.panel === 'open'

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed
  // A delete made on the detail screen arrives as a flashed id; one made here
  // sets local state. Either one makes Undo available.
  const restorableId = lastDeletedId ?? flash.restoreJobId
  const canUndo = restorableId !== null && Boolean(flash.warning)

  /**
   * Merges `changes` into the *current* query string rather than into a props
   * snapshot, so an in-flight request can never be re-sent with stale filters.
   */
  const applyFilters = useCallback(
    (changes: Partial<JobFilters & { page: number }>) => {
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
        queryString ? `${ROUTES.jobs}?${queryString}` : ROUTES.jobs,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
      )
    },
    [],
  )

  const rows = jobs.data
  const { meta } = jobs

  /**
   * Selection is derived against the rows actually on screen rather than reset
   * in an effect, so ids left over from a previous page or filter simply stop
   * counting instead of triggering a cascading render.
   */
  const pageIds = rows.map((job) => job.id)
  // A completed job can't be bulk-deleted or bulk-restatused (the server
  // skips it either way), so it's left out of "select all" and its own
  // checkbox is disabled — nothing to gain from selecting it.
  const selectablePageIds = rows.filter((job) => !job.isLocked).map((job) => job.id)
  const selectedOnPage = selected.filter((id) => pageIds.includes(id))
  const allOnPageSelected = selectablePageIds.length > 0 && selectedOnPage.length === selectablePageIds.length

  const toggleAll = () => {
    setSelected(allOnPageSelected ? [] : selectablePageIds)
  }

  const toggleOne = (id: number) => {
    setSelected((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    )
  }

  const runBulk = (action: 'delete' | 'status', status?: string) => {
    if (selectedOnPage.length === 0) return

    router.post(
      routeTo.jobsBulk,
      { ids: selectedOnPage, action, ...(status ? { status } : {}) },
      { preserveScroll: true, onSuccess: () => setSelected([]) },
    )
  }

  const requestDelete = useCallback(
    (job: Job) => {
      setPendingDelete(job)
      deleteDialog.open()
    },
    [deleteDialog],
  )

  const handleDeleteConfirmed = useCallback(() => {
    if (!pendingDelete) return

    const { id } = pendingDelete

    router.delete(routeTo.job(id), {
      preserveScroll: true,
      onSuccess: () => setLastDeletedId(id),
    })

    setPendingDelete(null)
    deleteDialog.close()
  }, [pendingDelete, deleteDialog])

  const handleUndo = useCallback(() => {
    if (restorableId === null) return

    router.post(routeTo.jobRestore(restorableId), {}, { preserveScroll: true })
    setLastDeletedId(null)
  }, [restorableId])

  const resetFilters = useCallback(() => {
    setQuery('')
    router.get(ROUTES.jobs, {}, { preserveState: true, preserveScroll: true, replace: true })
  }, [])

  const columns: TableColumn<Job>[] = [
    {
      key: 'select',
      // The control belongs to the column it governs, in the header row that
      // names the columns — not on a line of its own above the table.
      header: (
        <Checkbox
          id="select-all-jobs"
          label=""
          aria-label={allOnPageSelected ? 'Clear page selection' : 'Select all on page'}
          checked={allOnPageSelected}
          onChange={toggleAll}
        />
      ),
      width: 'w-10',
      render: (job) => (
        <Checkbox
          id={`select-job-${job.id}`}
          label=""
          aria-label={
            job.isLocked ? `${job.name} is completed and can't be bulk-changed` : `Select ${job.name}`
          }
          disabled={job.isLocked}
          checked={selected.includes(job.id)}
          onChange={() => toggleOne(job.id)}
        />
      ),
    },
    {
      key: 'name',
      header: 'Job',
      render: (job) => (
        <span className="min-w-0">
          <a
            href={routeTo.job(job.id)}
            className="font-bold text-white transition-colors hover:text-brand"
            onClick={(event) => {
              event.preventDefault()
              router.visit(routeTo.job(job.id))
            }}
          >
            {job.name}
          </a>
          <span className="mt-0.5 flex items-center gap-2 text-xs text-white/75">
            {job.location ?? '—'}
            {job.isArchived && (
              <Badge tone="warning" className="py-0 text-2xs">
                Archived
              </Badge>
            )}
          </span>
        </span>
      ),
    },
    {
      key: 'project',
      header: 'Project',
      render: (job) => <span className="text-white">{job.projectName ?? '—'}</span>,
    },
    {
      key: 'client',
      header: 'Client',
      render: (job) => <span className="text-white">{job.client ?? '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (job) => (
        <StatusChip
          pill
          tone={JOB_STATUS_TONE[job.status]}
          label={JOB_STATUS_LABEL[job.status]}
        />
      ),
    },
    {
      key: 'progress',
      header: 'Progress',
      render: (job) =>
        job.progress === null ? (
          <span className="text-white/45">—</span>
        ) : (
          <span className="flex items-center gap-3">
            <span className="h-2 w-24 overflow-hidden rounded-full bg-white/15" aria-hidden>
              <span
                className="block h-full rounded-full bg-linear-to-r from-status-success/40 to-status-success"
                style={{ width: `${job.progress}%` }}
              />
            </span>
            <span className="tabular-nums text-white">{job.progress}%</span>
          </span>
        ),
    },
    {
      key: 'crew',
      header: 'Crew',
      render: (job) => <span className="tabular-nums text-white">{job.crewCount}</span>,
    },
    {
      key: 'schedule',
      header: 'Schedule',
      render: (job) => {
        if (!job.startDate && !job.endDate) return <span className="text-white/45">—</span>

        const start = job.startDate ? formatCalendarDate(job.startDate) : null
        const end = job.endDate ? formatCalendarDate(job.endDate) : null

        // One day, or one end only: nothing to span, so no dash.
        if (start === end || !start || !end) {
          return <span className="whitespace-nowrap text-white/90">{start ?? end}</span>
        }

        return (
          <span className="text-white/90">
            <span className="whitespace-nowrap">{start} –</span>
            <br />
            <span className="whitespace-nowrap">{end}</span>
          </span>
        )
      },
    },
    {
      key: 'openTasks',
      header: 'Open Tasks',
      align: 'center',
      render: (job) => <span className="tabular-nums text-white">{job.openTasks}</span>,
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-24',
      render: (job) => (
        <MoreMenu
          ariaLabel={`Actions for ${job.name}`}
          items={[
            { label: 'View', icon: Eye, onSelect: () => router.visit(routeTo.job(job.id)) },
            ...(!job.isLocked
              ? [
                  {
                    label: 'Edit',
                    icon: PencilLine,
                    onSelect: () => router.visit(routeTo.jobEdit(job.id)),
                  },
                ]
              : []),
            /*
              Only once a job is completed — an in-progress job has nothing
              final to bill yet. Already invoiced opens that invoice instead of
              starting a second one; enforced again server-side, not only here.
            */
            ...(job.isLocked && canCreateInvoice
              ? [
                  {
                    label: job.hasInvoice ? 'View invoice' : 'Create invoice',
                    icon: Receipt,
                    onSelect: () =>
                      router.visit(
                        job.hasInvoice && job.invoiceId
                          ? routeTo.invoice(job.invoiceId)
                          : routeTo.invoiceCreateForJob(job.id),
                      ),
                  },
                ]
              : []),
            ...(!job.isLocked
              ? [
                  {
                    label: 'Delete',
                    icon: Trash2,
                    destructive: true,
                    onSelect: () => requestDelete(job),
                  },
                ]
              : []),
          ]}
        />
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Jobs" />

      {/* ================================================ Jobs panel ========= */}
      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <header className="flex flex-col gap-4 border-b border-hairline grad-ocean-soft px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold text-white sm:text-3xl">Jobs</h1>
            <p className="mt-1 text-sm text-white/85">
              Manage active jobs, track progress, and keep project details, field activity, and job performance organized in one place
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3 lg:shrink-0">
            <SearchBox
              id="job-search"
              value={query}
              onValueChange={setQuery}
              onSearch={(value) => applyFilters({ search: value })}
              placeholder="Search jobs, projects, clients..."
              aria-label="Search jobs"
              containerClassName="w-full sm:w-72"
            />
            <Button
              variant={isFiltersOpen ? 'primary' : 'white'}
              leftIcon={SlidersHorizontal}
              aria-expanded={isFiltersOpen}
              onClick={() => applyFilters({ panel: isFiltersOpen ? '' : 'open' })}
            >
              Filters
            </Button>
            <ButtonLink href={ROUTES.jobCreate} variant="dark" leftIcon={Plus}>
              Create Job
            </ButtonLink>
          </div>
        </header>

        <div className="p-5 sm:p-6">
          <Alert tone="info" className="mb-5 [&_p]:text-md [&_div]:text-sm" title="Office & Field, Fully Connected">
            Keep your office and field teams aligned with real-time updates to job details, tasks, schedules, and project changes across web and mobile.
          </Alert>

          <AnimatePresence initial={false}>
            {isFiltersOpen && (
              <motion.div
                key="filters"
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                exit={{ opacity: 0, height: 0 }}
                transition={{ duration: MOTION.base }}
                className="overflow-hidden"
              >
                <div className="mb-6 grid gap-4 rounded-panel border border-hairline bg-white/4 p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
                  <SelectField
                    id="job-status-filter"
                    label="Status"
                    options={STATUS_FILTER_OPTIONS}
                    value={filters.status}
                    onChange={(event) =>
                      applyFilters({ status: event.target.value as JobStatusFilter })
                    }
                  />
                  <SelectField
                    id="job-type-filter"
                    label="Type"
                    options={TYPE_FILTER_OPTIONS}
                    value={filters.type}
                    onChange={(event) =>
                      applyFilters({ type: event.target.value as JobTypeFilter })
                    }
                  />
                  <Button variant="white" onClick={resetFilters}>
                    Reset
                  </Button>
                </div>
              </motion.div>
            )}
          </AnimatePresence>

          {/* The dropdown is wrapped in its own sized container rather than
              sized through `SelectField`'s `className`, which narrows the
              inner `<select>` and leaves the chevron floating at the wrapper's
              full width. */}
          <div className="mb-5 flex items-center justify-end gap-3">
            <label
              htmlFor="job-sort"
              className="text-md font-medium whitespace-nowrap text-white/90"
            >
              Sort by:
            </label>
            <div className="w-48">
              <SelectField
                id="job-sort"
                options={SORT_OPTIONS}
                value={filters.sort}
                onChange={(event) => applyFilters({ sort: event.target.value as JobSort })}
              />
            </div>
          </div>

          {/* Bulk action bar */}
          <AnimatePresence initial={false}>
            {selectedOnPage.length > 0 && (
              <motion.div
                key="bulk"
                initial={{ opacity: 0, y: -8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -8 }}
                className="mb-5 flex flex-wrap items-center gap-3 rounded-panel border border-brand/40 bg-brand/10 p-4"
              >
                <p className="text-md font-medium text-white">
                  {selectedOnPage.length} selected
                </p>

                <SelectField
                  id="bulk-status"
                  options={BULK_STATUS_OPTIONS}
                  value=""
                  className="w-48"
                  onChange={(event) => {
                    if (event.target.value) runBulk('status', event.target.value)
                  }}
                />

                <Button
                  variant="danger"
                  size="sm"
                  leftIcon={Trash2}
                  onClick={() => runBulk('delete')}
                >
                  Delete
                </Button>

                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-auto"
                  onClick={() => setSelected([])}
                >
                  Clear
                </Button>
              </motion.div>
            )}
          </AnimatePresence>

          {/* Action feedback */}
          <AnimatePresence initial={false}>
            {notice && (
              <Alert
                key={notice}
                tone={flash.warning ? 'warning' : 'success'}
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
              title="No jobs found"
              description="No jobs match your current filters. Try another status or clear the search."
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  Reset filters
                </Button>
              }
            />
          ) : (
            <>
              {/* Table view — xl and up */}
              <div className="hidden xl:block">
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  className="text-sm [&_th]:text-sm [&_td]:text-sm [&_td_span]:text-sm"
                  columns={columns}
                  rows={rows}
                  getRowId={(job) => job.id}
                  onRowClick={(job) => router.visit(routeTo.job(job.id))}
                  caption="All electrical jobs"
                />
              </div>

              {/* Card view — below xl */}
              <ul className="space-y-3 xl:hidden">
                {rows.map((job, index) => (
                  <JobCard
                    key={job.id}
                    job={job}
                    index={index}
                    onView={() => router.visit(routeTo.job(job.id))}
                    onDelete={requestDelete}
                    onCreateInvoice={(row) =>
                      router.visit(
                        row.hasInvoice && row.invoiceId
                          ? routeTo.invoice(row.invoiceId)
                          : routeTo.invoiceCreateForJob(row.id),
                      )
                    }
                    canCreateInvoice={canCreateInvoice}
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
                ? 'No jobs to display'
                : `Showing ${meta.from}–${meta.to} of ${meta.total} jobs`
            }
          />
        </div>
      </section>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title={`Delete “${pendingDelete?.name ?? ''}”?`}
        description="The job is removed from your list. You can undo this straight after."
        confirmLabel="Delete job"
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

Jobs.layout = appLayout
