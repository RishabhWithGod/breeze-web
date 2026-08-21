import { useForm } from '@inertiajs/react'
import { UserPlus } from 'lucide-react'
import { Button, Modal, SelectField } from '@/components/common'
import { routeTo } from '@/constants'
import type { CrewMember, ScheduleTask } from '@/types'

export interface AssignTaskModalProps {
  isOpen: boolean
  onClose: () => void
  task: ScheduleTask | null
  members: readonly CrewMember[]
  roles: readonly string[]
}

export function AssignTaskModal({ isOpen, onClose, task, members, roles }: AssignTaskModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    team_member_id: members[0] ? String(members[0].id) : '',
    role: roles[0] ?? '',
  })

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    if (!task) return
    post(routeTo.scheduleTaskAssign(task.id), { preserveScroll: true, onSuccess: close })
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      title={`Assign to "${task?.title ?? ''}"`}
      footer={
        <>
          <Button variant="white" onClick={close}>
            Cancel
          </Button>
          <Button leftIcon={UserPlus} isLoading={processing} disabled={!data.team_member_id} onClick={submit}>
            Assign
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <SelectField
          id="assign-member"
          label="Team Member"
          options={members.map((m) => ({ label: `${m.name}${m.role ? ` (${m.role})` : ''}`, value: String(m.id) }))}
          value={data.team_member_id}
          onChange={(e) => setData('team_member_id', e.target.value)}
          error={errors.team_member_id}
        />
        <SelectField
          id="assign-role"
          label="Role on this Task"
          options={roles.map((r) => ({ label: r, value: r }))}
          value={data.role}
          onChange={(e) => setData('role', e.target.value)}
          error={errors.role}
        />
      </div>
    </Modal>
  )
}
