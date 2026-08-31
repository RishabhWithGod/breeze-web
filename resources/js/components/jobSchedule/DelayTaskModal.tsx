import { useForm } from '@inertiajs/react'
import { AlertTriangle } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { ScheduleTask } from '@/types'

export interface DelayTaskModalProps {
  isOpen: boolean
  onClose: () => void
  task: ScheduleTask | null
}

export function DelayTaskModal({ isOpen, onClose, task }: DelayTaskModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({ ends_on: '', reason: '' })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    if (!task) return
    post(routeTo.scheduleTaskDelay(task.id), { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={`Delay "${task?.title ?? ''}"`}
      description="Records a new due date and the reason — both show on the Delays panel."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button variant="danger" leftIcon={AlertTriangle} isLoading={processing} disabled={!data.ends_on || !data.reason} onClick={submit}>
            Mark Delayed
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <TextInput id="delay-ends-on" type="date" label="New Due Date" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} error={errors.ends_on} />
        <TextInput
          id="delay-reason"
          label="Reason"
          placeholder="Switchgear delivery slipped a week."
          value={data.reason}
          onChange={(e) => setData('reason', e.target.value)}
          error={errors.reason}
        />
      </div>
    </Modal>
  )
}
