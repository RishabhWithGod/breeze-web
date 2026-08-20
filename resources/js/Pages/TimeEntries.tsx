import { useCallback, useEffect, useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { AnimatePresence } from 'framer-motion'
import {
  Check,
  DollarSign,
  Download,
  Eye,
  Pencil,
  Plus,
  SearchX,
  Send,
  Trash2,
  X,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  Modal,
  MoreMenu,
  Pagination,
  SearchBox,
  SelectField,
  StatusChip,
  Table,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { TeamWeekSummary } from '@/components/timeTracking'
import {
  BILLABLE_FILTERS,
  ROUTES,
  TIME_ENTRY_STATUS_FILTERS,
  TIME_ENTRY_STATUS_LABEL,
  TIME_ENTRY_STATUS_TONE,
  routeTo,
} from '@/constants'
import { useDebouncedValue, useDisclosure } from '@/hooks'
import type {
  Paginated,
  SharedPageProps,
  TableColumn,
  TeamWeekSummaryState,
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
  weekSummary: TeamWeekSummaryState
  jobs: readonly TimeTrackingJobOption[]
  teamMembers: readonly TimeEntryPersonRef[]
  taskTypes: readonly string[]
  can: TimeTrackingAbilities
}

const taskTypeLabel = (value: string) => value.replace(/-/g, ' ')

/**
 * Time Log Viewer — the crew's weekly summary and every logged entry,
 * filterable by date range, job, employee, task type, status and billable.
 */
export default function TimeEntries({
  entries,
  filters,
  weekSummary,
  jobs,
  teamMembers,
  taskTypes,
  can,
}: TimeEntriesProps) {
  const { flash, auth } = usePage<SharedPageProps>().props

  const [query, setQuery] = useState(filters.search)
  const [draft, setDraft] = useState(filters)
  const [dismissed, setDismissed] = useState<string | null>(null)
  const [rejectingEntry, setRejectingEntry] = useState<TimeEntry | null>(null)
  const [deletingEntry, setDeletingEntry] = useState<TimeEntry | null>(null)
  const [rejectReason, setRejectReason] = useState('')

  const rejectDialog = useDisclosure()
  const deleteDialog = useDisclosure()
  const debouncedQuery = useDebouncedValue(query)

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

  useEffect(() => {
    if (debouncedQuery === filters.search) return
    updateQuery({ search: debouncedQuery })
  }, [debouncedQuery, filters.search, updateQuery])

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
    setQuery('')
    setDraft(cleared)
    router.get(ROUTES.timeEntries, {}, { preserveState: true, preserveScroll: true, replace: true })
  }

  const stepWeek = (weeks: number) => {
    const anchor = new Date(`${weekSummary.weekStart}T00:00:00`)
    anchor.setDate(anchor.getDate() + weeks * 7)
    updateQuery({ ...draft, week: anchor.toISOString().slice(0, 10) })
  }

  const goToThisWeek = () => updateQuery({ ...draft, week: undefined })

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

  const rows = entries.data
  const { meta } = entries

  const columns: TableColumn<TimeEntry>[] = [
    {
      key: 'date',
      header: 'Date',
      render: (entry) => <span className="whitespace-nowrap text-white">{formatDate(entry.date)}</span>,
    },
    {
      key: 'member',
      header: 'Employee',
      render: (entry) => (
        <span className="text-white">{entry.teamMember?.name ?? entry.user?.name ?? '—'}</span>
      ),
    },
    {
      key: 'job',
      header: 'Job',
      render: (entry) => <span className="text-white">{entry.job?.name ?? '—'}</span>,
    },
    {
      key: 'task',
      header: 'Task',
      render: (entry) => (
        <span className="text-white/85">{entry.jobTask?.title ?? entry.taskLabel ?? '—'}</span>
      ),
    },
    {
      key: 'start',
      header: 'Start',
      render: (entry) => <span className="text-white/85">{entry.startTime?.slice(0, 5) ?? '—'}</span>,
    },
    {
      key: 'end',
      header: 'End',
      render: (entry) => <span className="text-white/85">{entry.endTime?.slice(0, 5) ?? '—'}</span>,
    },
    {
      key: 'hours',
      header: 'Hours',
      align: 'right',
      render: (entry) => (
        <span className="inline-flex items-center gap-1.5 tabular-nums text-white">
          {entry.billable && (
            <DollarSign size={13} className="text-status-success" aria-label="Billable" />
          )}
          {formatHours(entry.hours)}
          {entry.overtimeHours > 0 && (
            <span className="text-xs text-status-warning">(+{formatHours(entry.overtimeHours)} OT)</span>
          )}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (entry) => (
        <StatusChip tone={TIME_ENTRY_STATUS_TONE[entry.status]} label={TIME_ENTRY_STATUS_LABEL[entry.status]} />
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      width: 'w-40',
      render: (entry) => {
        const isMine = entry.user?.id === auth.user?.id
        const canEdit = isMine && entry.isEditable

        return (
          <div className="flex items-center gap-1">
            <ButtonLink size="sm" variant="ghost" leftIcon={Eye} href={routeTo.timeEntry(entry.id)}>
              View
            </ButtonLink>
            <Button
              size="sm"
              variant="ghost"
              leftIcon={Trash2}
              disabled={!canEdit}
              className="text-status-danger hover:text-status-danger disabled:text-white/40"
              onClick={() => openDelete(entry)}
            >
              Delete
            </Button>
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
              leftIcon={Download}
              onClick={() => window.open(`${routeTo.timeEntriesExport('csv')}?${window.location.search.replace(/^\?/, '')}`, '_blank')}
            >
              Export
            </Button>
            <ButtonLink leftIcon={Plus} href={routeTo.timeEntryCreate()}>
              Add Time Entry
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
      <div className="mb-6 rounded-card border border-hairline glass p-5 shadow-panel sm:p-6">
        <div className="mb-4">
          <SearchBox
            value={query}
            onValueChange={setQuery}
            placeholder="Search entries…"
            aria-label="Search time entries"
            className="max-w-sm"
          />
        </div>

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

      {/* ============================================== Weekly Summary ======== */}
      <div className="mb-6 rounded-card border border-hairline glass p-5 shadow-panel sm:p-6">
        <TeamWeekSummary
          week={weekSummary}
          onPrevWeek={() => stepWeek(-1)}
          onNextWeek={() => stepWeek(1)}
          onThisWeek={goToThisWeek}
        />
      </div>

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
              getRowId={(entry) => entry.id}
              caption="Time entries"
            />
          )}

          <Pagination
            withLabels
            tone="light"
            className="mt-6"
            page={meta.current_page}
            pageCount={meta.last_page}
            onPageChange={(page) => updateQuery({ ...draft, page })}
            summary={meta.total === 0 ? 'No entries to display' : `Showing ${rows.length} of ${meta.total} entries`}
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
