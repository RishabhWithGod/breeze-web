import { useState } from 'react'
import { router } from '@inertiajs/react'
import { HardHat, ShieldCheck, Users, type LucideIcon } from 'lucide-react'
import { Button, Modal, SelectField, TextArea, TextInput } from '@/components/common'
import { ROUTES, SHIFT_DURATION_OPTIONS, SHIFT_START_OPTIONS } from '@/constants'
import type { SchedulableJob } from '@/types'
import { formatDate } from '@/utils'

export interface AssignCrewModalProps {
  /** The job being booked; nothing renders when this is null. */
  job: SchedulableJob | null
  onClose: () => void
  /** Fallback day, used only when the job has no start date of its own. */
  defaultDate: string
}

/**
 * Books the job's crew onto the calendar.
 *
 * Nobody is picked here. Foremen are assigned when a job's work is broken into
 * tasks, and asking again at the booking invited a second, different answer —
 * the calendar would then say one thing and the task list another. This shows
 * who is already on the job and books them.
 *
 * The form is keyed on the job and the day, so opening a different one remounts
 * it and every field re-derives from that job. Resetting a live instance would
 * mean writing state from an effect — a cascading render, and a way for one
 * job's dates to be left sitting on another job's booking.
 */
export function AssignCrewModal({ job, onClose, defaultDate }: AssignCrewModalProps) {
  if (!job) return null

  return (
    <AssignCrewForm
      key={`${job.id}:${defaultDate}`}
      job={job}
      onClose={onClose}
      defaultDate={defaultDate}
    />
  )
}

interface AssignCrewFormProps extends Omit<AssignCrewModalProps, 'job'> {
  job: SchedulableJob
}

/** "2026-09-07T00:00:00Z" → "2026-09-07", which is what a date input reads. */
function toDateInput(iso: string | null): string {
  return iso ? iso.slice(0, 10) : ''
}

/**
 * Working days from the first day to the last, weekends left out.
 *
 * Inclusive of both ends: a job that starts and finishes on the same Monday is
 * one day of work, not none. Returns null when the pair cannot be counted —
 * a missing end date, or an end before the start — so the caller can fall back
 * rather than book a nonsense number of days.
 */
function workingDaysBetween(start: string, end: string): number | null {
  if (start === '' || end === '') return null

  const from = new Date(`${start}T00:00:00`)
  const to = new Date(`${end}T00:00:00`)

  if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime()) || to < from) return null

  let days = 0
  for (const day = new Date(from); day <= to; day.setDate(day.getDate() + 1)) {
    const weekday = day.getDay()
    if (weekday !== 0 && weekday !== 6) days += 1
  }

  // A range made entirely of weekends still takes at least one working day to
  // do, and the field will not accept a zero.
  return Math.min(30, Math.max(1, days))
}

function AssignCrewForm({ job, onClose, defaultDate }: AssignCrewFormProps) {
  /*
   * The day the job is meant to start, not today. Someone booking a job that
   * starts in three weeks should not have to retype a date the job already
   * carries — and can still change it, since a booking is not the plan.
   */
  const [date, setDate] = useState(toDateInput(job.startDate) || defaultDate)
  const [startTime, setStartTime] = useState('08:00')
  const [duration, setDuration] = useState('8')

  /*
   * How long it runs, taken from the job's own dates where it has both. Where
   * it does not, a day per eight estimated hours — capped so one field cannot
   * book a month. Either way it is a starting point the user can overwrite.
   */
  const scheduled = workingDaysBetween(toDateInput(job.startDate), toDateInput(job.endDate))
  const [days, setDays] = useState(
    String(scheduled ?? Math.min(10, Math.max(1, Math.ceil((job.estimatedHours ?? 8) / 8)))),
  )

  const [notes, setNotes] = useState('')
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = () => {
    setIsSaving(true)
    setError(null)

    router.post(
      ROUTES.schedules,
      {
        job_id: job.id,
        scheduled_date: date,
        start_time: startTime,
        duration_hours: Number(duration),
        days: Number(days),
        notes: notes.trim() === '' ? null : notes.trim(),
      },
      {
        preserveScroll: true,
        onSuccess: () => onClose(),
        onError: (errors) =>
          setError(Object.values(errors)[0] ?? 'The crew could not be booked.'),
        onFinish: () => setIsSaving(false),
      },
    )
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Book crew"
      description={`${job.name}${job.client ? ` · ${job.client}` : ''}`}
      footer={
        <div className="flex w-full items-center justify-end gap-3">
          <Button variant="secondary" onClick={onClose} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={submit} isLoading={isSaving}>
            Book crew
          </Button>
        </div>
      }
    >
      {/* ------------------------------------------------- Who is on it ---- */}
      <div className="mb-5 rounded-panel border border-hairline bg-white/4 p-4">
        <p className="mb-3 flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
          <Users size={14} aria-hidden className="text-brand" />
          {/* The shift is booked for the crew, so the crew is named first. */}
          {job.teamName ?? 'No team on this job'}
        </p>

        {job.supervisors.length === 0 && job.foremen.length === 0 ? (
          /* Said plainly rather than left blank: booking work nobody is on is
             allowed, but it should not look like an oversight in the form. */
          <p className="text-sm text-white/70">
            Nobody is on this job yet — assign a foreman to its tasks first, or book
            the days now and fill it in later.
          </p>
        ) : (
          <div className="space-y-3">
            <People label="Supervisor" icon={ShieldCheck} people={job.supervisors} />
            <People label="Foreman" icon={HardHat} people={job.foremen} />
          </div>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput
          id="assign-date"
          label="Start date"
          type="date"
          value={date}
          onChange={(event) => setDate(event.target.value)}
          {...(job.startDate
            ? { hint: `Job starts ${formatDate(job.startDate)}` }
            : {})}
        />

        <TextInput
          id="assign-days"
          label="Working days"
          type="number"
          min={1}
          max={30}
          value={days}
          onChange={(event) => setDays(event.target.value)}
          hint={
            scheduled === null
              ? 'Weekends are skipped.'
              : `From the job’s own dates. Weekends are skipped.`
          }
        />

        <SelectField
          id="assign-start"
          label="Start time"
          value={startTime}
          onChange={(event) => setStartTime(event.target.value)}
          options={SHIFT_START_OPTIONS}
        />

        <SelectField
          id="assign-duration"
          label="Shift length"
          value={duration}
          onChange={(event) => setDuration(event.target.value)}
          options={SHIFT_DURATION_OPTIONS}
        />

        <div className="sm:col-span-2">
          <TextArea
            id="assign-notes"
            label="Notes"
            rows={3}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            placeholder="Site access, materials to collect, anything the crew needs to know…"
          />
        </div>
      </div>

      {error && (
        <p role="alert" className="mt-4 text-sm text-red-300">
          {error}
        </p>
      )}
    </Modal>
  )
}

interface PeopleProps {
  label: string
  icon: LucideIcon
  people: readonly { readonly name: string; readonly initials: string }[]
}

/** One role's worth of names, or nothing at all when there are none. */
function People({ label, icon: Icon, people }: PeopleProps) {
  if (people.length === 0) return null

  return (
    <div>
      <p className="mb-1.5 flex items-center gap-1.5 text-sm text-white/70">
        <Icon size={13} aria-hidden />
        {label}
      </p>
      <div className="flex flex-wrap gap-2">
        {people.map((person) => (
          <span
            key={person.name}
            className="inline-flex items-center gap-2 rounded-full border border-hairline bg-white/6 py-1 pr-3 pl-1 text-sm text-white"
          >
            <span className="grid size-6 place-items-center rounded-full bg-brand/20 text-xs font-semibold text-brand">
              {person.initials}
            </span>
            {person.name}
          </span>
        ))}
      </div>
    </div>
  )
}
