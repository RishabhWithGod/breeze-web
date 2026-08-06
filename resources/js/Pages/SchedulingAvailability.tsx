import { useCallback, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import {
  AlertTriangle,
  CalendarDays,
  CalendarPlus,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Clock,
  Gauge,
  Users,
} from 'lucide-react'
import { Alert, Badge, Button, Card, EmptyState, ProgressBar } from '@/components/common'
import { StatCard } from '@/components/dashboard'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { CrewHoursBreakdown } from '@/components/scheduling'
import { ROUTES, SCHEDULE_STATUS_LABEL, SCHEDULE_STATUS_TONE, routeTo } from '@/constants'
import type {
  AvailabilitySummary,
  AvailabilityTab,
  CalendarView,
  CrewAvailability,
  CrewMember,
  CrewTotal,
  MemberAssignment,
  ScheduleConflict,
  SchedulingActivity,
} from '@/types'
import { cn, formatRelative } from '@/utils'

const TABS: readonly { label: string; value: AvailabilityTab }[] = [
  { label: 'Overview', value: 'overview' },
  { label: 'By Crew', value: 'crews' },
  { label: 'Conflicts', value: 'conflicts' },
]

export interface SchedulingAvailabilityProps {
  view: CalendarView
  tab: AvailabilityTab
  anchor: string
  today: string
  periodLabel: string
  range: { from: string; to: string }
  summary: AvailabilitySummary
  availability: readonly CrewAvailability[]
  /** Each member's shifts in the window, keyed by member id. */
  assignments: Record<number, readonly MemberAssignment[]>
  crewTotals: readonly CrewTotal[]
  conflicts: readonly ScheduleConflict[]
  activity: readonly SchedulingActivity[]
  members: readonly CrewMember[]
  crews: readonly string[]
}

/**
 * Crew Availability — who has room, who is full, and who cannot be in two places.
 *
 * The window is the calendar's own, driven by the query string, so the two screens
 * never disagree about which days are being counted. Capacity is eight hours per
 * working day in that window; a person booked past it is called out rather than
 * left as a full bar, because a bar at 100% cannot tell "exactly full" from
 * "double-booked".
 */
export default function SchedulingAvailability({
  view,
  tab,
  anchor,
  today,
  periodLabel,
  range,
  summary,
  availability,
  assignments,
  crewTotals,
  conflicts,
  activity,
  members,
  crews,
}: SchedulingAvailabilityProps) {
  const [expanded, setExpanded] = useState<number | null>(null)

  const goTo = useCallback(
    (next: Partial<{ view: CalendarView; date: string; tab: AvailabilityTab }>) => {
      router.get(
        ROUTES.schedulingAvailability,
        {
          view: next.view ?? view,
          date: next.date ?? anchor,
          tab: next.tab ?? tab,
        },
        { preserveScroll: true, preserveState: true, replace: true },
      )
    },
    [view, anchor, tab],
  )

  /** Steps the window by one period, using the range the server sent. */
  const step = useCallback(
    (direction: -1 | 1) => {
      if (view === 'month') {
        // Anchored on the 15th: stepping from a 31st would skip a short month.
        const anchored = new Date(`${anchor}T00:00:00`)
        anchored.setDate(15)
        anchored.setMonth(anchored.getMonth() + direction)
        goTo({ date: anchored.toISOString().slice(0, 10) })
        return
      }

      const base = new Date(`${range.from}T00:00:00`)
      base.setDate(base.getDate() + direction * 7)
      goTo({ date: base.toISOString().slice(0, 10) })
    },
    [view, anchor, range.from, goTo],
  )

  return (
    <PageTransition>
      <Head title="Crew Availability" />

      <PageHeader
        title="Crew Availability"
        subtitle={`${summary.members} crew · ${periodLabel}`}
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={CalendarPlus}
              onClick={() => router.get(ROUTES.scheduling)}
            >
              Assign Crew
            </Button>
            <Button
              leftIcon={CalendarDays}
              onClick={() => router.get(ROUTES.schedulingCalendar, { view, date: anchor })}
            >
              Go to Calendar
            </Button>
          </>
        }
      />

      {summary.overbooked > 0 && (
        <Alert tone="warning" className="mb-6" title="Someone is over-booked">
          {summary.overbooked}{' '}
          {summary.overbooked === 1 ? 'crew member is' : 'crew members are'} booked past
          their capacity for {periodLabel}.
        </Alert>
      )}

      {/* Headline figures, mirroring the job screen's stat row. */}
      <div className="mb-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          index={0}
          icon={Users}
          label="Crew Members"
          value={String(summary.members)}
          hint={summary.idle > 0 ? `${summary.idle} with nothing booked` : 'All have work'}
        />
        <StatCard
          index={1}
          icon={Clock}
          label="Hours Booked"
          value={`${summary.bookedHours} h`}
          hint={`${summary.shifts} ${summary.shifts === 1 ? 'shift' : 'shifts'}`}
        />
        <StatCard
          index={2}
          icon={ClipboardList}
          label="Capacity"
          value={`${summary.capacityHours} h`}
          hint={`${summary.availableHours} h still free`}
          tone="info"
        />
        <StatCard
          index={3}
          icon={Gauge}
          label="Utilisation"
          value={`${summary.utilisation}%`}
          hint={summary.overbooked > 0 ? `${summary.overbooked} over-booked` : 'Within capacity'}
          tone={summary.overbooked > 0 ? 'warning' : 'success'}
        />
      </div>

      {/* Period control + tabs, in the job screen's tab-bar position. */}
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div
          role="tablist"
          aria-label="Availability view"
          className="flex flex-wrap items-center gap-1.5"
        >
          {TABS.map((option) => {
            const isActive = option.value === tab
            const count =
              option.value === 'conflicts'
                ? conflicts.length
                : option.value === 'crews'
                  ? crewTotals.length
                  : availability.length

            return (
              <button
                key={option.value}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => goTo({ tab: option.value })}
                className={cn(
                  'inline-flex items-center gap-2 rounded-panel border px-4 py-2 text-md font-medium transition-colors',
                  isActive
                    ? 'border-brand bg-brand text-brand-ink'
                    : 'border-hairline text-white/75 hover:border-brand/60 hover:bg-white/10 hover:text-white',
                )}
              >
                {option.label}
                <span
                  className={cn(
                    'rounded-full px-2 py-0.5 text-2xs font-semibold',
                    isActive ? 'bg-navy-950/30 text-white' : 'bg-white/10 text-white/70',
                  )}
                >
                  {count}
                </span>
              </button>
            )
          })}
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => step(-1)}
            aria-label={view === 'month' ? 'Previous month' : 'Previous week'}
            className="grid size-9 place-items-center rounded-panel border border-hairline text-white/75 transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-white"
          >
            <ChevronLeft size={17} aria-hidden />
          </button>
          <span className="min-w-[13ch] text-center text-md font-semibold text-white">
            {periodLabel}
          </span>
          <button
            type="button"
            onClick={() => step(1)}
            aria-label={view === 'month' ? 'Next month' : 'Next week'}
            className="grid size-9 place-items-center rounded-panel border border-hairline text-white/75 transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-white"
          >
            <ChevronRight size={17} aria-hidden />
          </button>

          {(['week', 'month'] as const).map((option) => (
            <button
              key={option}
              type="button"
              aria-pressed={view === option}
              onClick={() => goTo({ view: option })}
              className={cn(
                'rounded-panel border px-3 py-1.5 text-md font-medium capitalize transition-colors',
                view === option
                  ? 'border-brand bg-brand text-brand-ink'
                  : 'border-hairline text-white/75 hover:border-brand/60 hover:bg-white/10 hover:text-white',
              )}
            >
              {option}
            </button>
          ))}
          <button
            type="button"
            onClick={() => goTo({ date: today })}
            className="rounded-panel border border-hairline px-3 py-1.5 text-md font-medium text-white/75 transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-white"
          >
            Today
          </button>
        </div>
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        {/* Left column — the tab's own content, plus the activity trail. */}
        <div className="space-y-6 xl:col-span-2">
          {tab === 'overview' && (
            <Card padding="md">
              <h2 className="mb-5 text-xl font-semibold text-white">Crew load</h2>

              <ul className="space-y-3">
                {availability.map((member) => {
                  const shifts = assignments[member.id] ?? []
                  const isOpen = expanded === member.id

                  return (
                    <li
                      key={member.id}
                      className="rounded-panel border border-hairline bg-white/6"
                    >
                      <button
                        type="button"
                        onClick={() => setExpanded(isOpen ? null : member.id)}
                        aria-expanded={isOpen}
                        className="w-full p-4 text-left focus-visible:outline-none"
                      >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                          <span className="flex min-w-0 items-center gap-3">
                            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-ocean-800 text-xs font-semibold text-white ring-1 ring-steel-600">
                              {member.initials}
                            </span>
                            <span className="min-w-0">
                              <span className="block truncate font-semibold text-white">
                                {member.name}
                              </span>
                              {member.role && (
                                <span className="block truncate text-sm text-white/55">
                                  {member.role}
                                </span>
                              )}
                            </span>
                          </span>

                          <span className="flex items-center gap-4">
                            {member.shifts === 0 && <Badge tone="neutral">Free</Badge>}
                            {member.isOverbooked && (
                              <Badge tone="warning" icon={AlertTriangle}>
                                Over-booked
                              </Badge>
                            )}
                            <span className="text-right">
                              <span className="block font-semibold text-white tabular-nums">
                                {member.hours}/{member.capacity} h
                              </span>
                              <span className="block text-xs text-white/50">
                                {member.shifts} {member.shifts === 1 ? 'shift' : 'shifts'}
                              </span>
                            </span>
                          </span>
                        </div>

                        <ProgressBar
                          value={member.utilisation}
                          tone={
                            member.isOverbooked
                              ? 'danger'
                              : member.utilisation > 80
                                ? 'warning'
                                : 'brand'
                          }
                          className="mt-3"
                        />
                      </button>

                      {isOpen && (
                        <div className="border-t border-hairline p-4">
                          {shifts.length === 0 ? (
                            <p className="text-md text-white/55">
                              Nothing booked in this period.
                            </p>
                          ) : (
                            <ul className="space-y-2">
                              {shifts.map((shift) => (
                                <li
                                  key={shift.id}
                                  className="flex flex-wrap items-center justify-between gap-3 rounded-panel bg-white/6 px-3 py-2"
                                >
                                  <span className="min-w-0">
                                    <Link
                                      href={routeTo.job(shift.jobId)}
                                      className="block truncate font-medium text-white transition-colors hover:text-brand"
                                    >
                                      {shift.jobName}
                                    </Link>
                                    <span className="block text-xs text-white/55">
                                      {shift.dayLabel} · {shift.startLabel} – {shift.endLabel}
                                    </span>
                                  </span>
                                  <span className="flex shrink-0 items-center gap-2">
                                    <Badge tone="neutral" size="sm">
                                      {shift.crew}
                                    </Badge>
                                    <Badge
                                      tone={SCHEDULE_STATUS_TONE[shift.status]}
                                      size="sm"
                                    >
                                      {SCHEDULE_STATUS_LABEL[shift.status]}
                                    </Badge>
                                  </span>
                                </li>
                              ))}
                            </ul>
                          )}
                        </div>
                      )}
                    </li>
                  )
                })}
              </ul>
            </Card>
          )}

          {tab === 'crews' && (
            <Card padding="md">
              <h2 className="mb-5 text-xl font-semibold text-white">Crews</h2>

              {crewTotals.length === 0 ? (
                <EmptyState
                  size="sm"
                  title="No crew booked"
                  description={`Nothing is scheduled for ${periodLabel}.`}
                />
              ) : (
                <ul className="space-y-3">
                  {crewTotals.map((crew) => (
                    <li
                      key={crew.crew}
                      className="rounded-panel border border-hairline bg-white/6 p-4"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold text-white">{crew.crew}</h3>
                        <span className="font-semibold text-white tabular-nums">
                          {crew.hours} h
                          <span className="ml-2 text-sm font-normal text-white/55">
                            {crew.share}% of booked
                          </span>
                        </span>
                      </div>

                      <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                          <dt className="text-sm text-white/50">Shifts</dt>
                          <dd className="mt-0.5 font-semibold text-white tabular-nums">
                            {crew.shifts}
                          </dd>
                        </div>
                        <div>
                          <dt className="text-sm text-white/50">People</dt>
                          <dd className="mt-0.5 font-semibold text-white tabular-nums">
                            {crew.members}
                          </dd>
                        </div>
                        <div>
                          <dt className="text-sm text-white/50">Jobs</dt>
                          <dd className="mt-0.5 font-semibold text-white tabular-nums">
                            {crew.jobs}
                          </dd>
                        </div>
                        <div>
                          <dt className="text-sm text-white/50">Capacity</dt>
                          <dd className="mt-0.5 font-semibold text-white tabular-nums">
                            {crew.capacity} h
                          </dd>
                        </div>
                      </dl>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}

          {tab === 'conflicts' && (
            <Card padding="md">
              <h2 className="mb-2 text-xl font-semibold text-white">Double bookings</h2>
              <p className="mb-5 text-md text-white/55">
                Two shifts whose times overlap for the same person on the same day. Two
                bookings in one day are normal; these are the ones nobody can be at.
              </p>

              {conflicts.length === 0 ? (
                <EmptyState
                  size="sm"
                  title="No clashes"
                  description={`Every crew member's shifts fit around each other for ${periodLabel}.`}
                />
              ) : (
                <ul className="space-y-3">
                  {conflicts.map((conflict) => (
                    <li
                      key={conflict.id}
                      className="rounded-panel border border-status-warning/40 bg-status-warning/8 p-4"
                    >
                      <div className="flex flex-wrap items-center justify-between gap-3">
                        <span className="flex items-center gap-2.5">
                          <AlertTriangle
                            size={16}
                            aria-hidden
                            className="text-status-warning"
                          />
                          <span className="font-semibold text-white">
                            {conflict.member?.name ?? 'Unnamed crew'}
                          </span>
                          <span className="text-sm text-white/60">{conflict.dayLabel}</span>
                        </span>
                        <Badge tone="warning">
                          {conflict.overlapMinutes} min overlap
                        </Badge>
                      </div>

                      <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {[conflict.first, conflict.second].map((side) => (
                          <Link
                            key={side.id}
                            href={routeTo.job(side.jobId)}
                            className="rounded-panel bg-navy-950/30 px-3 py-2 transition-colors hover:bg-navy-950/50"
                          >
                            <span className="block truncate font-medium text-white">
                              {side.jobName}
                            </span>
                            <span className="block text-xs text-white/60">
                              {side.crew} · {side.startLabel} – {side.endLabel}
                            </span>
                          </Link>
                        ))}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}

          <Card padding="md">
            <div className="mb-5 flex items-center justify-between gap-3">
              <h2 className="text-xl font-semibold text-white">Recent Activity</h2>
              <Link
                href={ROUTES.scheduling}
                className="text-sm font-medium text-brand transition-colors hover:text-white"
              >
                Unassigned queue →
              </Link>
            </div>

            {activity.length === 0 ? (
              <p className="text-md text-white/55">
                No crew has been booked or released yet.
              </p>
            ) : (
              <ul className="space-y-2.5">
                {activity.map((entry) => (
                  <li
                    key={entry.id}
                    className="flex items-start gap-3 rounded-panel bg-white/6 px-4 py-3"
                  >
                    <span
                      aria-hidden
                      className={cn(
                        'mt-0.5 grid size-7 shrink-0 place-items-center rounded-full',
                        entry.type === 'scheduled'
                          ? 'bg-brand/20 text-brand'
                          : 'bg-status-warning/20 text-status-warning',
                      )}
                    >
                      <CalendarDays size={13} />
                    </span>
                    <span className="min-w-0">
                      <Link
                        href={routeTo.job(entry.jobId)}
                        className="block truncate font-semibold text-white transition-colors hover:text-brand"
                      >
                        {entry.jobName}
                      </Link>
                      <span className="block text-sm text-white/65">
                        {entry.description}
                      </span>
                      {entry.at && (
                        <span className="mt-0.5 block text-xs text-white/45">
                          {formatRelative(entry.at)}
                        </span>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        {/* Right column — utilisation, the crew split, and the roster. */}
        <div className="space-y-6">
          <Card padding="md">
            <h2 className="mb-1 text-xl font-semibold text-white">Utilisation</h2>
            <p className="mb-5 text-sm text-white/55">
              Booked against eight hours per working day.
            </p>

            <ul className="space-y-4">
              {availability.map((member) => (
                <li key={member.id}>
                  <div className="flex items-center justify-between gap-3">
                    <span className="truncate text-md text-white/80">{member.name}</span>
                    <span
                      className={cn(
                        'shrink-0 text-md font-semibold tabular-nums',
                        member.isOverbooked ? 'text-status-warning' : 'text-white',
                      )}
                    >
                      {member.utilisation}%
                    </span>
                  </div>
                  <ProgressBar
                    value={member.utilisation}
                    tone={
                      member.isOverbooked
                        ? 'danger'
                        : member.utilisation > 80
                          ? 'warning'
                          : 'brand'
                    }
                    className="mt-2"
                  />
                </li>
              ))}
            </ul>
          </Card>

          <Card padding="md">
            <h2 className="mb-1 text-xl font-semibold text-white">Hours by crew</h2>
            <p className="mb-5 text-sm text-white/55">
              How {summary.bookedHours} booked hours are split.
            </p>

            <CrewHoursBreakdown totals={crewTotals} />

            <dl className="mt-6 grid grid-cols-2 gap-4 border-t border-hairline pt-5">
              <div>
                <dt className="text-sm text-white/50">Booked</dt>
                <dd className="mt-0.5 text-lg font-semibold text-white tabular-nums">
                  {summary.bookedHours} h
                </dd>
              </div>
              <div>
                <dt className="text-sm text-white/50">Available</dt>
                <dd className="mt-0.5 text-lg font-semibold text-white tabular-nums">
                  {summary.availableHours} h
                </dd>
              </div>
              <div>
                <dt className="text-sm text-white/50">Crews</dt>
                <dd className="mt-0.5 text-lg font-semibold text-white tabular-nums">
                  {crews.length}
                </dd>
              </div>
              <div>
                <dt className="text-sm text-white/50">Unnamed shifts</dt>
                <dd className="mt-0.5 text-lg font-semibold text-white tabular-nums">
                  {summary.unnamedShifts}
                </dd>
              </div>
            </dl>
          </Card>

          <Card padding="md">
            <h2 className="mb-5 text-xl font-semibold text-white">Team</h2>
            <ul className="space-y-4">
              {members.map((member) => (
                <li key={member.id} className="flex items-center gap-3">
                  <span className="grid size-9 shrink-0 place-items-center rounded-full bg-ocean-800 text-xs font-semibold text-white ring-1 ring-steel-600">
                    {member.initials}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate font-semibold text-white">
                      {member.name}
                    </span>
                    <span className="block truncate text-sm text-white/55">
                      {member.role ?? 'Crew'}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
    </PageTransition>
  )
}

SchedulingAvailability.layout = appLayout
