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
  AvatarStack,
  Badge,
  Button,
  ButtonLink,
  Card,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  MoreMenu,
  Pagination,
  SelectField,
  Table,
  ToolbarSearch,
} from '@/components/common'
import { JobCard } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
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
import { useDisclosure, usePermissions } from '@/hooks'
import type {
  Job,
  Paginated,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { JOB_STATUS_LABEL, JOB_STATUS_TONE, formatCalendarDate, formatCurrency } from '@/utils'

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
  const { can: permitted } = usePermissions()
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
          <span
            className="mt-0.5 flex items-center gap-2 text-[13px] text-[var(--text-3)]"
            title={job.location ?? undefined}
          >
            {[job.projectName, job.client].filter(Boolean).join(' · ') || '—'}
            {job.isArchived && (
              <Badge tone="warning" size="sm">
                Archived
              </Badge>
            )}
          </span>
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (job) => (
        <Badge tone={JOB_STATUS_TONE[job.status]}>{JOB_STATUS_LABEL[job.status]}</Badge>
      ),
    },
    {
      key: 'progress',
      header: 'Progress',
      render: (job) =>
        job.progress === null ? (
          <span className="text-[var(--text-3)]">—</span>
        ) : (
          <span className="flex min-w-32 items-center gap-2.5">
            <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-[var(--surface-3)]" aria-hidden>
              <span
                className={job.progress === 100 ? 'block h-full rounded-full bg-status-success' : 'block h-full rounded-full bg-brand'}
                style={{ width: `${job.progress}%` }}
              />
            </span>
            <span className="font-mono text-[13px] tabular-nums text-white">{job.progress}%</span>
          </span>
        ),
    },
    {
      key: 'crew',
      header: 'Crew',
      render: (job) => (
        <AvatarStack people={job.crew ?? []} />
      ),
    },
    {
      key: 'start',
      header: 'Start',
      render: (job) => (
        <span className="whitespace-nowrap">{job.startDate ? formatCalendarDate(job.startDate) : '—'}</span>
      ),
    },
    {
      key: 'end',
      header: 'End',
      render: (job) => (
        <span className="whitespace-nowrap">{job.endDate ? formatCalendarDate(job.endDate) : '—'}</span>
      ),
    },
    {
      key: 'openTasks',
      header: 'Open tasks',
      align: 'right',
      render: (job) => <span className="tabular-nums">{job.openTasks}</span>,
    },
    {
      key: 'value',
      header: 'Value',
      align: 'right',
      render: (job) => (
        <span className="font-semibold whitespace-nowrap tabular-nums text-white">
          {job.budget === null ? '—' : formatCurrency(job.budget, 0)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      width: 'w-12',
      cellClassName: 'pl-1',
      render: (job) => (
        <MoreMenu
          ariaLabel={`Actions for ${job.name}`}
          variant="minimal"
          items={[
            { label: 'View', icon: Eye, onSelect: () => router.visit(routeTo.job(job.id)) },
            ...(!job.isLocked && permitted('jobs.edit')
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
            ...(!job.isLocked && permitted('jobs.delete')
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

      <PageHeader
        title="Jobs"
        subtitle="Manage active jobs, track progress, and keep project details, field activity, and job performance organized in one place"
        actions={
          <>
            <Button
              variant={isFiltersOpen ? 'primary' : 'secondary'}
              leftIcon={SlidersHorizontal}
              aria-expanded={isFiltersOpen}
              onClick={() => applyFilters({ panel: isFiltersOpen ? '' : 'open' })}
            >
              Filters
            </Button>
            {permitted('jobs.create') && (
              <ButtonLink href={ROUTES.jobCreate} leftIcon={Plus}>
                Create job
              </ButtonLink>
            )}
          </>
        }
      />

      <Alert tone="info" className="mb-4 [&_p]:text-md [&_div]:text-sm" title="Office & Field, Fully Connected">
        Keep your office and field teams aligned with real-time updates to job details, tasks, schedules, and project changes across web and mobile.
      </Alert>

      <Card padding="none" className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b border-hairline px-4 py-3">
          <ToolbarSearch
            value={query}
            onChange={setQuery}
            onSubmit={(value) => applyFilters({ search: value })}
            applied={filters.search !== ''}
            placeholder="Search jobs, projects, clients"
          />
          <div className="ml-auto flex items-center gap-2.5">
            <label htmlFor="job-sort" className="text-[13px] font-medium whitespace-nowrap text-[var(--text-2)]">
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
          <span className="text-[13px] text-[var(--text-3)]">
            {meta.total} {meta.total === 1 ? 'job' : 'jobs'}
          </span>
        </div>

        <div>
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
                <div className="grid gap-4 border-b border-hairline bg-[var(--surface-2)] p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
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

          {/* Bulk action bar */}
          <AnimatePresence initial={false}>
            {selectedOnPage.length > 0 && (
              <motion.div
                key="bulk"
                initial={{ opacity: 0, y: -8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -8 }}
                className="flex flex-wrap items-center gap-3 border-b border-hairline bg-[var(--brand-bg)] px-4 py-3"
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
                className="m-4"
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
                  variant="lined"
                  columns={columns}
                  rows={rows}
                  getRowId={(job) => job.id}
                  onRowClick={(job) => router.visit(routeTo.job(job.id))}
                  caption="All electrical jobs"
                />
              </div>

              {/* Card view — below xl */}
              <ul className="space-y-3 p-4 xl:hidden">
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

        </div>
      </Card>

      <Pagination
        withLabels
        className="mt-5"
        page={meta.current_page}
        pageCount={meta.last_page}
        onPageChange={(page) => applyFilters({ page })}
        summary={
          meta.total === 0
            ? 'No jobs to display'
            : `Showing ${meta.from}–${meta.to} of ${meta.total} jobs`
        }
      />

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
