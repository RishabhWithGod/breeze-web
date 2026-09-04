import { useCallback, useMemo, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { ArrowLeft, ChevronLeft, ChevronRight, SlidersHorizontal } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  SelectField,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  AssignCrewModal,
  CalendarGrid,
  ShiftDetailModal,
  UnassignedJobCard,
} from '@/components/scheduling'
import { ROUTES } from '@/constants'
import { useDisclosure } from '@/hooks'
import type {
  CalendarDay,
  CalendarView,
  CrewMember,
  JobShift,
  SchedulableJob,
  SharedPageProps,
} from '@/types'
import { cn } from '@/utils'

interface CalendarFilters {
  crew: string
  member: string
}

export interface SchedulingProps {
  view: CalendarView
  /** Any day inside the visible window. */
  anchor: string
  today: string
  periodLabel: string
  range: { from: string; to: string }
  days: readonly CalendarDay[]
  shifts: readonly JobShift[]
  unassigned: readonly SchedulableJob[]
  unassignedTotal: number
  crews: readonly string[]
  members: readonly CrewMember[]
  filters: CalendarFilters
}

/**
 * Scheduling Calendar — who is on what, and when.
 *
 * The visible window lives in the query string, so paging the calendar is a server
 * visit and the URL is shareable. Every date decision is the server's: it sends the
 * grid already labelled, which is what stops the browser's timezone from moving a
 * shift onto the wrong day.
 */
export default function Scheduling({
  view,
  anchor,
  today,
  periodLabel,
  range,
  days,
  shifts,
  unassigned,
  unassignedTotal,
  crews,
  members,
  filters,
}: SchedulingProps) {
  const { flash } = usePage<SharedPageProps>().props

  const [assigning, setAssigning] = useState<SchedulableJob | null>(null)
  const [assignDate, setAssignDate] = useState(today)
  const [inspecting, setInspecting] = useState<JobShift | null>(null)
  const [pendingRemoval, setPendingRemoval] = useState<JobShift | null>(null)

  const filterPanel = useDisclosure(filters.crew !== '' || filters.member !== '')
  const removalDialog = useDisclosure()

  /** Every calendar navigation is the same visit with a different window. */
  const goTo = useCallback(
    (next: Partial<{ view: CalendarView; date: string; crew: string; member: string }>) => {
      router.get(
        ROUTES.schedulingCalendar,
        {
          view: next.view ?? view,
          date: next.date ?? anchor,
          ...(next.crew ?? filters.crew ? { crew: next.crew ?? filters.crew } : {}),
          ...(next.member ?? filters.member
            ? { member: next.member ?? filters.member }
            : {}),
        },
        { preserveScroll: true, preserveState: true, replace: true },
      )
    },
    [view, anchor, filters.crew, filters.member],
  )

  /**
   * Steps the window by one period.
   *
   * Derived from the range the server sent rather than counted locally, so a month
   * view never lands on the wrong month by adding 30 days to a 31-day month.
   */
  const step = useCallback(
    (direction: -1 | 1) => {
      const base = new Date(`${range.from}T00:00:00`)

      if (view === 'month') {
        // Anchored on the 15th: stepping from a 31st would skip a short month.
        const anchored = new Date(`${anchor}T00:00:00`)
        anchored.setDate(15)
        anchored.setMonth(anchored.getMonth() + direction)
        goTo({ date: anchored.toISOString().slice(0, 10) })
        return
      }

      base.setDate(base.getDate() + direction * 7)
      goTo({ date: base.toISOString().slice(0, 10) })
    },
    [range.from, view, anchor, goTo],
  )

  const openAssign = useCallback(
    (job: SchedulableJob, date?: string) => {
      setAssignDate(date ?? today)
      setAssigning(job)
    },
    [today],
  )

  /**
   * Adding from a specific day.
   *
   * With nothing left to book there is no job to attach the shift to, so the day's
   * plus button sends the user to the queue rather than opening an empty form.
   */
  const addOnDay = useCallback(
    (date: string) => {
      const next = unassigned[0]

      if (!next) {
        router.get(ROUTES.scheduling)
        return
      }

      openAssign(next, date)
    },
    [unassigned, openAssign],
  )

  const requestRemoval = useCallback(
    (shift: JobShift) => {
      setInspecting(null)
      setPendingRemoval(shift)
      removalDialog.open()
    },
    [removalDialog],
  )

  const confirmRemoval = useCallback(() => {
    if (!pendingRemoval) return

    router.delete(`${ROUTES.schedules}/${pendingRemoval.id}`, {
      preserveScroll: true,
      onFinish: () => {
        setPendingRemoval(null)
        removalDialog.close()
      },
    })
  }, [pendingRemoval, removalDialog])

  const shiftCount = shifts.length
  const bookedHours = useMemo(
    () => shifts.reduce((total, shift) => total + shift.durationHours, 0),
    [shifts],
  )

  return (
    <PageTransition>
      <Head title="Scheduling Calendar" />

      <PageHeader
        title="Scheduling Calendar"
        subtitle="Manage and assign your crew to jobs efficiently"
        actions={
          <>
            <Button
              variant="secondary"
              leftIcon={SlidersHorizontal}
              onClick={filterPanel.toggle}
              aria-expanded={filterPanel.isOpen}
            >
              Filters
            </Button>
            <ButtonLink href={ROUTES.scheduling} variant="secondary" leftIcon={ArrowLeft}>
              Back
            </ButtonLink>
          </>
        }
      />

      {flash?.success && (
        <Alert tone="success" className="mb-6">
          {flash.success}
        </Alert>
      )}
      {flash?.warning && (
        <Alert tone="warning" className="mb-6">
          {flash.warning}
        </Alert>
      )}

      {filterPanel.isOpen && (
        <Card padding="md" className="mb-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {/*
              A shift is labelled with the team it was booked for, so this is a
              team filter. Older shifts carry whatever label they were booked
              with, and those are offered too — a filter that cannot select what
              is on the calendar is a filter that lies.
            */}
            <SelectField
              id="filter-crew"
              label="Team"
              value={filters.crew}
              onChange={(event) => goTo({ crew: event.target.value })}
              options={[
                { label: 'All teams', value: '' },
                ...crews.map((crew) => ({ label: crew, value: crew })),
              ]}
            />
            <SelectField
              id="filter-member"
              label="Crew lead"
              value={filters.member}
              onChange={(event) => goTo({ member: event.target.value })}
              options={[
                { label: 'Anyone', value: '' },
                ...members.map((member) => ({
                  label: member.name,
                  value: String(member.id),
                })),
              ]}
            />
            <div className="flex items-end">
              <Button
                variant="secondary"
                fullWidth
                onClick={() =>
                  router.get(
                    ROUTES.schedulingCalendar,
                    { view, date: anchor },
                    { preserveScroll: true, replace: true },
                  )
                }
              >
                Clear filters
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Card padding="md" className="mb-8">
        {/* Period, then the view switch — the order the reference reads in. */}
        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => step(-1)}
              aria-label={view === 'month' ? 'Previous month' : 'Previous week'}
              className="grid size-9 place-items-center rounded-panel border border-hairline text-white transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-white"
            >
              <ChevronLeft size={17} aria-hidden />
            </button>
            <h2 className="min-w-[13ch] text-lg font-semibold text-white">{periodLabel}</h2>
            <button
              type="button"
              onClick={() => step(1)}
              aria-label={view === 'month' ? 'Next month' : 'Next week'}
              className="grid size-9 place-items-center rounded-panel border border-hairline text-white transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-white"
            >
              <ChevronRight size={17} aria-hidden />
            </button>
          </div>

          <div className="flex items-center gap-1.5" role="group" aria-label="Calendar view">
            {(['month', 'week'] as const).map((option) => (
              <button
                key={option}
                type="button"
                aria-pressed={view === option}
                onClick={() => goTo({ view: option })}
                className={cn(
                  'rounded-panel border px-4 py-1.5 text-md font-medium capitalize transition-colors',
                  view === option
                    ? 'border-brand bg-brand text-brand-ink'
                    : 'border-hairline text-white hover:border-brand/60 hover:bg-white/10 hover:text-white',
                )}
              >
                {option}
              </button>
            ))}
            <button
              type="button"
              onClick={() => goTo({ date: today })}
              className="rounded-panel border border-hairline px-4 py-1.5 text-md font-medium text-white transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-white"
            >
              Today
            </button>
          </div>
        </div>

        <CalendarGrid
          days={days}
          shifts={shifts}
          onSelectShift={setInspecting}
          onRemoveShift={requestRemoval}
          onAddOnDay={addOnDay}
        />

        <p className="mt-4 text-sm text-white/80">
          {shiftCount === 0
            ? 'No crew booked in this period.'
            : `${shiftCount} ${shiftCount === 1 ? 'shift' : 'shifts'} · ${bookedHours} crew hours booked.`}
        </p>
      </Card>

      <section aria-labelledby="unassigned-heading">
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
          <h2 id="unassigned-heading" className="text-3xl font-semibold text-white">
            Unassigned Jobs
          </h2>
          {unassignedTotal > unassigned.length && (
            <Link
              href={ROUTES.scheduling}
              className="text-md font-medium text-brand transition-colors hover:text-white"
            >
              View all {unassignedTotal} →
            </Link>
          )}
        </div>

        {unassigned.length === 0 ? (
          <EmptyState
            title="Every job has a crew"
            description="Nothing is waiting to be scheduled. New work will appear here as soon as it is raised."
          />
        ) : (
          <div className="grid gap-5 lg:grid-cols-3">
            {unassigned.map((job, index) => (
              <UnassignedJobCard
                key={job.id}
                job={job}
                index={index}
                onAssign={(target) => openAssign(target)}
              />
            ))}
          </div>
        )}
      </section>

      <AssignCrewModal
        job={assigning}
        onClose={() => setAssigning(null)}
        defaultDate={assignDate}
      />

      <ShiftDetailModal
        shift={inspecting}
        onClose={() => setInspecting(null)}
        onRemove={requestRemoval}
      />

      <ConfirmDialog
        isOpen={removalDialog.isOpen}
        title="Remove this shift?"
        description={
          pendingRemoval
            ? `${pendingRemoval.crew} will be released from ${pendingRemoval.jobName} on ${pendingRemoval.date}. If it is the job's only shift it returns to the unassigned queue.`
            : ''
        }
        confirmLabel="Remove shift"
        confirmVariant="danger"
        onConfirm={confirmRemoval}
        onCancel={() => {
          setPendingRemoval(null)
          removalDialog.close()
        }}
      />
    </PageTransition>
  )
}

Scheduling.layout = appLayout
