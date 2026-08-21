import { useEffect } from 'react'
import { useForm } from '@inertiajs/react'
import { Button, Checkbox, Modal, SelectField, TextInput } from '@/components/common'
import { TASK_PRIORITY_LABEL } from '@/constants'
import { routeTo } from '@/constants'
import type { ScheduleTask } from '@/types'

export interface TaskFormModalProps {
  isOpen: boolean
  onClose: () => void
  jobId: number
  task: ScheduleTask | null
  categories: readonly string[]
  statuses: readonly string[]
}

const EMPTY = {
  title: '',
  description: '',
  status: 'pending',
  priority: 'medium',
  category: '',
  estimated_hours: '',
  actual_hours: '',
  starts_on: '',
  ends_on: '',
  completion_pct: '0',
  is_milestone: false,
  notes: '',
}

/** Create or edit a task — the same fields either way, so one modal covers both. */
export function TaskFormModal({ isOpen, onClose, jobId, task, categories, statuses }: TaskFormModalProps) {
  const { data, setData, post, put, processing, errors, reset } = useForm({ ...EMPTY })

  useEffect(() => {
    setData(
      task
        ? {
            title: task.title,
            description: task.description ?? '',
            status: task.status,
            priority: task.priority,
            category: task.category ?? '',
            estimated_hours: task.estimatedHours !== null ? String(task.estimatedHours) : '',
            actual_hours: String(task.actualHours),
            starts_on: task.startsOn ?? '',
            ends_on: task.endsOn ?? '',
            completion_pct: String(task.completionPct),
            is_milestone: task.isMilestone,
            notes: task.notes ?? '',
          }
        : { ...EMPTY },
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [task?.id])

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    const payload = { preserveScroll: true as const, onSuccess: close }

    if (task) {
      put(routeTo.scheduleTask(task.id), payload)
    } else {
      post(routeTo.jobTasksStore(jobId), payload)
    }
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={task ? `Edit "${task.title}"` : 'Add Task'}
      size="lg"
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button isLoading={processing} disabled={!data.title} onClick={submit}>
            {task ? 'Save Changes' : 'Add Task'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <TextInput id="task-title" label="Title" value={data.title} onChange={(e) => setData('title', e.target.value)} error={errors.title} />
        <TextInput
          id="task-description"
          label="Description"
          value={data.description}
          onChange={(e) => setData('description', e.target.value)}
          error={errors.description}
        />
        <div className="grid grid-cols-2 gap-4">
          {task && (
            <SelectField
              id="task-status"
              label="Status"
              options={statuses.map((s) => ({ label: s, value: s }))}
              value={data.status}
              onChange={(e) => setData('status', e.target.value)}
              error={errors.status}
            />
          )}
          <SelectField
            id="task-priority"
            label="Priority"
            options={Object.entries(TASK_PRIORITY_LABEL).map(([value, label]) => ({ label, value }))}
            value={data.priority}
            onChange={(e) => setData('priority', e.target.value)}
            error={errors.priority}
          />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <SelectField
            id="task-category"
            label="Category"
            options={[{ label: 'None', value: '' }, ...categories.map((c) => ({ label: c, value: c }))]}
            value={data.category}
            onChange={(e) => setData('category', e.target.value)}
            error={errors.category}
          />
          <TextInput
            id="task-hours"
            type="number"
            min={0}
            label="Estimated Hours"
            value={data.estimated_hours}
            onChange={(e) => setData('estimated_hours', e.target.value)}
            error={errors.estimated_hours}
          />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <TextInput id="task-starts" type="date" label="Starts On" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} error={errors.starts_on} />
          <TextInput id="task-ends" type="date" label="Ends On" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} error={errors.ends_on} />
        </div>
        {task && (
          <div className="grid grid-cols-2 gap-4">
            <TextInput
              id="task-actual-hours"
              type="number"
              min={0}
              label="Actual Hours"
              value={data.actual_hours}
              onChange={(e) => setData('actual_hours', e.target.value)}
              error={errors.actual_hours}
            />
            <TextInput
              id="task-completion"
              type="number"
              min={0}
              max={100}
              label="Completion %"
              value={data.completion_pct}
              onChange={(e) => setData('completion_pct', e.target.value)}
              error={errors.completion_pct}
            />
          </div>
        )}
        <div>
          <Checkbox id="task-milestone" label="This is a milestone" checked={data.is_milestone} onChange={(e) => setData('is_milestone', e.target.checked)} />
        </div>
      </div>
    </Modal>
  )
}
