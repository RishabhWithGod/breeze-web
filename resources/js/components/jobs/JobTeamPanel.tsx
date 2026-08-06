import { router, useForm } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import { UserMinus, UserPlus } from 'lucide-react'
import { Button, IconButton, SelectField, TextInput } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobTeamMember } from '@/types'

export interface JobTeamPanelProps {
  jobId: number
  team: readonly JobTeamMember[]
  /** Crew not yet on this job. */
  assignable: readonly JobTeamMember[]
}

/** Assign / remove crew, backed by the job_team_member pivot. */
export function JobTeamPanel({ jobId, team, assignable }: JobTeamPanelProps) {
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
    team_member_id: '',
    role_on_job: '',
  })

  const options = [
    { label: assignable.length ? 'Select a crew member' : 'Everyone is assigned', value: '' },
    ...assignable.map((member) => ({
      label: `${member.name} — ${member.role}`,
      value: String(member.id),
    })),
  ]

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    post(routeTo.jobTeam(jobId), {
      preserveScroll: true,
      onSuccess: () => reset(),
    })
  }

  const remove = (memberId: number) => {
    router.delete(routeTo.jobTeamMember(jobId, memberId), { preserveScroll: true })
  }

  return (
    <div>
      <form onSubmit={submit} className="mb-5 grid gap-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] sm:items-end">
        <SelectField
          id="job-team-member"
          label="Crew member"
          options={options}
          value={data.team_member_id}
          disabled={assignable.length === 0}
          onChange={(event) => {
            setData('team_member_id', event.target.value)
            if (errors.team_member_id) clearErrors('team_member_id')
          }}
          {...(errors.team_member_id ? { error: errors.team_member_id } : {})}
        />
        <TextInput
          id="job-team-role"
          label="Role on job"
          placeholder="Optional, e.g. Lead"
          value={data.role_on_job}
          disabled={assignable.length === 0}
          onChange={(event) => setData('role_on_job', event.target.value)}
        />
        <Button
          type="submit"
          leftIcon={UserPlus}
          isLoading={processing}
          disabled={!data.team_member_id}
        >
          Assign
        </Button>
      </form>

      {team.length === 0 ? (
        <p className="text-md text-white/50">No crew assigned yet.</p>
      ) : (
        <ul className="space-y-3">
          <AnimatePresence initial={false}>
            {team.map((member) => (
              <motion.li
                key={member.id}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, height: 0 }}
                className="flex items-center gap-3 rounded-panel border border-hairline bg-white/4 p-4"
              >
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-ocean-800 text-2xs font-semibold text-white ring-1 ring-steel-600">
                  {member.initials}
                </span>

                <div className="min-w-0 flex-1">
                  <p className="truncate text-md font-semibold text-white">
                    {member.name}
                  </p>
                  <p className="mt-0.5 truncate text-sm text-white/55">{member.role}</p>
                </div>

                <IconButton
                  icon={UserMinus}
                  label={`Remove ${member.name}`}
                  size="sm"
                  className="shrink-0 text-white/45 hover:text-status-danger"
                  onClick={() => remove(member.id)}
                />
              </motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}
    </div>
  )
}
