import { useMemo, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import {
  ArrowLeft,
  Check,
  CheckCircle2,
  Clock,
  Hourglass,
  Layers,
  LogOut,
  MapPin,
  PencilLine,
  Timer,
  type LucideIcon,
} from 'lucide-react'
import { Button, ButtonLink, Card, Table } from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import {
  CheckOutDialog,
  SessionStatus,
  clockTime,
  entrySessionStatus,
  type CheckOutTarget,
  type SessionStatusKey,
} from '@/components/timeTracking'
import { ROUTES, routeTo } from '@/constants'
import type { AttendanceRow, TableColumn, TimeEntry, TimeTrackingDayDetail } from '@/types'
import { formatCalendarDate, formatHours } from '@/utils'

export interface TimeEntryDayShowProps {
  day: TimeTrackingDayDetail
}

const METHOD_SOURCE: Record<NonNullable<AttendanceRow['checkInMethod']>, string> = {
  automatic: 'Auto (Geofence)',
  photo: 'Photo check-in',
  manual: 'Manual check-in',
}

function attendanceStatus(row: AttendanceRow): SessionStatusKey {
  if (row.status === 'checkedOut') return 'completed'

  return row.missingCheckout ? 'missing-checkout' : 'on-site'
}

/**
 * One technician's one day — every timer/manual session and every GPS check-in
 * behind the day's total, with what has been approved and what is waiting.
 *
 * A manager approves from here: each finished session has its own button, and
 * "Approve all" takes every one of them at once. A check-in nobody closed can be
 * closed here too, by saying when they actually left.
 */
export default function TimeEntryDayShow({ day }: TimeEntryDayShowProps) {
  const [closing, setClosing] = useState<CheckOutTarget | null>(null)
  const approvable = useMemo(() => new Set(day.approvableEntryIds), [day.approvableEntryIds])

  const approve = (entryId: number) =>
    router.post(routeTo.timeEntryApprove(entryId), {}, { preserveScroll: true })

  const approveAll = () =>
    router.post(routeTo.timeEntryDayApprove(day.userId, day.date), {}, { preserveScroll: true })

  const hours = useMemo(() => {
    const sum = (keep: (entry: TimeEntry) => boolean) =>
      day.entries.filter(keep).reduce((total, entry) => total + entry.hours, 0)

    return {
      approved: sum((entry) => entrySessionStatus(entry) === 'approved'),
      waiting: sum((entry) => entrySessionStatus(entry) === 'pending'),
    }
  }, [day.entries])

  // One status for the whole day: anything that needs a person first.
  const overall: SessionStatusKey = useMemo(() => {
    if (day.attendance.some((row) => row.missingCheckout)) return 'missing-checkout'
    if (day.status === 'rejected') return 'rejected'
    if (day.status === 'pending' || day.status === 'mixed') return 'pending'
    if (day.status === 'approved') return 'approved'
    if (day.attendance.some((row) => row.status === 'checkedIn')) return 'on-site'

    return 'completed'
  }, [day.attendance, day.status])

  const sessionCount = day.entries.length + day.attendance.length
  const total = Math.max(day.totalHours, 0.0001)

  const openCheckOut = (row: AttendanceRow) =>
    setClosing({
      attendanceId: row.id,
      employee: day.employee.name,
      job: row.job?.name ?? null,
      checkedInAt: row.checkInLabel ? `${formatCalendarDate(row.date, 'MM/dd/yyyy')} ${row.checkInLabel}` : null,
      min: row.checkOutMin,
      suggested: row.checkOutSuggested,
    })

  const entryColumns: TableColumn<TimeEntry>[] = [
    {
      key: 'job',
      header: 'Job',
      render: (entry) => <span className="font-medium text-white">{entry.job?.name ?? 'No job'}</span>,
    },
    {
      key: 'task',
      header: 'Task',
      render: (entry) => (
        <span className="text-white/90">{entry.jobTask?.title ?? entry.taskLabel ?? '—'}</span>
      ),
    },
    {
      key: 'in',
      header: 'Check-in',
      render: (entry) => <Clocked icon={entry.source === 'timer' ? Timer : PencilLine} value={clockTime(entry.startTime)} />,
    },
    {
      key: 'out',
      header: 'Checkout',
      render: (entry) => <Clocked icon={entry.source === 'timer' ? Timer : PencilLine} value={clockTime(entry.endTime)} />,
    },
    {
      key: 'hours',
      header: 'Duration',
      render: (entry) => (
        <span className="font-semibold whitespace-nowrap tabular-nums text-white">{formatHours(entry.hours)}</span>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      render: (entry) => (
        <span className="text-white/90">{entry.source === 'timer' ? 'Timer' : 'Manual entry'}</span>
      ),
    },
    { key: 'status', header: 'Status', render: (entry) => <SessionStatus status={entrySessionStatus(entry)} /> },
    {
      key: 'actions',
      header: 'Actions',
      render: (entry) => (
        <div className="flex items-center gap-2">
          {approvable.has(entry.id) && (
            <Button size="sm" leftIcon={Check} onClick={() => approve(entry.id)}>
              Approve
            </Button>
          )}
          <ButtonLink href={routeTo.timeEntry(entry.id)} size="sm" variant="secondary">
            View
          </ButtonLink>
        </div>
      ),
    },
  ]

  const attendanceColumns: TableColumn<AttendanceRow>[] = [
    {
      key: 'job',
      header: 'Job',
      render: (row) => <span className="font-medium text-white">{row.job?.name ?? 'No job'}</span>,
    },
    {
      key: 'in',
      header: 'Check-in',
      render: (row) => <Clocked icon={MapPin} value={row.checkInLabel ?? '—'} />,
    },
    {
      key: 'out',
      header: 'Checkout',
      render: (row) =>
        row.checkOutLabel ? (
          <Clocked icon={MapPin} value={row.checkOutLabel} />
        ) : (
          <span className="text-white/60">—</span>
        ),
    },
    {
      key: 'hours',
      header: 'Duration',
      render: (row) => (
        <span className="font-semibold whitespace-nowrap tabular-nums text-white">
          {row.status === 'checkedIn' ? '—' : formatHours(row.hours)}
        </span>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      render: (row) => (
        <span className="text-white/90">
          {row.checkInMethod ? METHOD_SOURCE[row.checkInMethod] : 'Manual check-in'}
        </span>
      ),
    },
    { key: 'status', header: 'Status', render: (row) => <SessionStatus status={attendanceStatus(row)} /> },
    {
      key: 'actions',
      header: 'Actions',
      render: (row) => (
        <div className="flex items-center gap-2">
          {row.canCheckOut && (
            <Button size="sm" variant="secondary" leftIcon={LogOut} onClick={() => openCheckOut(row)}>
              Check out
            </Button>
          )}
          <ButtonLink href={routeTo.attendance(row.id)} size="sm" variant="secondary">
            View
          </ButtonLink>
        </div>
      ),
    },
  ]

  return (
    <PageTransition>
      <Head title={`${day.employee.name} — ${formatCalendarDate(day.date)}`} />

      {/* ==================================================== Header ========= */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <Link
            href={ROUTES.timeTracking}
            className="inline-flex items-center gap-2 text-md font-medium text-white transition-colors hover:text-brand"
          >
            <ArrowLeft size={17} aria-hidden />
            Time Tracking
          </Link>

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <h1 className="text-3xl font-bold text-white sm:text-4xl">{day.employee.name}</h1>
            {day.employee.role && (
              <span className="inline-flex rounded-full border border-brand/40 bg-brand/10 px-3 py-1 text-xs font-medium text-brand">
                {day.employee.role}
              </span>
            )}
            <SessionStatus status={overall} />
          </div>

          <p className="mt-2 text-md text-white/85">
            {formatCalendarDate(day.date, 'EEEE, MMMM d, yyyy')} · {sessionCount}{' '}
            {sessionCount === 1 ? 'session' : 'sessions'} logged
          </p>
        </div>

        {approvable.size > 0 && (
          <Button leftIcon={Check} onClick={approveAll}>
            {approvable.size === 1 ? 'Approve session' : `Approve all ${approvable.size}`}
          </Button>
        )}
      </div>

      {/* ================================================= Summary cards ===== */}
      <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard icon={Clock} label="Total Hours" value={formatHours(day.totalHours)} />
        <StatCard icon={CheckCircle2} label="Approved" value={formatHours(hours.approved)} />
        <StatCard icon={Hourglass} label="Waiting on Approval" value={formatHours(hours.waiting)} />
        <StatCard
          icon={Layers}
          label="Sessions"
          value={String(sessionCount)}
          note={`${day.entries.length} timer / manual · ${day.attendance.length} GPS`}
        />
      </div>

      <div className="mt-5 grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_21rem]">
        <div className="min-w-0 space-y-5">
          {day.entries.length > 0 && (
            <Card padding="md">
              <CardTitle title="Timer & Manual Entries" count={day.entries.length} />
              <div className="mt-3 overflow-x-auto">
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  className="min-w-4xl text-sm [&_th]:text-sm [&_td]:text-sm"
                  columns={entryColumns}
                  rows={day.entries}
                  getRowId={(entry) => entry.id}
                  caption="Timer and manual entries for the day"
                />
              </div>
            </Card>
          )}

          {day.attendance.length > 0 && (
            <Card padding="md">
              <CardTitle title="GPS Check-ins" count={day.attendance.length} />
              <div className="mt-3 overflow-x-auto">
                <Table
                  dense
                  variant="lined"
                  headerVariant="plain"
                  className="min-w-4xl text-sm [&_th]:text-sm [&_td]:text-sm"
                  columns={attendanceColumns}
                  rows={day.attendance}
                  getRowId={(row) => row.id}
                  caption="GPS check-ins for the day"
                />
              </div>
            </Card>
          )}
        </div>

        <Card padding="md">
          <h2 className="text-lg font-semibold text-white">Time by Job / Site</h2>
          <p className="mt-0.5 text-xs text-white/70">Every site the day&apos;s total is split across</p>

          <ul className="mt-4 space-y-4">
            {day.jobBreakdown.map((row) => {
              const share = Math.round((row.hours / total) * 100)

              return (
                <li key={row.job}>
                  <div className="flex items-baseline justify-between gap-3 text-md">
                    <span className="min-w-0 truncate text-white">{row.job}</span>
                    <span className="shrink-0 font-semibold tabular-nums text-white">{formatHours(row.hours)}</span>
                  </div>
                  <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-white/12" aria-hidden>
                    <div
                      className="h-full rounded-full bg-linear-to-r from-status-success/40 to-status-success"
                      style={{ width: `${Math.min(share, 100)}%` }}
                    />
                  </div>
                </li>
              )
            })}
          </ul>

          {day.jobBreakdown.length > 1 && (
            <div className="mt-5 flex items-center justify-between border-t border-hairline pt-3">
              <span className="text-md font-semibold text-white">Total</span>
              <span className="text-lg font-bold tabular-nums text-white">{formatHours(day.totalHours)}</span>
            </div>
          )}
        </Card>
      </div>

      <CheckOutDialog target={closing} onClose={() => setClosing(null)} />
    </PageTransition>
  )
}

TimeEntryDayShow.layout = appLayout

function CardTitle({ title, count }: { title: string; count: number }) {
  return (
    <div className="flex items-center gap-2.5">
      <h2 className="text-lg font-semibold text-white">{title}</h2>
      <span className="rounded-full bg-white/12 px-2 py-0.5 text-2xs text-white/85">{count}</span>
    </div>
  )
}

function Clocked({ icon: Icon, value }: { icon: LucideIcon; value: string }) {
  return (
    <span className="flex items-center gap-2 whitespace-nowrap text-white">
      <Icon size={14} aria-hidden className="shrink-0 text-white/75" />
      {value}
    </span>
  )
}

function StatCard({
  icon: Icon,
  label,
  value,
  note,
}: {
  icon: LucideIcon
  label: string
  value: string
  note?: string
}) {
  return (
    <Card padding="md" className="flex items-center gap-4">
      <span className="grid size-12 shrink-0 place-items-center rounded-panel bg-ocean-600/60 text-brand ring-1 ring-brand/25">
        <Icon size={22} aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm text-white/75">{label}</p>
        <p className="text-xl leading-tight font-bold text-white">{value}</p>
        {note && <p className="text-xs text-white/65">{note}</p>}
      </div>
    </Card>
  )
}
