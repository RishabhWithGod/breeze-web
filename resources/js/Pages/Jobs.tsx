import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  Archive,
  ArchiveRestore,
  Copy,
  Eye,
  PencilLine,
  Plus,
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
  FilterTabs,
  IconButton,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  StatusDot,
  Table,
} from '@/components/common'
import { DashboardPanel, IconListRow } from '@/components/dashboard'
import { ForemanBadge, JobCard } from '@/components/jobs'
import { appLayout, PageTransition } from '@/components/layout'
import {
  JOB_SORT_OPTIONS,
  JOB_STATUS_FILTERS,
  JOB_STATUS_OPTIONS,
  JOB_TYPE_FILTERS,
  JOB_VIEW_OPTIONS,
  MOTION,
  ROUTES,
  routeTo,
  type JobSort,
  type JobStatusFilter,
  type JobTypeFilter,
  type JobView,
} from '@/constants'
import { useDebouncedValue, useDisclosure } from '@/hooks'
import type {
  FeedItem,
  Job,
  JobForeman,
  Paginated,
  SharedPageProps,
  TableColumn,
} from '@/types'
import { JOB_STATUS_LABEL, JOB_STATUS_TONE, formatCurrency, formatDate } from '@/utils'

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
  foremen: readonly JobForeman[]
  counts: { active: number; archived: number }
  activity: readonly FeedItem[]
}

/**
 * Jobs — every electrical project in one place.
 *
 * Search, filters, sorting and pagination all run in the database against
 * query-string state. Row actions and bulk actions go through JobController.
 */
export default function Jobs({ jobs, filters, foremen, counts, activity }: JobsProps) {
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
  const debouncedQuery = useDebouncedValue(query)

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed
  // A delete made on the detail screen arrives as a flashed id; one made here
  // sets local state. Either one makes Undo available.
  const restorableId = lastDeletedId ?? flash.restoreJobId
  const canUndo = restorableId !== null && Boolean(flash.warning)

  const foremanOptions = [
    { label: 'All Foremen', value: 'all' },
    ...foremen.map((foreman) => ({ label: foreman.name, value: foreman.name })),
  ]

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

  useEffect(() => {
    if (debouncedQuery === filters.search) return
    applyFilters({ search: debouncedQuery })
  }, [debouncedQuery, filters.search, applyFilters])

  const rows = jobs.data
  const { meta } = jobs

  /**
   * Selection is derived against the rows actually on screen rather than reset
   * in an effect, so ids left over from a previous page or filter simply stop
   * counting instead of triggering a cascading render.
   */
  const pageIds = rows.map((job) => job.id)
  const selectedOnPage = selected.filter((id) => pageIds.includes(id))
  const allOnPageSelected = rows.length > 0 && selectedOnPage.length === rows.length

  const toggleAll = () => {
    setSelected(allOnPageSelected ? [] : pageIds)
  }

  const toggleOne = (id: number) => {
    setSelected((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    )
  }

  const runBulk = (action: 'archive' | 'unarchive' | 'delete' | 'status', status?: string) => {
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
      header: '',
      width: 'w-10',
      render: (job) => (
        <Checkbox
          id={`select-job-${job.id}`}
          label=""
          aria-label={`Select ${job.name}`}
          checked={selected.includes(job.id)}
          onChange={() => toggleOne(job.id)}
        />
      ),
    },
    {
      key: 'name',
      header: 'Job Name',
      render: (job) => (
        <span className="flex items-center gap-2.5">
          <StatusDot
            tone={JOB_STATUS_TONE[job.status]}
            pulse={job.status === 'in-progress'}
          />
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
            <span className="mt-0.5 flex items-center gap-2 text-sm text-white/75">
              {job.client ?? '—'}
              {job.isArchived && (
                <Badge tone="warning" className="py-0 text-2xs">
                  Archived
                </Badge>
              )}
            </span>
          </span>
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (job) => (
        <StatusChip
          hideDot
          tone={JOB_STATUS_TONE[job.status]}
          label={JOB_STATUS_LABEL[job.status]}
        />
      ),
    },
    {
      key: 'foreman',
      header: 'Foreman',
      render: (job) =>
        job.foreman ? (
          <ForemanBadge foreman={job.foreman} />
        ) : (
          <span className="text-white/70">Unassigned</span>
        ),
    },
    {
      key: 'assignments',
      header: 'Assigned',
      render: (job) =>
        job.assignments.length > 0 ? (
          <div className="flex flex-wrap gap-1.5">
            {job.assignments.map((assignment) => (
              <Badge key={assignment.id} tone="info" size="sm">
                {assignment.name} · {assignment.roleLabel}
              </Badge>
            ))}
          </div>
        ) : (
          <span className="text-white/70">—</span>
        ),
    },
    {
      key: 'startDate',
      header: 'Start Date',
      render: (job) => (
        <span className="whitespace-nowrap text-white/90">
          {job.startDate ? formatDate(job.startDate) : '—'}
        </span>
      ),
    },
    {
      key: 'budget',
      header: 'Budget',
      render: (job) => (
        <span className="whitespace-nowrap tabular-nums text-white">
          {job.budget === null ? '—' : formatCurrency(job.budget, 2)}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-52',
      render: (job) => (
        <div className="flex items-center gap-1">
          <ButtonLink href={routeTo.job(job.id)} size="sm" leftIcon={Eye}>
            View
          </ButtonLink>
          <IconButton
            icon={PencilLine}
            label={`Edit ${job.name}`}
            size="sm"
            className="text-white/85 hover:text-brand"
            onClick={() => router.visit(routeTo.jobEdit(job.id))}
          />
          <IconButton
            icon={Copy}
            label={`Duplicate ${job.name}`}
            size="sm"
            className="text-white/85 hover:text-brand"
            onClick={() =>
              router.post(routeTo.jobDuplicate(job.id), {}, { preserveScroll: true })
            }
          />
          <IconButton
            icon={job.isArchived ? ArchiveRestore : Archive}
            label={`${job.isArchived ? 'Unarchive' : 'Archive'} ${job.name}`}
            size="sm"
            className="text-white/85 hover:text-brand"
            onClick={() =>
              router.post(
                job.isArchived
                  ? routeTo.jobUnarchive(job.id)
                  : routeTo.jobArchive(job.id),
                {},
                { preserveScroll: true },
              )
            }
          />
          <IconButton
            icon={Trash2}
            label={`Delete ${job.name}`}
            size="sm"
            className="text-white/85 hover:text-status-danger"
            onClick={() => requestDelete(job)}
          />
        </div>
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
            <p className="mt-1 text-md text-white/90">
              Manage all your electrical projects in one place
            </p>
          </div>

          <ButtonLink
            href={ROUTES.jobCreate}
            variant="dark"
            leftIcon={Plus}
            className="lg:shrink-0"
          >
            Create Job
          </ButtonLink>
        </header>

        <div className="p-5 sm:p-6">
          {/* Archive view + filters toggle */}
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <FilterTabs
              solid
              options={JOB_VIEW_OPTIONS}
              value={filters.view}
              onChange={(view) => applyFilters({ view })}
              counts={{
                active: counts.active,
                archived: counts.archived,
                all: counts.active + counts.archived,
              }}
            />

            <div className="flex items-center gap-3">
              <span className="text-md text-white/90">View:</span>
              <Button
                size="sm"
                variant={isFiltersOpen ? 'primary' : 'secondary'}
                leftIcon={SlidersHorizontal}
                aria-expanded={isFiltersOpen}
                onClick={() => applyFilters({ panel: isFiltersOpen ? '' : 'open' })}
              >
                Filters
              </Button>
            </div>
          </div>

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
                <div className="mb-6 grid gap-4 rounded-panel border border-hairline bg-white/4 p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
                  <SearchBox
                    value={query}
                    onValueChange={setQuery}
                    placeholder="Search jobs, clients, locations…"
                    aria-label="Search jobs"
                  />
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
                  <SelectField
                    id="job-foreman-filter"
                    label="Foreman"
                    options={foremanOptions}
                    value={filters.foreman}
                    onChange={(event) => applyFilters({ foreman: event.target.value })}
                  />
                  <Button variant="white" onClick={resetFilters}>
                    Reset
                  </Button>
                </div>
              </motion.div>
            )}
          </AnimatePresence>

          {/* Sort */}
          <div className="mb-5 flex flex-wrap items-center justify-end gap-3">
            <label htmlFor="job-sort" className="text-md text-white/90">
              Sort by:
            </label>
            <SelectField
              id="job-sort"
              options={SORT_OPTIONS}
              value={filters.sort}
              onChange={(event) => applyFilters({ sort: event.target.value as JobSort })}
              className="w-56"
            />
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
                  variant="secondary"
                  size="sm"
                  leftIcon={Archive}
                  onClick={() => runBulk('archive')}
                >
                  Archive
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  leftIcon={ArchiveRestore}
                  onClick={() => runBulk('unarchive')}
                >
                  Unarchive
                </Button>
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
                <div className="mb-3 flex items-center gap-3">
                  <Checkbox
                    id="select-all-jobs"
                    label={allOnPageSelected ? 'Clear page selection' : 'Select all on page'}
                    checked={allOnPageSelected}
                    onChange={toggleAll}
                  />
                </div>
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  columns={columns}
                  rows={rows}
                  getRowId={(job) => job.id}
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
