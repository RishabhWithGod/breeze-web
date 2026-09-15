import { useCallback, useMemo, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  Camera,
  Check,
  ChevronDown,
  Filter,
  MapPin,
  Pencil,
  Plus,
  SearchX,
  Send,
  Trash2,
  X,
} from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  Modal,
  MoreMenu,
  Pagination,
  SelectField,
  StatusChip,
  Table,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  BILLABLE_FILTERS,
  ROUTES,
  TIME_ENTRY_STATUS_FILTERS,
  TIME_ENTRY_STATUS_LABEL,
  TIME_ENTRY_STATUS_TONE,
  routeTo,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  AttendanceRow,
  Paginated,
  SharedPageProps,
  TableColumn,
  TimeEntry,
  TimeEntryPersonRef,
  TimeTrackingAbilities,
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
  entries: Paginated<TimeEntry>
  filters: EntryFilters
  jobs: readonly TimeTrackingJobOption[]
  teamMembers: readonly TimeEntryPersonRef[]
  taskTypes: readonly string[]
  attendance: readonly AttendanceRow[]
  can: TimeTrackingAbilities
}

const ATTENDANCE_METHOD_LABEL: Record<NonNullable<AttendanceRow['checkInMethod']>, string> = {
  manual: 'Manual',
  automatic: 'GPS match',
  photo: 'Photo',
}

const taskTypeLabel = (value: string) => value.replace(/-/g, ' ')

/**
 * 'Site Supervisor' is the real stored value — role-based checks elsewhere
 * in the app match on that exact string, so it stays as-is everywhere but
 * here. This page just shows it shorter, as "Supervisor" (same shortening
 * `Teams.tsx` already does for its own role display).
 */
const roleLabel = (role: string) => (role === 'Site Supervisor' ? 'Supervisor' : role)

const formatTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—'

const formatMeters = (value: number | null) =>
  value === null ? '—' : value >= 1000 ? `${(value / 1000).toFixed(1)} km` : `${Math.round(value)} m`

/**
 * One row of the merged table — a logged [TimeEntry] or a GPS
 * [AttendanceRow]. They come from unrelated tables with no shared key, so
 * every column renders off `kind` rather than a common shape.
 */
type MergedRow =
  | { readonly id: string; readonly kind: 'entry'; readonly entry: TimeEntry }
  | { readonly id: string; readonly kind: 'attendance'; readonly row: AttendanceRow }

/**
 * Time Log Viewer — the crew's weekly summary and every logged entry,
 * filterable by date range, job, employee, task type, status and billable.
 */
export default function TimeEntries({
  entries,
  filters,
  jobs,
  teamMembers,
  taskTypes,
  attendance,
  can,
}: TimeEntriesProps) {
  const { flash, auth } = usePage<SharedPageProps>().props

  const [draft, setDraft] = useState(filters)
  const [showFilters, setShowFilters] = useState(false)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [rejectingEntry, setRejectingEntry] = useState<TimeEntry | null>(null)
  const [deletingEntry, setDeletingEntry] = useState<TimeEntry | null>(null)
  const [rejectReason, setRejectReason] = useState('')

  const rejectDialog = useDisclosure()
  const deleteDialog = useDisclosure()

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

  const openReject = (entry: TimeEntry) => {
    setRejectingEntry(entry)
    setRejectReason('')
    rejectDialog.open()
  }

  const openDelete = (entry: TimeEntry) => {
    setDeletingEntry(entry)
    deleteDialog.open()
  }

  const submitEntry = (entry: TimeEntry) => {
    router.post(routeTo.timeEntrySubmit(entry.id), {}, { preserveScroll: true })
  }

  const approveEntry = (entry: TimeEntry) => {
    router.post(routeTo.timeEntryApprove(entry.id), {}, { preserveScroll: true })
  }

  const confirmDelete = () => {
    if (!deletingEntry) return

    router.delete(routeTo.timeEntry(deletingEntry.id), {
      preserveScroll: true,
      onSuccess: () => setDeletingEntry(null),
    })
    deleteDialog.close()
  }

  const confirmReject = () => {
    if (!rejectingEntry || rejectReason.trim().length < 3) return

    router.post(
      routeTo.timeEntryReject(rejectingEntry.id),
      { reason: rejectReason },
      { preserveScroll: true, onSuccess: () => rejectDialog.close() },
    )
  }

  const { meta } = entries

  /**
   * One table, one list. A logged entry and a GPS check-in come from
   * unrelated models with no foreign key between them, so there is no
   * database-level union — they are simply concatenated here, entries
   * first, then whichever check-ins fell in the last 14 days.
   */
  const rows: readonly MergedRow[] = useMemo(
    () => [
      ...entries.data.map((entry): MergedRow => ({ id: `entry-${entry.id}`, kind: 'entry', entry })),
      ...attendance.map((row): MergedRow => ({ id: `attendance-${row.id}`, kind: 'attendance', row })),
    ],
    [entries.data, attendance],
  )

  const columns: TableColumn<MergedRow>[] = [
    {
      key: 'date',
      header: 'Date',
      render: (item) => (
        <span className="whitespace-nowrap text-white">
          {formatDate(item.kind === 'entry' ? item.entry.date : item.row.date)}
        </span>
      ),
    },
    {
      key: 'member',
      header: 'Employee',
      render: (item) => {
        const name =
          item.kind === 'entry'
            ? (item.entry.teamMember?.name ?? item.entry.user?.name ?? '—')
            : item.row.employee
        const role =
          item.kind === 'entry'
            ? (item.entry.teamMember?.role ?? item.entry.user?.role ?? null)
            : item.row.employeeRole

        return (
          <div className="flex items-center gap-2">
            <span className="text-white">{name}</span>
            {role && (
              <Badge tone="info" size="sm">
                {roleLabel(role)}
              </Badge>
            )}
          </div>
        )
      },
    },
    {
      key: 'job',
      header: 'Job',
      width: 'w-56',
      render: (item) => (
        <span className="whitespace-nowrap text-white">
          {(item.kind === 'entry' ? item.entry.job : item.row.job)?.name ?? '—'}
        </span>
      ),
    },
    {
      key: 'start',
      header: 'Check In',
      render: (item) =>
        item.kind === 'entry' ? (
          <span className="text-white/85">{item.entry.startTime?.slice(0, 5) ?? '—'}</span>
        ) : (
          <div>
            <span className="text-white/85">{formatTime(item.row.checkInAt)}</span>
            <span className="ml-1.5 text-2xs text-white/60">{formatMeters(item.row.checkInDistanceMeters)}</span>
          </div>
        ),
    },
    {
      key: 'end',
      header: 'Check Out',
      render: (item) =>
        item.kind === 'entry' ? (
          <span className="text-white/85">{item.entry.endTime?.slice(0, 5) ?? '—'}</span>
        ) : (
          <div>
            <span className="text-white/85">{formatTime(item.row.checkOutAt)}</span>
            <span className="ml-1.5 text-2xs text-white/60">{formatMeters(item.row.checkOutDistanceMeters)}</span>
          </div>
        ),
    },
    {
      key: 'hours',
      header: 'Hours',
      align: 'right',
      render: (item) =>
        item.kind === 'entry' ? (
          <span className="inline-flex items-center gap-1.5 tabular-nums text-white">
            {formatHours(item.entry.hours)}
            {item.entry.overtimeHours > 0 && (
              <span className="text-xs text-status-warning">(+{formatHours(item.entry.overtimeHours)} OT)</span>
            )}
          </span>
        ) : (
          <span className="tabular-nums text-white">{formatHours(item.row.hours)}</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (item) =>
        item.kind === 'entry' ? (
          <StatusChip
            tone={TIME_ENTRY_STATUS_TONE[item.entry.status]}
            label={TIME_ENTRY_STATUS_LABEL[item.entry.status]}
          />
        ) : item.row.status === 'checkedIn' ? (
          <Badge tone="success">On site</Badge>
        ) : (
          <Badge tone="neutral">Checked out</Badge>
        ),
    },
    {
      key: 'verified',
      header: 'Verified',
      render: (item) => {
        if (item.kind === 'entry') return <span className="text-white/40">—</span>

        const method = item.row.checkInMethod

        return (
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs text-white/70">
              {method === 'automatic' ? <MapPin size={13} /> : method === 'photo' ? <Camera size={13} /> : null}
              {method ? ATTENDANCE_METHOD_LABEL[method] : '—'}
            </span>
            {item.row.photoUrl && (
              <a href={item.row.photoUrl} target="_blank" rel="noreferrer">
                <img
                  src={item.row.photoUrl}
                  alt="Check-in photo"
                  className="h-8 w-8 rounded-md border border-hairline object-cover"
                />
              </a>
            )}
          </div>
        )
      },
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-40',
      render: (item) => {
        // Nothing else to do with a check-in — a click anywhere on its row
        // already opens the detail screen (see `onRowClick` on the table).
        if (item.kind === 'attendance') return null

        const entry = item.entry
        const isMine = entry.user?.id === auth.user?.id
        const canEdit = isMine && entry.isEditable

        return (
          <div className="flex items-center gap-1">
            <MoreMenu
              ariaLabel={`More actions for the entry on ${entry.date}`}
              items={[
                {
                  label: 'Edit',
                  icon: Pencil,
                  disabled: !canEdit,
                  onSelect: () => router.visit(routeTo.timeEntryEdit(entry.id)),
                },
                {
                  label: 'Submit',
                  icon: Send,
                  disabled: !canEdit,
                  onSelect: () => submitEntry(entry),
                },
                {
                  label: 'Approve',
                  icon: Check,
                  disabled: !(can.approve && entry.status === 'submitted'),
                  onSelect: () => approveEntry(entry),
                },
                {
                  label: 'Reject',
                  icon: X,
                  disabled: !(can.approve && entry.status === 'submitted'),
                  onSelect: () => openReject(entry),
                },
                {
                  label: 'Delete',
                  icon: Trash2,
                  disabled: !canEdit,
                  destructive: true,
                  onSelect: () => openDelete(entry),
                },
              ]}
            />
          </div>
        )
      },
    },
  ]

  return (
    <PageTransition>
      <Head title="Time Log Viewer" />

      <PageHeader
        title="Time Log Viewer"
        subtitle="View and manage time entries for your team"
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

      {/* =================================================== Entries table ==== */}
      <div className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-5 sm:p-6">
          {rows.length === 0 ? (
            <EmptyState
              icon={SearchX}
              title="No time entries found"
              description="No entries match your current filters. Try another status or clear the search."
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
              rows={rows}
              getRowId={(item) => item.id}
              caption="Time entries"
              onRowClick={(item) =>
                router.visit(
                  item.kind === 'entry' ? routeTo.timeEntry(item.entry.id) : routeTo.attendance(item.row.id),
                )
              }
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => updateQuery({ ...draft, page })}
            summary={
              rows.length === 0
                ? 'No entries to display'
                : `Showing ${rows.length} of ${meta.total + attendance.length} entries`
            }
          />
        </div>
      </div>

      <ConfirmDialog
        isOpen={deleteDialog.isOpen}
        tone="danger"
        title="Delete this time entry?"
        description="This cannot be undone."
        confirmLabel="Delete entry"
        confirmVariant="danger"
        onConfirm={confirmDelete}
        onCancel={() => {
          setDeletingEntry(null)
          deleteDialog.close()
        }}
      />

      <Modal
        isOpen={rejectDialog.isOpen}
        onClose={rejectDialog.close}
        title="Reject time entry"
        description="Say why, so the employee knows what to fix and can resubmit."
        footer={
          <>
            <Button variant="ghost" onClick={rejectDialog.close}>
              Cancel
            </Button>
            <Button
              variant="danger"
              leftIcon={X}
              disabled={rejectReason.trim().length < 3}
              onClick={confirmReject}
            >
              Reject
            </Button>
          </>
        }
      >
        <TextArea
          id="reject-reason"
          label="Reason"
          rows={3}
          value={rejectReason}
          onChange={(event) => setRejectReason(event.target.value)}
          placeholder="e.g. Hours don't match the schedule for this day."
          autoFocus
        />
      </Modal>

    </PageTransition>
  )
}

TimeEntries.layout = appLayout
