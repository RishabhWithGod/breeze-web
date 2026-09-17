import { Head } from '@inertiajs/react'
import { Camera, MapPin } from 'lucide-react'
import { Badge, ButtonLink, Card, SectionHeading, StatusChip } from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  DAY_STATUS_LABEL,
  DAY_STATUS_TONE,
  ROUTES,
  TIME_ENTRY_STATUS_LABEL,
  TIME_ENTRY_STATUS_TONE,
  routeTo,
} from '@/constants'
import type { AttendanceRow, TimeEntry, TimeTrackingDayDetail } from '@/types'
import { formatDate, formatHours } from '@/utils'

export interface TimeEntryDayShowProps {
  day: TimeTrackingDayDetail
}

const ATTENDANCE_METHOD_LABEL: Record<NonNullable<AttendanceRow['checkInMethod']>, string> = {
  manual: 'Manual',
  automatic: 'GPS match',
  photo: 'Photo',
}

const formatTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—'

/**
 * One technician's one day — laid out the same way a single time entry's
 * own detail screen is (summary cards, then a figures card, then history),
 * so it reads as the same screen a manager already knows, just totalled
 * for the day rather than one session. Every timer/manual entry and every
 * GPS check-in behind that total is broken out below, in full, with a link
 * to the exact same per-session detail screen this page used to send
 * someone to directly — nothing about approving, editing or rejecting one
 * session moved; it still lives there.
 */
export default function TimeEntryDayShow({ day }: TimeEntryDayShowProps) {
  const initials = day.employee.name
    .split(' ')
    .map((part) => part[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()

  const sessionCount = day.entries.length + day.attendance.length

  return (
    <PageTransition>
      <Head title={`${day.employee.name} — ${formatDate(day.date)}`} />

      <PageHeader
        title={day.employee.name}
        subtitle={formatDate(day.date)}
        breadcrumbs={[
          { label: 'Time Tracking', href: ROUTES.timeTracking },
          { label: formatDate(day.date) },
        ]}
        actions={
          <ButtonLink href={ROUTES.timeEntries} variant="secondary" size="sm">
            Back to Time Log
          </ButtonLink>
        }
      />

      <div className="mb-6 flex flex-wrap items-center gap-3">
        {day.status ? (
          <StatusChip tone={DAY_STATUS_TONE[day.status]} label={DAY_STATUS_LABEL[day.status]} />
        ) : day.attendanceStatus === 'checkedIn' ? (
          <Badge tone="success">
            <Camera size={12} className="mr-1 inline" aria-hidden />
            On site
          </Badge>
        ) : (
          <Badge tone="neutral">
            <Camera size={12} className="mr-1 inline" aria-hidden />
            Checked out
          </Badge>
        )}
        <span className="text-sm text-white/70">
          {formatDate(day.date)} · {sessionCount} {sessionCount === 1 ? 'session' : 'sessions'} logged
        </span>
      </div>

      {/* ============================================ Summary cards =========== */}
      <div className="grid gap-6 xl:grid-cols-2">
        <Card accent="brand" padding="lg">
          <SectionHeading as="h3" title="Electrician Information" />
          <div className="flex items-start gap-4">
            <span className="grid size-14 shrink-0 place-items-center rounded-full bg-ocean-800 text-md font-semibold text-white ring-1 ring-steel-600">
              {initials || '—'}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-lg font-semibold text-white">{day.employee.name}</p>
              {day.employee.role && <p className="text-sm text-white/80">{day.employee.role}</p>}
            </div>
          </div>
        </Card>

        <Card accent="success" padding="lg">
          <SectionHeading as="h3" title="Time by Job / Site" subtitle="Every site this day's total is split across" />
          <dl className="flex flex-col gap-2">
            {day.jobBreakdown.map((row) => (
              <TotalRow key={row.job} label={row.job} value={formatHours(row.hours)} />
            ))}
            {day.jobBreakdown.length > 1 && (
              <TotalRow label="Total" value={formatHours(day.totalHours)} strong />
            )}
          </dl>
        </Card>
      </div>

      {/* ================================================= Time details ======= */}
      <Card accent="neutral" padding="lg" className="mt-6">
        <SectionHeading as="h3" title="Time Details" subtitle="Calculated by the server — never recomputed here" />
        <dl className="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-4">
          <Field label="Date" value={formatDate(day.date)} />
          <Field label="Total Hours" value={formatHours(day.totalHours)} strong />
          <Field label="Timer & Manual Sessions" value={String(day.entries.length)} />
          <Field label="GPS Check-Ins" value={String(day.attendance.length)} />
        </dl>
      </Card>

      {/* ==================================== Timer & manual sessions ========= */}
      {day.entries.length > 0 && (
        <Card accent="warning" padding="lg" className="mt-6">
          <SectionHeading
            as="h3"
            title="Timer & Manual Entries"
            subtitle={`${day.entries.length} logged on ${formatDate(day.date)}`}
          />
          <div className="flex flex-col divide-y divide-hairline">
            {day.entries.map((entry) => (
              <EntrySession key={entry.id} entry={entry} />
            ))}
          </div>
        </Card>
      )}

      {/* ========================================== GPS check-ins ============= */}
      {day.attendance.length > 0 && (
        <Card accent="success" padding="lg" className="mt-6">
          <SectionHeading
            as="h3"
            title="GPS Check-Ins"
            subtitle={`${day.attendance.length} on ${formatDate(day.date)}`}
          />
          <div className="flex flex-col divide-y divide-hairline">
            {day.attendance.map((row) => (
              <AttendanceSession key={row.id} row={row} />
            ))}
          </div>
        </Card>
      )}
    </PageTransition>
  )
}

/** One timer/manual session — the same fields its own detail screen's
 *  "Time Details" card shows, plus a link through to that full screen for
 *  anything that needs editing, submitting or approving. */
function EntrySession({ entry }: { entry: TimeEntry }) {
  return (
    <div className="py-4 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-md font-semibold text-white">{entry.job?.name ?? 'No job'}</p>
        <div className="flex items-center gap-2">
          <StatusChip tone={TIME_ENTRY_STATUS_TONE[entry.status]} label={TIME_ENTRY_STATUS_LABEL[entry.status]} />
          <ButtonLink href={routeTo.timeEntry(entry.id)} size="sm" variant="ghost">
            View
          </ButtonLink>
        </div>
      </div>
      <dl className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <Field label="Task" value={entry.jobTask?.title ?? entry.taskLabel ?? '—'} />
        <Field label="Check In" value={entry.startTime?.slice(0, 5) ?? '—'} />
        <Field label="Check Out" value={entry.endTime?.slice(0, 5) ?? '—'} />
        <Field label="Hours" value={formatHours(entry.hours)} strong />
        <Field label="Source" value={entry.source === 'timer' ? 'Timer' : 'Manual'} />
      </dl>
    </div>
  )
}

/** One GPS check-in/check-out cycle — the same fields its own detail
 *  screen's Check In/Check Out cards show, condensed to one row. */
function AttendanceSession({ row }: { row: AttendanceRow }) {
  return (
    <div className="py-4 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-md font-semibold text-white">{row.job?.name ?? 'No job'}</p>
        <div className="flex items-center gap-2">
          {row.status === 'checkedIn' ? (
            <Badge tone="success">On site</Badge>
          ) : (
            <Badge tone="neutral">Checked out</Badge>
          )}
          <ButtonLink href={routeTo.attendance(row.id)} size="sm" variant="ghost">
            View
          </ButtonLink>
        </div>
      </div>
      <dl className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <Field label="Check In" value={formatTime(row.checkInAt)} />
        <Field label="Check Out" value={formatTime(row.checkOutAt)} />
        <Field label="Hours" value={formatHours(row.hours)} strong />
        <Field
          label="Verified"
          value={row.checkInMethod ? ATTENDANCE_METHOD_LABEL[row.checkInMethod] : '—'}
        />
        {row.photoUrl && (
          <div>
            <dt className="text-2xs tracking-wide text-white/80 uppercase">Photo</dt>
            <dd className="mt-1">
              <a href={row.photoUrl} target="_blank" rel="noreferrer">
                <img
                  src={row.photoUrl}
                  alt="Check-in photo"
                  className="h-10 w-10 rounded-md border border-hairline object-cover"
                />
              </a>
            </dd>
          </div>
        )}
      </dl>
      {row.checkInMethod === 'automatic' && (
        <p className="mt-3 flex items-center gap-1.5 text-xs text-white/60">
          <MapPin size={12} aria-hidden />
          Verified by GPS match against the job site.
        </p>
      )}
    </div>
  )
}

function Field({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-2xs tracking-wide text-white/80 uppercase">{label}</dt>
      <dd className={strong ? 'mt-1 text-lg font-semibold text-white' : 'mt-1 truncate text-md text-white'} title={value}>
        {value}
      </dd>
    </div>
  )
}

function TotalRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div
      className={
        strong
          ? 'flex items-center justify-between gap-3 border-t border-hairline pt-2'
          : 'flex items-center justify-between gap-3'
      }
    >
      <dt className={strong ? 'text-md font-semibold text-white' : 'text-md text-white/90'}>{label}</dt>
      <dd className={strong ? 'text-lg font-bold text-white' : 'text-md font-medium text-white/90'}>{value}</dd>
    </div>
  )
}

TimeEntryDayShow.layout = appLayout
