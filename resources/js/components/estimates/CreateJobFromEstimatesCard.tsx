import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Briefcase, X } from 'lucide-react'
import { Button, SelectField, TextInput } from '@/components/common'
import { JobSitePicker } from '@/components/jobs'
import { routeTo } from '@/constants'
import type { ClientOption } from '@/types'
import { AddendumSelectionList, type AddendumSummary } from './AddendumSelectionList'

export interface CreateJobFromEstimatesCardProps {
  original: { readonly id: number; readonly number: string; readonly amount: number; readonly status: string }
  addenda: readonly AddendumSummary[]
  /** The estimate's own client — which of `clients` the site picker offers. */
  clientId: number | null
  clients: readonly ClientOption[]
  teams: readonly { readonly id: number; readonly name: string }[]
}

/**
 * Pick which of the original estimate's addenda go into a job, see the
 * combined total, and raise it — the merge half of the Addendum feature.
 * `JobFromEstimatesController` clones the selected sources' saved line items
 * (never the original AI numbers, and never the unselected addenda); this
 * component only ever sends estimate ids, never a total, to the server.
 *
 * Shared by the Addendum screen (one card per original, across a whole
 * project) and the Estimate screen's pre-job workspace (one instance, for
 * this estimate) — the merge workflow itself lives here once.
 */
export function CreateJobFromEstimatesCard({
  original,
  addenda,
  clientId,
  clients,
  teams,
}: CreateJobFromEstimatesCardProps) {
  const [selected, setSelected] = useState<ReadonlySet<number>>(
    () => new Set([original.id, ...addenda.map((addendum) => addendum.id)]),
  )
  const toggle = (id: number) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })

  const [creatingJob, setCreatingJob] = useState(false)

  return (
    <div>
      <AddendumSelectionList
        original={original}
        addenda={addenda}
        selected={selected}
        onToggle={toggle}
      />

      <div className="mt-4 border-t border-hairline pt-4">
        {creatingJob ? (
          <CreateJobForm
            estimateIds={Array.from(selected)}
            clientId={clientId}
            clients={clients}
            teams={teams}
            onCancel={() => setCreatingJob(false)}
          />
        ) : (
          <Button
            size="sm"
            variant="secondary"
            leftIcon={Briefcase}
            disabled={selected.size === 0}
            onClick={() => setCreatingJob(true)}
          >
            Create Job
          </Button>
        )}
      </div>
    </div>
  )
}

export interface CreateJobFormProps {
  estimateIds: readonly number[]
  clientId: number | null
  clients: readonly ClientOption[]
  teams: readonly { readonly id: number; readonly name: string }[]
  onCancel: () => void
}

/** The job-detail fields (name, crew, site, dates) a merge is actually raised with — exported so a caller that already has its own selection UI (the Estimate screen's "Continue to Job") can present just this part, in a modal. */
export function CreateJobForm({
  estimateIds,
  clientId,
  clients,
  teams,
  onCancel,
}: CreateJobFormProps) {
  const [name, setName] = useState('')
  const [teamId, setTeamId] = useState('')
  const [addressIds, setAddressIds] = useState<readonly number[]>([])
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10))
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10))
  const [processing, setProcessing] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const client = clients.find((c) => c.id === clientId) ?? null

  const submit = () => {
    router.post(
      routeTo.jobsFromEstimates,
      {
        estimate_ids: [...estimateIds],
        name,
        team_id: teamId,
        address_ids: [...addressIds],
        start_date: startDate,
        end_date: endDate,
      },
      {
        onStart: () => setProcessing(true),
        onError: setErrors,
        onFinish: () => setProcessing(false),
      },
    )
  }

  return (
    <div className="rounded-panel border border-hairline bg-white/4 p-4">
      <div className="mb-3 flex items-center justify-between gap-3">
        <p className="text-sm font-medium text-white">
          New job from {estimateIds.length} selected estimate{estimateIds.length === 1 ? '' : 's'}
        </p>
        <Button type="button" variant="ghost" size="sm" leftIcon={X} onClick={onCancel}>
          Cancel
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <TextInput
          id="new-job-name"
          label="Job Name*"
          placeholder="e.g. Harborview — Combined Scope"
          value={name}
          onChange={(event) => setName(event.target.value)}
          {...(errors.name ? { error: errors.name } : {})}
        />

        <SelectField
          id="new-job-team"
          label="Team*"
          options={[
            { value: '', label: teams.length > 0 ? 'Select team' : 'No teams yet' },
            ...teams.map((team) => ({ value: String(team.id), label: team.name })),
          ]}
          value={teamId}
          onChange={(event) => setTeamId(event.target.value)}
          {...(errors.team_id ? { error: errors.team_id } : {})}
        />

        <TextInput
          id="new-job-start"
          label="Start Date*"
          type="date"
          value={startDate}
          onChange={(event) => setStartDate(event.target.value)}
          {...(errors.start_date ? { error: errors.start_date } : {})}
        />

        <TextInput
          id="new-job-end"
          label="End Date*"
          type="date"
          value={endDate}
          onChange={(event) => setEndDate(event.target.value)}
          {...(errors.end_date ? { error: errors.end_date } : {})}
        />
      </div>

      <div className="mt-4">
        <p className="mb-2 text-md font-medium text-white">Site*</p>
        <JobSitePicker
          clientId={client?.id ?? null}
          clientName={client?.name ?? ''}
          sites={client?.addresses ?? []}
          value={addressIds}
          onChange={setAddressIds}
          {...(errors.address_ids ? { error: errors.address_ids } : {})}
        />
      </div>

      {errors.estimate_ids && <p className="mt-3 text-sm text-red-300">{errors.estimate_ids}</p>}

      <Button type="button" size="sm" className="mt-4" isLoading={processing} onClick={submit}>
        Create Job
      </Button>
    </div>
  )
}
