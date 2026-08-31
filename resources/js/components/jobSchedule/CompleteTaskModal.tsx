import { useForm } from '@inertiajs/react'
import { CheckCircle2 } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { ScheduleTask } from '@/types'

export interface CompleteTaskModalProps {
  isOpen: boolean
  onClose: () => void
  task: ScheduleTask | null
}

export function CompleteTaskModal({ isOpen, onClose, task }: CompleteTaskModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({ actual_hours: '', notes: '' })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    if (!task) return
    post(routeTo.scheduleTaskComplete(task.id), { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={`Complete "${task?.title ?? ''}"`}
      description="Marks this task 100% complete and may unblock what was waiting on it."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button leftIcon={CheckCircle2} isLoading={processing} onClick={submit}>
            Mark Complete
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <TextInput
          id="complete-actual-hours"
          type="number"
          min={0}
          label="Actual Hours (optional)"
          placeholder={task?.estimatedHours !== null && task?.estimatedHours !== undefined ? String(task.estimatedHours) : undefined}
          value={data.actual_hours}
          onChange={(e) => setData('actual_hours', e.target.value)}
          error={errors.actual_hours}
        />
        <TextInput id="complete-notes" label="Notes (optional)" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} />
      </div>
    </Modal>
  )
}
