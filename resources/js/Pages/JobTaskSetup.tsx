import { useState } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import { ArrowRight, ListChecks, Plus, Trash2 } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  EmptyState,
  IconButton,
  SectionHeading,
  SelectField,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { SharedPageProps } from '@/types'
import { cn } from '@/utils'

interface TaskRow {
  title: string
  category: string
  priority: string
  estimated_hours: string
  starts_on: string
  ends_on: string
}

export interface JobTaskSetupProps {
  job: {
    readonly id: number
    readonly name: string
    readonly client: string | null
    readonly location: string | null
    readonly startDate: string | null
    readonly endDate: string | null
  }
  /** Tasks already planned, so re-opening the step adds to them rather than repeats them. */
  existingTasks: readonly {
    readonly id: number
    readonly title: string
    readonly category: string | null
    readonly estimatedHours: number | null
  }[]
  categories: readonly string[]
  priorities: readonly string[]
}

const emptyRow = (): TaskRow => ({
  title: '',
  category: '',
  priority: 'medium',
  estimated_hours: '',
  starts_on: '',
  ends_on: '',
})

/** "rough-in" reads as a machine value; the label should not. */
const humanise = (value: string) =>
  value.charAt(0).toUpperCase() + value.slice(1).replaceAll('-', ' ')

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
  job,
  existingTasks,
  categories,
  priorities,
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

  const filled = rows.filter((row) => row.title.trim() !== '')

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    // Rows nobody typed into are dropped rather than rejected as missing names.
    router.post(
      routeTo.jobTaskSetup(job.id),
      {
        tasks: filled.map((row) => ({
          title: row.title,
          category: row.category || null,
          priority: row.priority,
          estimated_hours: row.estimated_hours === '' ? null : Number(row.estimated_hours),
          starts_on: row.starts_on || null,
          ends_on: row.ends_on || null,
        })),
      },
      {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
      },
    )
  }

  const categoryOptions = [
    { label: 'No category', value: '' },
    ...categories.map((category) => ({ label: humanise(category), value: category })),
  ]

  const priorityOptions = priorities.map((priority) => ({
    label: humanise(priority),
    value: priority,
  }))

  return (
    <PageTransition>
      <Head title={`Add tasks — ${job.name}`} />

      <PageHeader
        title="Add tasks"
        subtitle={`Break “${job.name}” into the work it takes. You can do this later instead.`}
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: job.name, href: routeTo.job(job.id) },
          { label: 'Tasks' },
        ]}
        actions={
          <ButtonLink href={routeTo.job(job.id)} variant="secondary" size="sm">
            Skip for now
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the tasks" className="mb-6">
          Some rows need attention before this plan can be saved.
        </Alert>
      )}

      {existingTasks.length > 0 && (
        <Card padding="lg" className="mb-6">
          <SectionHeading
            as="h3"
            title="Already planned"
            subtitle="Anything you add below is appended to these"
          />
          <ul className="grid gap-2 sm:grid-cols-2">
            {existingTasks.map((task) => (
              <li
                key={task.id}
                className="flex items-center justify-between gap-3 rounded-panel border border-hairline bg-white/4 px-3 py-2"
              >
                <span className="min-w-0 truncate text-md text-white">{task.title}</span>
                <span className="shrink-0 text-sm text-white/70">
                  {task.estimatedHours === null ? '—' : `${task.estimatedHours}h`}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Tasks"
            subtitle="Only the name is required — the rest can be filled in on the schedule"
          />

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

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                      <SelectField
                        id={`task-category-${index}`}
                        label="Category"
                        options={categoryOptions}
                        value={row.category}
                        disabled={processing}
                        onChange={(event) => update(index, { category: event.target.value })}
                      />
                      <SelectField
                        id={`task-priority-${index}`}
                        label="Priority"
                        options={priorityOptions}
                        value={row.priority}
                        disabled={processing}
                        onChange={(event) => update(index, { priority: event.target.value })}
                      />
                      <TextInput
                        id={`task-hours-${index}`}
                        type="number"
                        inputMode="decimal"
                        min={0}
                        step={0.5}
                        label="Est. hours"
                        placeholder="8"
                        value={row.estimated_hours}
                        disabled={processing}
                        onChange={(event) =>
                          update(index, { estimated_hours: event.target.value })
                        }
                        {...(errors[`tasks.${index}.estimated_hours`]
                          ? { error: errors[`tasks.${index}.estimated_hours`] }
                          : {})}
                      />
                      <TextInput
                        id={`task-starts-${index}`}
                        type="date"
                        label="Starts"
                        value={row.starts_on}
                        disabled={processing}
                        onChange={(event) => update(index, { starts_on: event.target.value })}
                      />
                    </div>

                    <TextInput
                      id={`task-ends-${index}`}
                      type="date"
                      label="Ends"
                      className="sm:max-w-xs"
                      value={row.ends_on}
                      disabled={processing}
                      onChange={(event) => update(index, { ends_on: event.target.value })}
                      {...(errors[`tasks.${index}.ends_on`]
                        ? { error: errors[`tasks.${index}.ends_on`] }
                        : {})}
                    />
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
            <ButtonLink href={routeTo.job(job.id)} variant="white">
              Skip for now
            </ButtonLink>
            <Button
              type="submit"
              rightIcon={ArrowRight}
              isLoading={processing}
              disabled={filled.length === 0}
              {...(filled.length === 0
                ? { title: 'Name at least one task to save the plan' }
                : {})}
            >
              {filled.length === 0
                ? 'Save tasks'
                : `Save ${filled.length} ${filled.length === 1 ? 'task' : 'tasks'}`}
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

JobTaskSetup.layout = appLayout
