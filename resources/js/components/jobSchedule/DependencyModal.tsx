import { useForm } from '@inertiajs/react'
import { GitBranch } from 'lucide-react'
import { Button, Modal, SelectField, TextInput } from '@/components/common'
import { DEPENDENCY_TYPE_LABEL } from '@/constants'
import { routeTo } from '@/constants'
import type { ScheduleTask } from '@/types'

export interface DependencyModalProps {
  isOpen: boolean
  onClose: () => void
  task: ScheduleTask | null
  otherTasks: readonly ScheduleTask[]
}

/** Adds a "waits on" edge — a cycle is refused server-side, a broken date is only a warning. */
export function DependencyModal({ isOpen, onClose, task, otherTasks }: DependencyModalProps) {
  const candidates = otherTasks.filter((t) => t.id !== task?.id)
  const { data, setData, post, processing, errors, reset } = useForm({
    depends_on_id: candidates[0] ? String(candidates[0].id) : '',
    type: 'finish_to_start',
    lag_days: '0',
  })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    if (!task) return
    post(routeTo.scheduleTaskDependencyStore(task.id), { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={`"${task?.title ?? ''}" waits on…`}
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button leftIcon={GitBranch} isLoading={processing} disabled={!data.depends_on_id} onClick={submit}>
            Add Dependency
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <SelectField
          id="dependency-task"
          label="Depends On"
          options={candidates.map((t) => ({ label: t.title, value: String(t.id) }))}
          value={data.depends_on_id}
          onChange={(e) => setData('depends_on_id', e.target.value)}
          error={errors.depends_on_id}
        />
        <SelectField
          id="dependency-type"
          label="Type"
          options={Object.entries(DEPENDENCY_TYPE_LABEL).map(([value, label]) => ({ label, value }))}
          value={data.type}
          onChange={(e) => setData('type', e.target.value)}
          error={errors.type}
        />
        <TextInput
          id="dependency-lag"
          type="number"
          label="Lag Days (optional)"
          value={data.lag_days}
          onChange={(e) => setData('lag_days', e.target.value)}
          error={errors.lag_days}
        />
      </div>
    </Modal>
  )
}
