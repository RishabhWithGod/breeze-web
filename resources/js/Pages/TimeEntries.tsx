import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  Check,
  ChevronDown,
  Clock,
  Filter,
  LogOut,
  MapPin,
  PencilLine,
  Plus,
  SearchX,
  Timer,
  TriangleAlert,
  type LucideIcon,
} from 'lucide-react'
import { Alert, Badge, Button, ButtonLink, EmptyState, Pagination, SelectField, Table, TextInput } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { CheckOutDialog, SessionStatus, type CheckOutTarget } from '@/components/timeTracking'
import { BILLABLE_FILTERS, ROUTES, TIME_ENTRY_STATUS_FILTERS, routeTo } from '@/constants'
import type {
  Paginated,
  SharedPageProps,
  TableColumn,
  TimeEntryPersonRef,
  TimeTrackingAbilities,
  TimeLogRow,
  TimeTrackingJobOption,
} from '@/types'
import { formatHours } from '@/utils'

interface EntryFilters {
  search: string
  from: string
  to: string
  job: string
  team_member: string
  task_type: string
  status: string
  billable: string
  exceptions: boolean
}

export interface TimeEntriesProps {
  log: Paginated<TimeLogRow>
  /** Sessions that need a look: a check-in never closed, or a rejected entry. */
  exceptionCount: number
  filters: EntryFilters
  jobs: readonly TimeTrackingJobOption[]
  teamMembers: readonly TimeEntryPersonRef[]
  taskTypes: readonly string[]
  can: TimeTrackingAbilities
}

const taskTypeLabel = (value: string) => value.replace(/-/g, ' ')

/**
 * Time Log Viewer — one row per technician per day, filterable by date
 * range, job, employee, task type, status and billable. A technician who
 * stopped their timer four times today, or checked in and out of two jobs,
 * still shows once here with the day's total; every session behind that
 * total is one click away on the day's own detail screen.
 */
export default function TimeEntries({
  log,
  exceptionCount,
  filters,
  jobs,
  teamMembers,
  taskTypes,
  can,
}: TimeEntriesProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [draft, setDraft] = useState(filters)
  const [showFilters, setShowFilters] = useState(false)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [closing, setClosing] = useState<CheckOutTarget | null>(null)

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  /** Merges into the *current* query string, keyed by the URL not a stale snapshot. */
  const updateQuery = useCallback((changes: Record<string, string | number | undefined | null>) => {
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
      queryString ? `${ROUTES.timeEntries}?${queryString}` : ROUTES.timeEntries,
      {},
      { preserveState: true, preserveScroll: true, replace: true },
    )
  }, [])

  const applyFilters = () => updateQuery({ ...draft, exceptions: draft.exceptions ? 1 : '' })

  const toggleExceptions = () => {
    const next = !filters.exceptions
    setDraft({ ...draft, exceptions: next })
    updateQuery({ exceptions: next ? 1 : '' })
  }

  const resetFilters = () => {
    const cleared: EntryFilters = {
      search: '',
      from: '',
      to: '',
      job: '',
      team_member: '',
      task_type: 'all',
      status: 'all',
      billable: 'all',
      exceptions: false,
    }
    setDraft(cleared)
    router.get(ROUTES.timeEntries, {}, { preserveState: true, preserveScroll: true, replace: true })
  }

  const { meta } = log

  const approve = (row: TimeLogRow) => {
    if (row.entryId === null) return

    router.post(routeTo.timeEntryApprove(row.entryId), {}, { preserveScroll: true })
  }

  const openCheckOut = (row: TimeLogRow) => {
    if (row.attendanceId === null) return

    setClosing({
      attendanceId: row.attendanceId,
      employee: row.employee.name,
      job: row.job,
      checkedInAt: row.checkIn.at,
      min: row.checkOutMin,
      suggested: row.checkOutSuggested,
    })
  }

  const columns: TableColumn<TimeLogRow>[] = [
    {
      key: 'employee',
      header: 'Employee',
      // Who, and what they are, in one cell — a column of its own for a small chip only
      // made the row wider than the card.
      render: (row) => (
        <div className="min-w-0">
          <span className="block text-white">{row.employee.name}</span>
          {row.employee.role && (
            <span className="mt-1 inline-flex rounded-full border border-brand/40 bg-brand/10 px-2.5 py-0.5 text-2xs font-medium text-brand">
              {row.employee.role}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'job',
      header: 'Job',
      render: (row) => <span className="text-white">{row.job ?? '—'}</span>,
    },
    {
      key: 'checkIn',
      header: 'Check-in',
      render: (row) => <Moment moment={row.checkIn} kind={row.sourceKind} />,
    },
    {
      key: 'checkOut',
      header: 'Checkout',
      render: (row) => (
        <Moment
          moment={row.checkOut}
          kind={row.sourceKind}
          empty={
            row.status === 'missing-checkout' ? (
              <span className="mt-0.5 flex items-center gap-1.5 text-xs text-status-warning">
                <TriangleAlert size={14} aria-hidden /> No checkout yet
              </span>
            ) : row.status === 'on-site' ? (
              <span className="mt-0.5 text-xs text-white/70">Still on site</span>
            ) : null
          }
        />
      ),
    },
    {
      key: 'duration',
      header: 'Duration',
      render: (row) => (
        <span className="whitespace-nowrap tabular-nums text-white">
          {row.hours === null ? '—' : formatHours(row.hours)}
        </span>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      render: (row) => {
        const Icon = SOURCE_ICON[row.sourceKind]

        return (
          <span className="flex items-center gap-2 whitespace-nowrap text-white">
            <Icon size={15} aria-hidden className="shrink-0" />
            {row.source}
          </span>
        )
      },
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <span className="flex flex-wrap items-center gap-2">
          <SessionStatus status={row.status} />
          {row.recordedOffline && <Badge tone="info" size="sm">Offline</Badge>}
          {row.reviewFlag && <Badge tone="warning" size="sm">Review</Badge>}
          {(row.openReports ?? 0) > 0 && <Badge tone="danger" size="sm">Reported</Badge>}
        </span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => (
        <div className="flex items-center gap-2">
          {row.canApprove && (
            <Button size="sm" variant="primary" leftIcon={Check} onClick={() => approve(row)}>
              Approve
            </Button>
          )}
          {row.canCheckOut && (
            <Button size="sm" variant="secondary" leftIcon={LogOut} onClick={() => openCheckOut(row)}>
              Check out
            </Button>
          )}
          {!row.canApprove && !row.canCheckOut && <span className="text-white/40">—</span>}
        </div>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Time Tracking" />

      <PageHeader
        title="Time Tracking"
        subtitle="Track time automatically with geofence check-ins, or add entries manually."
        breadcrumbs={[{ label: 'Time Tracking' }]}
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={Filter}
              rightIcon={ChevronDown}
              aria-expanded={showFilters}
              onClick={() => setShowFilters((current) => !current)}
            >
              Filters
            </Button>
            {/* The sessions that need a person: a check-in never closed, a rejected entry. */}
            <Button
              variant={filters.exceptions ? 'primary' : 'secondary'}
              leftIcon={TriangleAlert}
              aria-pressed={filters.exceptions}
              onClick={toggleExceptions}
            >
              Review Exceptions ({exceptionCount})
            </Button>
            <ButtonLink leftIcon={Plus} href={routeTo.timeEntryCreate()}>
              Manual Time Entry
            </ButtonLink>
          </>
        }
      />

      <AnimatePresence initial={false}>
        {notice && (
          <Alert
            key={notice}
            tone={flash.warning ? 'warning' : 'success'}
            className="mb-6"
            onDismiss={() => setDismissed(notice)}
          >
            {notice}
          </Alert>
        )}
      </AnimatePresence>

      {/* ==================================================== Filters ========= */}
      {/* Hidden until "Filters" is clicked in the header above — these columns
          start out of the way. */}
      {showFilters && (
        <div className="mb-6 rounded-card border border-hairline glass p-5 shadow-panel sm:p-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4">
            <TextInput
              id="filter-from"
              type="date"
              label="Date Range — From"
              value={draft.from}
              onChange={(event) => setDraft({ ...draft, from: event.target.value })}
            />
            <TextInput
              id="filter-to"
              type="date"
              label="Date Range — To"
              value={draft.to}
              onChange={(event) => setDraft({ ...draft, to: event.target.value })}
            />
            <SelectField
              id="filter-job"
              label="Jobs"
              options={[
                { label: 'All Jobs', value: 'all' },
                ...jobs.map((job) => ({ label: job.name, value: String(job.id) })),
              ]}
              value={draft.job || 'all'}
              onChange={(event) => setDraft({ ...draft, job: event.target.value })}
            />
            {can.viewCrew ? (
              <SelectField
                id="filter-team-member"
                label="Employee"
                options={[
                  { label: 'All Employees', value: 'all' },
                  ...teamMembers.map((member) => ({ label: member.name, value: String(member.id) })),
                ]}
                value={draft.team_member || 'all'}
                onChange={(event) => setDraft({ ...draft, team_member: event.target.value })}
              />
            ) : (
              <SelectField
                id="filter-task-type"
                label="Task Type"
                options={[
                  { label: 'All Task Types', value: 'all' },
                  ...taskTypes.map((type) => ({ label: taskTypeLabel(type), value: type })),
                ]}
                value={draft.task_type}
                onChange={(event) => setDraft({ ...draft, task_type: event.target.value })}
              />
            )}
          </div>

          <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {can.viewCrew && (
              <SelectField
                id="filter-task-type-2"
                label="Task Type"
                options={[
                  { label: 'All Task Types', value: 'all' },
                  ...taskTypes.map((type) => ({ label: taskTypeLabel(type), value: type })),
                ]}
                value={draft.task_type}
                onChange={(event) => setDraft({ ...draft, task_type: event.target.value })}
              />
            )}
            <SelectField
              id="filter-status"
              label="Status"
              options={TIME_ENTRY_STATUS_FILTERS}
              value={draft.status}
              onChange={(event) => setDraft({ ...draft, status: event.target.value })}
            />
            <SelectField
              id="filter-billable"
              label="Billable"
              options={BILLABLE_FILTERS}
              value={draft.billable}
              onChange={(event) => setDraft({ ...draft, billable: event.target.value })}
            />
            <div className="flex items-end gap-3">
              <Button onClick={applyFilters}>Apply Filters</Button>
              <Button variant="white" onClick={resetFilters}>
                Reset
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* ================================================== Session log ==== */}
      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
          {log.data.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title={filters.exceptions ? 'No exceptions' : 'No time logged'}
              description={
                filters.exceptions
                  ? 'Every check-in has a checkout and nothing has been rejected.'
                  : 'Nothing matches your current filters. Try another status or clear the search.'
              }
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  {filters.exceptions ? 'Show all entries' : 'Reset filters'}
                </Button>
              }
            />
          ) : (
            // The table scrolls sideways by itself when a screen is too narrow; wrapping it
            // in another scroller is what made two scrollbars appear.
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              className="text-sm [&_th]:text-sm [&_td]:text-sm"
              columns={columns}
              rows={log.data}
              getRowId={(row) => row.key}
              caption="Time logged, one row per session"
              onRowClick={(row) => router.visit(routeTo.timeEntryDay(row.userId, row.date))}
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => updateQuery({ ...draft, exceptions: draft.exceptions ? 1 : '', page })}
            summary={
              log.data.length === 0 ? 'No entries to display' : `Showing ${log.data.length} of ${meta.total} entries`
            }
          />
        </div>
      </div>

      <CheckOutDialog target={closing} onClose={() => setClosing(null)} />
    </PageTransition>
  )
}

TimeEntries.layout = appLayout

const SOURCE_ICON: Record<TimeLogRow['sourceKind'], LucideIcon> = {
  geofence: MapPin,
  timer: Timer,
  manual: PencilLine,
}

/** One end of a session: when it happened and, underneath, how it was recorded. */
function Moment({
  moment,
  kind,
  empty = null,
}: {
  moment: TimeLogRow['checkIn']
  kind: TimeLogRow['sourceKind']
  empty?: React.ReactNode
}) {
  const Icon = kind === 'geofence' ? MapPin : Clock

  if (moment.at === null) {
    return (
      <div>
        <span className="text-white/60">—</span>
        {empty}
      </div>
    )
  }

  return (
    <div className="flex items-start gap-2">
      <Icon size={15} aria-hidden className="mt-0.5 shrink-0 text-white/85" />
      <div>
        <p className="whitespace-nowrap text-white">{moment.at}</p>
        {moment.note && <p className="text-xs whitespace-nowrap text-white/65">{moment.note}</p>}
      </div>
    </div>
  )
}
