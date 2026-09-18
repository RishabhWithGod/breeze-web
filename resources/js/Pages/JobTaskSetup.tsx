import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { ArrowLeft, ArrowRight, ListChecks, Plus, Trash2 } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  EmptyState,
  IconButton,
  SectionHeading,
  TextInput,
  WorkflowProgress,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { CrewMemberPicker, TaskLinePicker, type EstimateLine } from '@/components/jobs'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'

interface TaskRow {
  title: string
  /** Who runs it. One person — a task with two people in charge has nobody. */
  foreman_id: string
  /** Who is over it. Optional: plenty of work needs nobody above the foreman. */
  supervisor_id: string
  /** The estimate lines this task is the work for. */
  estimate_item_ids: number[]
}

export interface JobTaskSetupProps {
  /**
   * Set when the step was opened from the task list to add work to a job
   * already running, rather than reached as part of raising one. Back and a
   * successful save then go there instead of on through the flow.
   */
  returnUrl: string | null
  /** Where the form posts. Carries the origin so it survives the save. */
  saveUrl: string
  job: {
    readonly id: number
    readonly name: string
    readonly client: string | null
    readonly location: string | null
    readonly startDate: string | null
    readonly endDate: string | null
    /** False for a job created by hand — it has no takeoff behind it. */
    readonly fromTakeoff: boolean
    /** The review summary this job was raised from, when there was one. */
    readonly takeoffUrl: string | null
  }
  /** Tasks already planned, so re-opening the step adds to them rather than repeats them. */
  existingTasks: readonly {
    readonly id: number
    readonly title: string
    readonly foreman: string | null
    readonly lineCount: number
  }[]
  /** Every line on the job's estimates, with whatever already claimed it. */
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
}

const emptyRow = (): TaskRow => ({
  title: '',
  foreman_id: '',
  supervisor_id: '',
  estimate_item_ids: [],
})

/**
 * The step straight after a job is created: breaking it into the work it takes.
 *
 * The whole list is typed here and saved in one request, because a plan is
 * written in one sitting — adding tasks one at a time, each a round trip, is
 * how the schedule screen works and is the wrong shape for starting from
 * nothing.
 *
 * Skipping is a real choice, not a dead end: a job can be planned later, or
 * never, and the link out says so plainly.
 */
export default function JobTaskSetup({
  returnUrl,
  saveUrl,
  job,
  existingTasks,
  estimateLines,
  foremen,
  supervisors,
  team,
}: JobTaskSetupProps) {
  const [rows, setRows] = useState<TaskRow[]>([emptyRow()])
  const [processing, setProcessing] = useState(false)

  /*
   * Errors come off the page rather than a typed form, because they arrive
   * keyed by row — `tasks.2.title` — and belong to a list this component owns
   * in its own state, not to a flat form shape.
   */
  const { errors } = usePage<SharedPageProps>().props
  const hasErrors = Object.keys(errors).length > 0

  const update = (index: number, patch: Partial<TaskRow>) => {
    setRows((current) =>
      current.map((row, position) => (position === index ? { ...row, ...patch } : row)),
    )
  }

  /** Every line the *other* rows have taken — a line belongs to one task. */
  const claimedBy = (index: number) =>
    new Set(rows.flatMap((row, position) => (position === index ? [] : row.estimate_item_ids)))

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    router.post(
      saveUrl,
      {
        tasks: rows.map((row) => ({
          title: row.title,
          foreman_id: row.foreman_id === '' ? null : Number(row.foreman_id),
          supervisor_id: row.supervisor_id === '' ? null : Number(row.supervisor_id),
          estimate_item_ids: row.estimate_item_ids,
        })),
      },
      {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
      },
    )
  }

  return (
    <PageTransition>
      <Head title={`Add tasks — ${job.name}`} />

      <PageHeader
        title="Add tasks"
        /*
         * The crew is named here because it is why the crew and foreman
         * lists below are short — "where is everyone" is the first question a
         * narrowed picker raises.
         */
        subtitle={
          team === null
            ? 'This job has no crew, so anyone on the register can be given its work.'
            : `Handed to ${team.name} — its crew and foremen are the ones offered below.`
        }
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: job.name, href: routeTo.job(job.id) },
          { label: 'Tasks' },
        ]}
        actions={
          /*
           * The step before: the list this was opened from, the review summary
           * that raised this job, or the jobs list for one created by hand.
           * Navigation, not a way out — the job exists either way and still
           * needs its tasks.
           */
          <ButtonLink
            href={returnUrl ?? job.takeoffUrl ?? ROUTES.jobs}
            variant="secondary"
            size="sm"
            leftIcon={ArrowLeft}
          >
            Back
          </ButtonLink>
        }
      />

      {/*
        Only for a job that came through a takeoff. A job created by hand has no
        analysis, review or estimate behind it, so the roadmap would be claiming
        steps that never happened.
      */}
      {job.fromTakeoff && returnUrl === null && (
        <WorkflowProgress
          current="tasks"
          done={['analysis', 'review', 'estimate', 'job']}
          className="mb-6"
        />
      )}

      {hasErrors && (
        <Alert tone="danger" title="Check the tasks" className="mb-6">
          Some rows need attention before this plan can be saved.
        </Alert>
      )}

      {existingTasks.length > 0 && (
        <Card accent="brand" padding="lg" className="mb-6">
          <SectionHeading
            as="h3"
            title="Already planned"
          />
          <ul className="grid gap-2 sm:grid-cols-2">
            {existingTasks.map((task) => (
              <li
                key={task.id}
                className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 px-3 py-2"
              >
                <span className="min-w-0">
                  <span className="block truncate text-md text-white">{task.title}</span>
                  <span className="text-sm text-white/65">
                    {task.lineCount > 0
                      ? `${task.lineCount} estimate ${task.lineCount === 1 ? 'line' : 'lines'}`
                      : 'No estimate lines'}
                  </span>
                </span>
                <span className="shrink-0 text-sm text-white/70">
                  {task.foreman ?? 'Unassigned'}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <form onSubmit={submit} noValidate>
        <Card accent="success" padding="lg">
          <SectionHeading as="h3" title="Tasks" />

          {rows.length === 0 ? (
            <EmptyState
              size="sm"
              icon={ListChecks}
              title="No rows"
              description="Add a row to start planning this job."
            />
          ) : (
            <ul className="space-y-4">
              {rows.map((row, index) => (
                <li
                  key={index}
                  className={cn(
                    'rounded-panel border p-4',
                    errors[`tasks.${index}.title`]
                      ? 'border-status-danger/70 bg-status-danger/5'
                      : 'border-hairline bg-white/4',
                  )}
                >
                  <div className="mb-3 flex items-center justify-between gap-3">
                    <p className="text-sm font-medium text-white">Task {index + 1}</p>
                    {rows.length > 1 && (
                      <IconButton
                        icon={Trash2}
                        label={`Remove task ${index + 1}`}
                        variant="white"
                        size="sm"
                        disabled={processing}
                        onClick={() =>
                          setRows((current) =>
                            current.filter((_, position) => position !== index),
                          )
                        }
                        className="text-status-danger hover:border-status-danger hover:bg-status-danger hover:text-white"
                      />
                    )}
                  </div>

                  <div className="space-y-4">
                    <TextInput
                      id={`task-title-${index}`}
                      label="Task name*"
                      placeholder="e.g. Rough-in first floor"
                      value={row.title}
                      disabled={processing}
                      onChange={(event) => update(index, { title: event.target.value })}
                      {...(errors[`tasks.${index}.title`]
                        ? { error: errors[`tasks.${index}.title`] }
                        : {})}
                    />

                    {/* What the task is the work for, taken from the estimate. */}
                    <div>
                      <p className="mb-2 text-md font-medium text-white">Estimate lines</p>
                      <TaskLinePicker
                        lines={estimateLines}
                        value={row.estimate_item_ids}
                        claimedElsewhere={claimedBy(index)}
                        disabled={processing}
                        onChange={(ids) => update(index, { estimate_item_ids: ids })}
                        {...(errors[`tasks.${index}.estimate_item_ids`]
                          ? { error: errors[`tasks.${index}.estimate_item_ids`] }
                          : {})}
                      />
                    </div>

                    {/*
                      Who runs it and who is over them — both from this job's
                      own crew, which is why the lists are short.
                    */}
                    <div className="grid gap-4 sm:grid-cols-2">
                      <CrewMemberPicker
                        id={`task-foreman-${index}`}
                        label="Assigned to*"
                        slot="worker"
                        people={foremen}
                        value={row.foreman_id}
                        onChange={(next) => update(index, { foreman_id: next })}
                        teamId={team?.id ?? null}
                        teamName={team?.name ?? null}
                        emptyLabel={
                          foremen.length > 0 ? 'Select who runs it' : 'No one on this crew'
                        }
                        disabled={processing}
                        allowInlineAdd={false}
                        {...(errors[`tasks.${index}.foreman_id`]
                          ? { error: errors[`tasks.${index}.foreman_id`] }
                          : {})}
                      />

                      <CrewMemberPicker
                        id={`task-supervisor-${index}`}
                        label="Foreman*"
                        slot="foreman"
                        people={supervisors}
                        value={row.supervisor_id}
                        onChange={(next) => update(index, { supervisor_id: next })}
                        teamId={team?.id ?? null}
                        teamName={team?.name ?? null}
                        emptyLabel={
                          supervisors.length > 0
                            ? 'Select foreman'
                            : 'No foreman on this crew'
                        }
                        disabled={processing}
                        allowInlineAdd={false}
                        {...(errors[`tasks.${index}.supervisor_id`]
                          ? { error: errors[`tasks.${index}.supervisor_id`] }
                          : {})}
                      />
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}

          <div className="mt-4">
            <Button
              type="button"
              variant="white"
              size="sm"
              leftIcon={Plus}
              disabled={processing}
              onClick={() => setRows((current) => [...current, emptyRow()])}
            >
              Add another task
            </Button>
          </div>

          {errors['tasks'] && <p className="mt-3 text-sm text-red-300">{errors['tasks']}</p>}

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            {/*
              The last step of the flow when this is part of raising a job;
              plain "add these" when the planner came back to a running one.
            */}
            <Button type="submit" rightIcon={ArrowRight} isLoading={processing}>
              {returnUrl === null ? 'Save and finish' : 'Save tasks'}
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

JobTaskSetup.layout = appLayout
