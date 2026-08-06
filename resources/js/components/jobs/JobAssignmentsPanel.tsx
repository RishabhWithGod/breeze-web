import { useState } from 'react'
import { router } from '@inertiajs/react'
import { History, UserMinus, UserPlus } from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  IconButton,
  SectionHeading,
  SelectField,
  TextInput,
} from '@/components/common'
import { ASSIGNMENT_ROLES, routeTo } from '@/constants'
import type { AssignmentRole, JobAssignmentRow, JobTeamMember } from '@/types'

export interface JobAssignmentsPanelProps {
  jobId: number
  /** Every assignment ever made, newest first. */
  assignments: readonly JobAssignmentRow[]
  /** Crew who can be picked instead of typing a name. */
  members: readonly JobTeamMember[]
}

/**
 * Role-based staffing: estimator, project manager, foreman, electrician,
 * reviewer.
 *
 * Assigning a role that is already held releases the current holder, and released
 * rows stay on the record — the second list is the assignment history.
 */
export function JobAssignmentsPanel({
  jobId,
  assignments,
  members,
}: JobAssignmentsPanelProps) {
  const [role, setRole] = useState<AssignmentRole>('estimator')
  const [memberId, setMemberId] = useState('')
  const [name, setName] = useState('')

  const active = assignments.filter((assignment) => assignment.active)
  const past = assignments.filter((assignment) => !assignment.active)

  const assign = (event: React.FormEvent) => {
    event.preventDefault()

    router.post(
      routeTo.jobAssignments(jobId),
      {
        role,
        team_member_id: memberId ? Number(memberId) : null,
        name: memberId ? null : name,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setMemberId('')
          setName('')
        },
      },
    )
  }

  return (
    <Card padding="lg">
      <SectionHeading
        as="h3"
        title="Assignments"
        subtitle={`${active.length} ${active.length === 1 ? 'role' : 'roles'} staffed`}
      />

      {active.length === 0 ? (
        <EmptyState
          icon={UserPlus}
          size="sm"
          title="Nobody assigned yet"
          description="Assign an estimator to get the takeoff priced."
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {active.map((assignment) => (
            <li
              key={assignment.id}
              className="flex min-w-0 items-center gap-3 rounded-panel bg-white/5 px-4 py-3"
            >
              <div className="min-w-0 flex-1">
                <p className="truncate text-md font-medium text-white">{assignment.name}</p>
                <p className="text-2xs text-white/50">
                  {assignment.roleLabel} ·{' '}
                  {formatDistanceToNow(new Date(assignment.assignedAt), { addSuffix: true })}
                  {assignment.assignedBy ? ` · by ${assignment.assignedBy}` : ''}
                </p>
                {assignment.notes && (
                  <p className="mt-1 text-2xs text-white/60">{assignment.notes}</p>
                )}
              </div>
              <Badge tone="info" size="sm">
                {assignment.roleLabel}
              </Badge>
              <IconButton
                icon={UserMinus}
                label={`Release ${assignment.name}`}
                size="sm"
                variant="danger"
                onClick={() =>
                  router.delete(routeTo.jobAssignment(jobId, assignment.id), {
                    preserveScroll: true,
                  })
                }
              />
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={assign} className="mt-5 grid gap-3 border-t border-hairline pt-5 sm:grid-cols-3">
        <SelectField
          id="assignment-role"
          label="Role"
          options={ASSIGNMENT_ROLES.map((option) => ({
            value: option.value,
            label: option.label,
          }))}
          value={role}
          onChange={(event) => setRole(event.target.value as AssignmentRole)}
        />
        <SelectField
          id="assignment-member"
          label="Crew member"
          options={[
            { value: '', label: 'Type a name instead' },
            ...members.map((member) => ({
              value: String(member.id),
              label: `${member.name} — ${member.role}`,
            })),
          ]}
          value={memberId}
          onChange={(event) => setMemberId(event.target.value)}
        />
        <TextInput
          id="assignment-name"
          label="Or a name"
          placeholder="Dana Whitfield"
          value={name}
          disabled={Boolean(memberId)}
          onChange={(event) => setName(event.target.value)}
        />

        <div className="sm:col-span-3">
          <Button type="submit" size="sm" leftIcon={UserPlus}>
            Assign
          </Button>
        </div>
      </form>

      {past.length > 0 && (
        <div className="mt-5 border-t border-hairline pt-5">
          <p className="mb-2 flex items-center gap-2 text-sm font-medium text-white/70">
            <History size={14} aria-hidden />
            Assignment history
          </p>
          <ul className="flex flex-col gap-1.5">
            {past.map((assignment) => (
              <li key={assignment.id} className="text-2xs text-white/55">
                {assignment.name} — {assignment.roleLabel}, released{' '}
                {assignment.releasedAt
                  ? formatDistanceToNow(new Date(assignment.releasedAt), { addSuffix: true })
                  : ''}
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  )
}
