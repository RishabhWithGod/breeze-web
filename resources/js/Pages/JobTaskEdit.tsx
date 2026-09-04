import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { ArrowLeft, Save, Trash2 } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  SectionHeading,
  SelectField,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { CrewMemberPicker, TaskLinePicker, type EstimateLine } from '@/components/jobs'
import { ROUTES } from '@/constants'
import type { SharedPageProps } from '@/types'

export interface JobTaskEditProps {
  /** Where Back and a successful save land — the screen this was opened from. */
  returnUrl: string
  /** Where the form saves to. Carries the origin so it survives the save. */
  saveUrl: string
  /** Where removing the task posts. Carries the same origin. */
  deleteUrl: string
  job: {
    readonly id: number
    readonly name: string
    readonly client: string | null
  }
  task: {
    readonly id: number
    readonly title: string
    readonly status: string
    readonly foremanId: number | null
    /** Who is over it. Null for work with a foreman and nobody above them. */
    readonly supervisorId: number | null
    readonly lineIds: readonly number[]
  }
  /**
   * Every line on the job's estimates. This task's own arrive unclaimed, so
   * unticking one and changing your mind is possible.
   */
  estimateLines: readonly EstimateLine[]
  /**
   * Who can be given this work: the job's own crew, or the whole register when
   * the job has no crew. Narrowed by the server — see
   * JobTaskSetupController::staffing().
   */
  foremen: readonly { readonly id: number; readonly name: string; readonly initials: string }[]
  supervisors: readonly {
    readonly id: number
    readonly name: string
    readonly initials: string
  }[]
  /** The crew both lists came from, so the screen can say why they are short. */
  team: { readonly id: number; readonly name: string } | null
  statuses: readonly string[]
}

/** "rough-in" reads as a machine value; the label should not. */
const humanise = (value: string) =>
  value.charAt(0).toUpperCase() + value.slice(1).replaceAll('-', ' ')

/**
 * One task, on the screen that created it.
 *
 * The same fields laid out the same way as "Add tasks" — a task is its name,
 * the estimate lines it covers and the foreman running it — because those are
 * the things a task is. Changing which lines it covers is the point: the lines
 * dropped here go back into the picker for another task, and the hours are
 * re-read off the labour rather than typed, so the plan and the estimate stay
 * the same story.
 *
 * Dates, priority and progress are not here. Those belong to the schedule,
 * where the whole plan is visible and moving one task means moving others.
 */
export default function JobTaskEdit({
  returnUrl,
  saveUrl,
  deleteUrl,
  job,
  task,
  estimateLines,
  foremen,
  supervisors,
  team,
  statuses,
}: JobTaskEditProps) {
  const [title, setTitle] = useState(task.title)
  const [status, setStatus] = useState(task.status)
  const [foremanId, setForemanId] = useState(task.foremanId === null ? '' : String(task.foremanId))
  const [supervisorId, setSupervisorId] = useState(
    task.supervisorId === null ? '' : String(task.supervisorId),
  )
  const [lineIds, setLineIds] = useState<number[]>([...task.lineIds])
  const [processing, setProcessing] = useState(false)
  const [isRemoving, setIsRemoving] = useState(false)

  const { errors } = usePage<SharedPageProps>().props
  const hasErrors = Object.keys(errors).length > 0

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    router.put(
      saveUrl,
      {
        title,
        status,
        foreman_id: foremanId === '' ? null : Number(foremanId),
        supervisor_id: supervisorId === '' ? null : Number(supervisorId),
        estimate_item_ids: lineIds,
      },
      {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
      },
    )
  }

  return (
    <PageTransition>
      <Head title={`Edit task — ${task.title}`} />

      <PageHeader
        title="Edit task"
        subtitle={
          team === null ? job.name : `${job.name} · ${team.name}`
        }
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Tasks', href: ROUTES.tasks },
          { label: task.title },
        ]}
        actions={
          <ButtonLink href={returnUrl} variant="secondary" size="sm" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the task" className="mb-6">
          Something below needs attention before this task can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading as="h3" title="Task" />

          {/*
            The same row the setup screen draws, with one task in it — same
            border, same order of fields, so the two screens read as one thing.
          */}
          <div className="rounded-panel border border-hairline bg-white/4 p-4">
            <div className="space-y-4">
              <TextInput
                id="task-title"
                label="Task name*"
                placeholder="e.g. Rough-in first floor"
                value={title}
                disabled={processing}
                onChange={(event) => setTitle(event.target.value)}
                {...(errors['title'] ? { error: errors['title'] } : {})}
              />

              {/* What the task is the work for, taken from the estimate. */}
              <div>
                <p className="mb-2 text-md font-medium text-white">Estimate lines</p>
                <TaskLinePicker
                  lines={estimateLines}
                  value={lineIds}
                  claimedElsewhere={new Set()}
                  disabled={processing}
                  onChange={setLineIds}
                  {...(errors['estimate_item_ids']
                    ? { error: errors['estimate_item_ids'] }
                    : {})}
                />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                {/* Who runs it. One per task — see JobTask::foreman(). */}
                <CrewMemberPicker
                  id="task-foreman"
                  label="Foreman"
                  role="foreman"
                  people={foremen}
                  value={foremanId}
                  onChange={setForemanId}
                  teamId={team?.id ?? null}
                  teamName={team?.name ?? null}
                  emptyLabel={
                    foremen.length > 0 ? 'Select foreman' : 'No foreman on this crew'
                  }
                  disabled={processing}
                  {...(errors['foreman_id'] ? { error: errors['foreman_id'] } : {})}
                />
                {/* Who is over it — from the same crew as the foreman. */}
                <CrewMemberPicker
                  id="task-supervisor"
                  label="Supervisor"
                  role="supervisor"
                  people={supervisors}
                  value={supervisorId}
                  onChange={setSupervisorId}
                  teamId={team?.id ?? null}
                  teamName={team?.name ?? null}
                  emptyLabel={
                    supervisors.length > 0 ? 'No supervisor' : 'No supervisor on this crew'
                  }
                  disabled={processing}
                  {...(errors['supervisor_id'] ? { error: errors['supervisor_id'] } : {})}
                />
                {/*
                  Not on the setup screen, because a task being planned has not
                  started. Here it has, and where it has got to is what the list
                  this was opened from is about.
                */}
                <SelectField
                  id="task-status"
                  label="Status"
                  options={statuses.map((value) => ({ label: humanise(value), value }))}
                  value={status}
                  disabled={processing}
                  onChange={(event) => setStatus(event.target.value)}
                  {...(errors['status'] ? { error: errors['status'] } : {})}
                />
              </div>
            </div>
          </div>

          <div className="mt-8 flex flex-wrap items-center gap-3 border-t border-hairline pt-6">
            {/*
              Removing is a decision about this task, so it belongs on this
              screen — kept away from Save, on the other side of the row.
            */}
            <Button
              type="button"
              variant="danger"
              leftIcon={Trash2}
              disabled={processing}
              onClick={() => setIsRemoving(true)}
            >
              Remove task
            </Button>

            <div className="ml-auto flex flex-wrap items-center gap-3">
              <ButtonLink href={returnUrl} variant="secondary">
                Cancel
              </ButtonLink>
              <Button type="submit" leftIcon={Save} isLoading={processing}>
                Save changes
              </Button>
            </div>
          </div>
        </Card>
      </form>

      <ConfirmDialog
        isOpen={isRemoving}
        tone="danger"
        title={`Remove “${task.title}”?`}
        description="The estimate lines it covers go back to be planned into another task, and the job's hours are recalculated."
        confirmLabel="Remove task"
        confirmVariant="danger"
        onConfirm={() => {
          router.delete(deleteUrl)
          setIsRemoving(false)
        }}
        onCancel={() => setIsRemoving(false)}
      />
    </PageTransition>
  )
}

JobTaskEdit.layout = appLayout
