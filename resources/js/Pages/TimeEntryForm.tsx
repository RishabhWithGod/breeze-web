import { useEffect, useState } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  Checkbox,
  SectionHeading,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { TimeEntry, TimeTrackingJobOption, TimeTrackingTaskOption } from '@/types'

interface TimeEntryFormData {
  job_id: string
  job_task_id: string
  task_label: string
  date: string
  start_time: string
  end_time: string
  break_minutes: string
  hours: string
  description: string
  billable: boolean
}

export interface TimeEntryFormProps {
  entry: TimeEntry | null
  jobs: readonly TimeTrackingJobOption[]
}

/**
 * Log Time / Edit — one page for both, since the fields and the validation
 * are identical. Hours are always confirmed by the server: this form previews
 * a duration from start/end/break locally, but the number a save actually
 * records is whatever `TimeEntryController` computes, never this preview.
 */
export default function TimeEntryForm({ entry, jobs }: TimeEntryFormProps) {
  const isEditing = entry !== null

  const { data, setData, post, put, transform, processing, errors, hasErrors, clearErrors } =
    useForm<TimeEntryFormData>({
      job_id: entry?.job ? String(entry.job.id) : jobs[0] ? String(jobs[0].id) : '',
      job_task_id: entry?.jobTask ? String(entry.jobTask.id) : '',
      task_label: entry?.taskLabel ?? '',
      date: entry?.date ?? new Date().toISOString().slice(0, 10),
      start_time: entry?.startTime?.slice(0, 5) ?? '',
      end_time: entry?.endTime?.slice(0, 5) ?? '',
      break_minutes: String(entry?.breakMinutes ?? 0),
      hours: entry ? String(entry.hours) : '',
      description: entry?.description ?? '',
      billable: entry?.billable ?? false,
    })

  const [tasks, setTasks] = useState<readonly TimeTrackingTaskOption[]>([])

  // The task list belongs to a specific job — reset it during render when the
  // job changes (not in the effect below, which only owns the async fetch).
  const [loadedForJobId, setLoadedForJobId] = useState(data.job_id)
  if (data.job_id !== loadedForJobId) {
    setLoadedForJobId(data.job_id)
    setTasks([])
  }

  useEffect(() => {
    if (!data.job_id) return undefined

    let cancelled = false
    fetch(routeTo.jobTimeEntryTasks(Number(data.job_id)), { headers: { Accept: 'application/json' } })
      .then((response) => response.json())
      .then((result: readonly TimeTrackingTaskOption[]) => {
        if (!cancelled) setTasks(result)
      })
      .catch(() => {
        if (!cancelled) setTasks([])
      })

    return () => {
      cancelled = true
    }
  }, [data.job_id])

  const update = <K extends FormDataKeys<TimeEntryFormData>>(
    field: K,
    value: FormDataValues<TimeEntryFormData, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const usingTimes = Boolean(data.start_time && data.end_time)
  const cancelHref = isEditing ? routeTo.timeEntry(entry.id) : ROUTES.timeEntries

  const submit = (event: React.FormEvent) => {
    event.preventDefault()

    // Laravel's default `ConvertEmptyStringsToNull` middleware turns any of
    // these empty strings into a real `null` before validation runs, so the
    // mutual-exclusivity rules below don't need to construct `null` here.
    transform((payload) => ({
      ...payload,
      job_task_id: payload.task_label ? '' : payload.job_task_id,
      hours: usingTimes ? '' : payload.hours,
    }))

    if (isEditing) {
      put(routeTo.timeEntry(entry.id), { preserveScroll: true })
    } else {
      post(ROUTES.timeEntries, { preserveScroll: true })
    }
  }

  return (
    <PageTransition>
      <Head title={isEditing ? 'Edit Time Entry' : 'Add Time Entry'} />

      <PageHeader
        title={isEditing ? 'Edit Time Entry' : 'Add Time Entry'}
        subtitle={isEditing ? entry.job?.name ?? undefined : 'Log a block of time against a job.'}
        breadcrumbs={[
          { label: 'Time Tracking', href: ROUTES.timeTracking },
          ...(isEditing ? [{ label: 'View', href: routeTo.timeEntry(entry.id) }] : []),
          { label: isEditing ? 'Edit' : 'Add' },
        ]}
        actions={
          <ButtonLink href={cancelHref} variant="secondary" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this entry can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading title="Entry details" />

          <div className="space-y-6">
            <div className="grid gap-6 sm:grid-cols-2">
              <SelectField
                id="entry-job"
                label="Job *"
                options={jobs.map((job) => ({
                  label: job.client ? `${job.name} — ${job.client}` : job.name,
                  value: String(job.id),
                }))}
                value={data.job_id}
                onChange={(event) => update('job_id', event.target.value)}
                {...(errors.job_id ? { error: errors.job_id } : {})}
              />
              <SelectField
                id="entry-task"
                label="Task"
                options={[
                  { label: 'No specific task', value: '' },
                  ...tasks.map((task) => ({ label: task.title, value: String(task.id) })),
                ]}
                value={data.job_task_id}
                onChange={(event) => update('job_task_id', event.target.value)}
              />
            </div>

            {!data.job_task_id && (
              <TextInput
                id="entry-task-label"
                label="Or describe the task"
                placeholder="e.g. Panel installation"
                value={data.task_label}
                onChange={(event) => update('task_label', event.target.value)}
              />
            )}

            <TextInput
              id="entry-date"
              type="date"
              label="Date *"
              value={data.date}
              onChange={(event) => update('date', event.target.value)}
              {...(errors.date ? { error: errors.date } : {})}
            />

            <div className="grid gap-6 sm:grid-cols-3">
              <TextInput
                id="entry-start"
                type="time"
                label="Start Time"
                value={data.start_time}
                onChange={(event) => update('start_time', event.target.value)}
              />
              <TextInput
                id="entry-end"
                type="time"
                label="End Time"
                value={data.end_time}
                onChange={(event) => update('end_time', event.target.value)}
                {...(errors.end_time ? { error: errors.end_time } : {})}
              />
              <TextInput
                id="entry-break"
                type="number"
                min={0}
                label="Break (min)"
                value={data.break_minutes}
                onChange={(event) => update('break_minutes', event.target.value)}
                {...(errors.break_minutes ? { error: errors.break_minutes } : {})}
              />
            </div>

            <TextInput
              id="entry-hours"
              type="number"
              min={0}
              step="0.25"
              label="Hours"
              hint={usingTimes ? 'Calculated from the start and end time above.' : 'Enter the hours worked directly.'}
              value={usingTimes ? '' : data.hours}
              disabled={usingTimes}
              onChange={(event) => update('hours', event.target.value)}
              {...(errors.hours ? { error: errors.hours } : {})}
            />

            <TextArea
              id="entry-description"
              label="Description"
              rows={4}
              placeholder="What was done…"
              value={data.description}
              onChange={(event) => update('description', event.target.value)}
            />

            <Checkbox
              id="entry-billable"
              label="Billable"
              checked={data.billable}
              onChange={(event) => setData('billable', event.target.checked)}
            />
          </div>

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            <ButtonLink href={cancelHref} variant="white">
              Cancel
            </ButtonLink>
            <Button type="submit" leftIcon={Save} isLoading={processing}>
              {isEditing ? 'Save Changes' : 'Log Time'}
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

TimeEntryForm.layout = appLayout
