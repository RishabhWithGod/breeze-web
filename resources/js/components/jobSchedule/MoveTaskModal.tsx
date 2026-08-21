import { useEffect } from 'react'
import { useForm } from '@inertiajs/react'
import { CalendarClock } from 'lucide-react'
import { Button, Modal, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { ScheduleTask } from '@/types'

export interface MoveTaskModalProps {
  isOpen: boolean
  onClose: () => void
  task: ScheduleTask | null
}

/** The drag-and-drop reschedule, as a form — the span is preserved server-side if no end date is given. */
export function MoveTaskModal({ isOpen, onClose, task }: MoveTaskModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({ starts_on: '', ends_on: '' })

  useEffect(() => {
    setData({ starts_on: task?.startsOn ?? '', ends_on: task?.endsOn ?? '' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [task?.id])

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    if (!task) return
    post(routeTo.scheduleTaskMove(task.id), { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={`Reschedule "${task?.title ?? ''}"`}
      description="Moving the start date keeps the task's original span."
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button leftIcon={CalendarClock} isLoading={processing} disabled={!data.starts_on} onClick={submit}>
            Move Task
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-4">
        <TextInput id="move-starts-on" type="date" label="New Start Date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} error={errors.starts_on} />
        <TextInput id="move-ends-on" type="date" label="New End Date (optional)" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} error={errors.ends_on} />
      </div>
    </Modal>
  )
}
