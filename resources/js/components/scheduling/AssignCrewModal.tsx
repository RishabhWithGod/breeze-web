import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Button, Modal, SelectField, TextArea, TextInput } from '@/components/common'
import { ROUTES, SHIFT_DURATION_OPTIONS, SHIFT_START_OPTIONS } from '@/constants'
import type { CrewMember, SchedulableJob } from '@/types'

export interface AssignCrewModalProps {
  /** The job being booked; nothing renders when this is null. */
  job: SchedulableJob | null
  onClose: () => void
  crews: readonly string[]
  members: readonly CrewMember[]
  /** Pre-selected day, when the form was opened from a calendar cell. */
  defaultDate: string
}

/**
 * Books a crew onto a job.
 *
 * The form is keyed on the job and the day, so opening a different one remounts it
 * and every field re-derives from that job. Resetting a live instance instead would
 * mean writing state from an effect — a cascading render, and a way for one job's
 * crew to be left sitting on another job's booking.
 */
export function AssignCrewModal({
  job,
  onClose,
  crews,
  members,
  defaultDate,
}: AssignCrewModalProps) {
  if (!job) return null

  return (
    <AssignCrewForm
      key={`${job.id}:${defaultDate}`}
      job={job}
      onClose={onClose}
      crews={crews}
      members={members}
      defaultDate={defaultDate}
    />
  )
}

interface AssignCrewFormProps extends Omit<AssignCrewModalProps, 'job'> {
  job: SchedulableJob
}

function AssignCrewForm({
  job,
  onClose,
  crews,
  members,
  defaultDate,
}: AssignCrewFormProps) {
  const [crew, setCrew] = useState(crews[0] ?? 'Team A')
  const [memberId, setMemberId] = useState('')
  const [date, setDate] = useState(defaultDate)
  const [startTime, setStartTime] = useState('08:00')
  const [duration, setDuration] = useState('8')
  // A day per eight estimated hours, capped so one field cannot book a month.
  const [days, setDays] = useState(
    String(Math.min(10, Math.max(1, Math.ceil((job.estimatedHours ?? 8) / 8)))),
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
        team_member_id: memberId === '' ? null : Number(memberId),
        crew,
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
      title="Assign crew"
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
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField
          id="assign-crew"
          label="Crew"
          value={crew}
          onChange={(event) => setCrew(event.target.value)}
          options={crews.map((name) => ({ label: name, value: name }))}
        />

        <SelectField
          id="assign-member"
          label="Crew lead"
          value={memberId}
          onChange={(event) => setMemberId(event.target.value)}
          options={[
            { label: 'Unassigned', value: '' },
            ...members.map((member) => ({
              label: member.role ? `${member.name} — ${member.role}` : member.name,
              value: String(member.id),
            })),
          ]}
        />

        <TextInput
          id="assign-date"
          label="Start date"
          type="date"
          value={date}
          onChange={(event) => setDate(event.target.value)}
        />

        <TextInput
          id="assign-days"
          label="Working days"
          type="number"
          min={1}
          max={30}
          value={days}
          onChange={(event) => setDays(event.target.value)}
          hint="Weekends are skipped."
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
