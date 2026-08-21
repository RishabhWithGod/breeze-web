import { useEffect, useState } from 'react'
import { useForm } from '@inertiajs/react'
import { Play } from 'lucide-react'
import { Button, Checkbox, Modal, SelectField, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { TimeTrackingJobOption, TimeTrackingTaskOption } from '@/types'

export interface StartTimerModalProps {
  isOpen: boolean
  onClose: () => void
  jobs: readonly TimeTrackingJobOption[]
}

/**
 * The one place a timer is started — the job/task pair a timer needs isn't
 * something a header widget can ask for, so it lives here rather than on
 * `TimerIndicator`, which only ever controls a timer already running.
 */
export function StartTimerModal({ isOpen, onClose, jobs }: StartTimerModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    job_id: jobs[0] ? String(jobs[0].id) : '',
    job_task_id: '',
    task_label: '',
    description: '',
    billable: true,
  })
  const [tasks, setTasks] = useState<readonly TimeTrackingTaskOption[]>([])

  // The task list belongs to a specific job — reset it during render when the
  // job changes (not in the effect below, which only owns the async fetch).
  const [loadedForJobId, setLoadedForJobId] = useState(data.job_id)
  if (data.job_id !== loadedForJobId) {
    setLoadedForJobId(data.job_id)
    setTasks([])
  }

  useEffect(() => {
    if (!data.job_id) return undefined

    let cancelled = false
    fetch(routeTo.jobTimeEntryTasks(Number(data.job_id)), { headers: { Accept: 'application/json' } })
      .then((response) => response.json())
      .then((result: readonly TimeTrackingTaskOption[]) => {
        if (!cancelled) setTasks(result)
      })
      .catch(() => {
        if (!cancelled) setTasks([])
      })

    return () => {
      cancelled = true
    }
  }, [data.job_id])

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    post(routeTo.timerStart, { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title="Start Timer"
      description="Tracks real time against a job — visible and controllable from anywhere in the app until you stop it."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button leftIcon={Play} isLoading={processing} disabled={!data.job_id} onClick={submit}>
            Start Timer
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <SelectField
          id="timer-job"
          label="Job"
          options={jobs.map((job) => ({ label: `${job.name}${job.client ? ` — ${job.client}` : ''}`, value: String(job.id) }))}
          value={data.job_id}
          onChange={(e) => {
            setData('job_id', e.target.value)
            setData('job_task_id', '')
          }}
          error={errors.job_id}
        />
        <SelectField
          id="timer-task"
          label="Task (optional)"
          options={[{ label: 'No specific task', value: '' }, ...tasks.map((t) => ({ label: t.title, value: String(t.id) }))]}
          value={data.job_task_id}
          onChange={(e) => setData('job_task_id', e.target.value)}
          error={errors.job_task_id}
        />
        {!data.job_task_id && (
          <TextInput
            id="timer-task-label"
            label="Task Label (optional)"
            placeholder="e.g. Panel rough-in"
            value={data.task_label}
            onChange={(e) => setData('task_label', e.target.value)}
            error={errors.task_label}
          />
        )}
        <TextInput
          id="timer-description"
          label="Description (optional)"
          value={data.description}
          onChange={(e) => setData('description', e.target.value)}
          error={errors.description}
        />
        <div>
          <Checkbox id="timer-billable" label="Billable" checked={data.billable} onChange={(e) => setData('billable', e.target.checked)} />
        </div>
      </div>
    </Modal>
  )
}
