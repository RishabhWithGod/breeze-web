import { useCallback, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import { Camera, ChevronDown, Filter, MapPin, Plus, SearchX, Timer } from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  EmptyState,
  Pagination,
  SelectField,
  StatusChip,
  Table,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  BILLABLE_FILTERS,
  DAY_STATUS_LABEL,
  DAY_STATUS_TONE,
  ROUTES,
  TIME_ENTRY_STATUS_FILTERS,
  routeTo,
} from '@/constants'
import type {
  Paginated,
  SharedPageProps,
  TableColumn,
  TimeEntryPersonRef,
  TimeTrackingAbilities,
  TimeTrackingDayRow,
  TimeTrackingJobOption,
} from '@/types'
import { formatDate, formatHours } from '@/utils'

interface EntryFilters {
  search: string
  from: string
  to: string
  job: string
  team_member: string
  task_type: string
  status: string
  billable: string
}

export interface TimeEntriesProps {
  days: Paginated<TimeTrackingDayRow>
  filters: EntryFilters
  jobs: readonly TimeTrackingJobOption[]
  teamMembers: readonly TimeEntryPersonRef[]
  taskTypes: readonly string[]
  can: TimeTrackingAbilities
}

const taskTypeLabel = (value: string) => value.replace(/-/g, ' ')

/**
 * 'Site Supervisor' is the real stored value — role-based checks elsewhere
 * in the app match on that exact string, so it stays as-is everywhere but
 * here. This page just shows it shorter, as "Supervisor" (same shortening
 * `Teams.tsx` already does for its own role display).
 */
const roleLabel = (role: string) => (role === 'Site Supervisor' ? 'Supervisor' : role)

/**
 * Time Log Viewer — one row per technician per day, filterable by date
 * range, job, employee, task type, status and billable. A technician who
 * stopped their timer four times today, or checked in and out of two jobs,
 * still shows once here with the day's total; every session behind that
 * total is one click away on the day's own detail screen.
 */
export default function TimeEntries({ days, filters, jobs, teamMembers, taskTypes, can }: TimeEntriesProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [draft, setDraft] = useState(filters)
  const [showFilters, setShowFilters] = useState(false)
  const [dismissed, setDismissed] = useState<string | null>(null)

  const flashed = flash.warning ?? flash.success ?? null
  const notice = flashed === dismissed ? null : flashed

  /** Merges into the *current* query string, keyed by the URL not a stale snapshot. */
  const updateQuery = useCallback(
    (changes: Record<string, string | number | undefined | null>) => {
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
    },
    [],
  )

  const applyFilters = () => updateQuery({ ...draft })

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
    }
    setDraft(cleared)
    router.get(ROUTES.timeEntries, {}, { preserveState: true, preserveScroll: true, replace: true })
  }

  const { meta } = days

  const columns: TableColumn<TimeTrackingDayRow>[] = [
    {
      key: 'date',
      header: 'Date',
      render: (day) => <span className="whitespace-nowrap text-white">{formatDate(day.date)}</span>,
    },
    {
      key: 'member',
      header: 'Employee',
      render: (day) => (
        <div className="flex items-center gap-2">
          <span className="text-white">{day.employee.name}</span>
          {day.employee.role && (
            <Badge tone="info" size="sm">
              {roleLabel(day.employee.role)}
            </Badge>
          )}
        </div>
      ),
    },
    {
      key: 'jobs',
      header: 'Job(s)',
      width: 'w-56',
      render: (day) => (
        <span className="text-white" title={day.jobs.join(', ')}>
          {day.jobs.length === 0
            ? '—'
            : day.jobs.length === 1
              ? day.jobs[0]
              : `${day.jobs[0]} +${day.jobs.length - 1} more`}
        </span>
      ),
    },
    {
      key: 'sessions',
      header: 'Sessions',
      render: (day) => (
        <div className="flex items-center gap-2 text-xs text-white/70">
          <span className="tabular-nums">{day.sessionCount}</span>
          {day.hasTimerEntries && <Timer size={13} aria-label="Timer or manual entries" />}
          {day.hasAttendance && <MapPin size={13} aria-label="GPS check-ins" />}
        </div>
      ),
    },
    {
      key: 'hours',
      header: 'Total Hours',
      align: 'right',
      render: (day) => (
        <span className="whitespace-nowrap tabular-nums text-white">{formatHours(day.totalHours)}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (day) =>
        day.status ? (
          <StatusChip tone={DAY_STATUS_TONE[day.status]} label={DAY_STATUS_LABEL[day.status]} />
        ) : (
          <Badge tone="neutral">
            <Camera size={12} className="mr-1 inline" aria-hidden />
            Check-in only
          </Badge>
        ),
    },
  ]

  return (
    <PageTransition>
      <Head title="Time Log Viewer" />

      <PageHeader
        title="Time Log Viewer"
        subtitle="One row per technician per day — open a day to see every session behind its total"
        breadcrumbs={[{ label: 'Time Tracking', href: ROUTES.timeTracking }, { label: 'Entries' }]}
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

      {/* ===================================================== Day list ==== */}
      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
          {days.data.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title="No time logged"
              description="No days match your current filters. Try another status or clear the search."
              actions={
                <Button variant="secondary" onClick={resetFilters}>
                  Reset filters
                </Button>
              }
            />
          ) : (
            <Table
              dense
              variant="lined"
              headerVariant="plain"
              columns={columns}
              rows={days.data}
              getRowId={(day) => `${day.userId}-${day.date}`}
              caption="Time logged per technician per day"
              onRowClick={(day) => router.visit(routeTo.timeEntryDay(day.userId, day.date))}
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => updateQuery({ ...draft, page })}
            summary={days.data.length === 0 ? 'No days to display' : `Showing ${days.data.length} of ${meta.total} days`}
          />
        </div>
      </div>
    </PageTransition>
  )
}

TimeEntries.layout = appLayout
