import { useForm } from '@inertiajs/react'
import { Save } from 'lucide-react'
import { Button, Modal, SelectField, TextInput } from '@/components/common'
import { ISO_WEEKDAYS, SCHEDULE_STATE_LABEL } from '@/constants'
import { routeTo } from '@/constants'
import type { JobScheduleState } from '@/types'

export interface ScheduleSettingsModalProps {
  isOpen: boolean
  onClose: () => void
  jobId: number
  schedule: JobScheduleState
  scheduleStatuses: readonly string[]
}

export function ScheduleSettingsModal({ isOpen, onClose, jobId, schedule, scheduleStatuses }: ScheduleSettingsModalProps) {
  const { data, setData, put, processing, errors } = useForm({
    starts_on: schedule.startsOn ?? '',
    ends_on: schedule.endsOn ?? '',
    working_days: schedule.workingDays as readonly number[],
    work_start_time: schedule.workStartTime,
    work_end_time: schedule.workEndTime,
    break_minutes: String(schedule.breakMinutes),
    timezone: schedule.timezone,
    holidays: schedule.holidays as readonly string[],
    status: schedule.status,
    notes: schedule.notes ?? '',
  })

  const toggleDay = (day: number) => {
    setData('working_days', data.working_days.includes(day) ? data.working_days.filter((d) => d !== day) : [...data.working_days, day])
  }

  const submit = () => {
    put(routeTo.jobSchedule(jobId), { preserveScroll: true, onSuccess: onClose })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Schedule Settings"
      size="lg"
      footer={
        <>
          <Button variant="white" onClick={onClose}>
            Cancel
          </Button>
          <Button leftIcon={Save} isLoading={processing} onClick={submit}>
            Save Changes
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <TextInput id="sched-starts" type="date" label="Starts On" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} error={errors.starts_on} />
          <TextInput id="sched-ends" type="date" label="Ends On" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} error={errors.ends_on} />
        </div>

        <div>
          <p className="mb-2 text-md font-medium text-white">Working Days</p>
          <div className="flex flex-wrap gap-2">
            {ISO_WEEKDAYS.map((day) => (
              <button
                key={day.value}
                type="button"
                onClick={() => toggleDay(day.value)}
                className={`rounded-pill px-3 py-1.5 text-sm font-medium transition-colors ${
                  data.working_days.includes(day.value) ? 'bg-brand text-brand-ink' : 'bg-white/10 text-white/80 hover:bg-white/20'
                }`}
              >
                {day.label}
              </button>
            ))}
          </div>
          {errors.working_days && <p className="mt-2 text-sm text-red-300">{errors.working_days}</p>}
        </div>

        <div className="grid grid-cols-3 gap-4">
          <TextInput id="sched-start-time" type="time" label="Work Start" value={data.work_start_time} onChange={(e) => setData('work_start_time', e.target.value)} error={errors.work_start_time} />
          <TextInput id="sched-end-time" type="time" label="Work End" value={data.work_end_time} onChange={(e) => setData('work_end_time', e.target.value)} error={errors.work_end_time} />
          <TextInput id="sched-break" type="number" min={0} label="Break (min)" value={data.break_minutes} onChange={(e) => setData('break_minutes', e.target.value)} error={errors.break_minutes} />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <TextInput id="sched-timezone" label="Timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)} error={errors.timezone} />
          <SelectField
            id="sched-status"
            label="Status"
            options={scheduleStatuses.map((s) => ({ label: SCHEDULE_STATE_LABEL[s as keyof typeof SCHEDULE_STATE_LABEL] ?? s, value: s }))}
            value={data.status}
            onChange={(e) => setData('status', e.target.value as JobScheduleState['status'])}
            error={errors.status}
          />
        </div>

        <TextInput id="sched-notes" label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} />
      </div>
    </Modal>
  )
}
