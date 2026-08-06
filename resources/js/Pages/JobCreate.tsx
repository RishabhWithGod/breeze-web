import { useState } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { Lightbulb } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Checkbox,
  RadioGroup,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageTransition } from '@/components/layout'
import { JOB_TYPE_OPTIONS, ROUTES } from '@/constants'
import type { JobDraft, JobForeman, JobType } from '@/types'

export interface JobCreateProps {
  foremen: readonly JobForeman[]
  /** Clients already on record, offered in the Client select. */
  clients: readonly string[]
}

/**
 * Create New Job.
 *
 * Posts to StoreJobRequest and renders whatever it rejects, so the form never
 * restates the server's rules. "Save as Draft" and "Create Job" post the same
 * payload — the server picks the status from the `save_as_draft` flag.
 */
export default function JobCreate({ foremen, clients }: JobCreateProps) {
  const [savingDraft, setSavingDraft] = useState(false)

  const { data, setData, post, processing, errors, hasErrors, clearErrors, transform } =
    useForm<JobDraft>({
      name: '',
      client: '',
      location: '',
      description: '',
      job_type: '',
      start_date: '',
      end_date: '',
      budget: '',
      foreman_id: '',
      create_estimate: false,
      assign_team: false,
      notify_client: false,
      save_as_draft: false,
    })

  /**
   * Inertia keeps server errors until the next request, which would leave
   * "Client is required" sitting under a field the user has just filled in, so
   * each edit clears its own message.
   */
  const update = <K extends FormDataKeys<JobDraft>>(
    field: K,
    value: FormDataValues<JobDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  /**
   * Both buttons post the same payload; only the draft flag differs. It goes on
   * via `transform` rather than `setData` because a state update would not be
   * visible to a `post()` fired in the same tick.
   */
  const submit = (asDraft: boolean) => {
    setSavingDraft(asDraft)
    transform((payload) => ({ ...payload, save_as_draft: asDraft }))
    post(ROUTES.jobs, { preserveScroll: true })
  }

  const clientOptions = [
    { label: 'Select client', value: '' },
    ...clients.map((client) => ({ label: client, value: client })),
  ]

  const foremanOptions = [
    { label: 'Assign later', value: '' },
    ...foremen.map((foreman) => ({
      label: foreman.name,
      value: String(foreman.id),
    })),
  ]

  return (
    <PageTransition>
      <Head title="Create New Job" />

      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-6 sm:p-8 xl:p-10">
          <header className="mb-8">
            <h1 className="text-3xl font-bold text-white sm:text-4xl">Create New Job</h1>
            <p className="mt-2 text-md text-white/70">
              Fill in the details below to create a new job in the system
            </p>
          </header>

          {hasErrors && (
            <Alert tone="danger" title="Check the form" className="mb-6">
              Some fields need attention before this job can be saved.
            </Alert>
          )}

          <form
            onSubmit={(event) => {
              event.preventDefault()
              submit(false)
            }}
            noValidate
            className="space-y-6"
          >
            <TextInput
              id="job-name"
              label="Job Name*"
              placeholder="Enter job name"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <div className="grid gap-6 lg:grid-cols-2">
              <SelectField
                id="job-client"
                label="Client*"
                options={clientOptions}
                value={data.client}
                onChange={(event) => update('client', event.target.value)}
                {...(errors.client ? { error: errors.client } : {})}
              />
              <TextInput
                id="job-location"
                label="Location*"
                placeholder="Enter job location"
                value={data.location}
                onChange={(event) => update('location', event.target.value)}
                {...(errors.location ? { error: errors.location } : {})}
              />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              <TextInput
                id="job-start"
                type="date"
                label="Schedule"
                placeholder="Select start date"
                value={data.start_date}
                onChange={(event) => update('start_date', event.target.value)}
                {...(errors.start_date ? { error: errors.start_date } : {})}
              />
              <TextInput
                id="job-budget"
                type="number"
                inputMode="decimal"
                min={0}
                step={50}
                label="Budget ($)"
                placeholder="Enter budget amount"
                value={data.budget}
                onChange={(event) => update('budget', event.target.value)}
                {...(errors.budget ? { error: errors.budget } : {})}
              />
            </div>

            <TextArea
              id="job-description"
              label="Job Description"
              rows={5}
              placeholder="Enter detailed job description"
              value={data.description}
              onChange={(event) => update('description', event.target.value)}
              {...(errors.description ? { error: errors.description } : {})}
            />

            <RadioGroup
              name="job-type"
              label="Job Type"
              options={JOB_TYPE_OPTIONS}
              value={data.job_type}
              onChange={(value) => update('job_type', value as JobType)}
              {...(errors.job_type ? { error: errors.job_type } : {})}
            />

            <fieldset>
              <legend className="mb-3 text-md font-medium text-white">
                Additional Options
              </legend>
              {/* `Checkbox` renders an inline-flex label, so a flex column is
                  what actually stacks them. */}
              <div className="flex flex-col items-start gap-3">
                <Checkbox
                  id="job-create-estimate"
                  label="Create estimate for this job"
                  checked={data.create_estimate}
                  onChange={(event) => setData('create_estimate', event.target.checked)}
                />
                <Checkbox
                  id="job-assign-team"
                  label="Assign team members"
                  checked={data.assign_team}
                  onChange={(event) => setData('assign_team', event.target.checked)}
                />
                <Checkbox
                  id="job-notify-client"
                  label="Notify client when job is created"
                  checked={data.notify_client}
                  onChange={(event) => setData('notify_client', event.target.checked)}
                />
              </div>
            </fieldset>

            {/* Shown only when the team-assignment option is ticked, so the
                default form matches the reference exactly. */}
            {data.assign_team && (
              <SelectField
                id="job-foreman"
                label="Foreman"
                options={foremanOptions}
                value={data.foreman_id}
                onChange={(event) => update('foreman_id', event.target.value)}
                {...(errors.foreman_id ? { error: errors.foreman_id } : {})}
              />
            )}

            <div className="flex flex-wrap items-center justify-end gap-3 pt-2">
              <ButtonLink href={ROUTES.jobs} variant="white">
                Cancel
              </ButtonLink>
              <Button
                type="button"
                variant="white"
                isLoading={processing && savingDraft}
                onClick={() => submit(true)}
              >
                Save as Draft
              </Button>
              <Button type="submit" isLoading={processing && !savingDraft}>
                Create Job
              </Button>
            </div>
          </form>
        </div>
      </section>

      {/* ------------------------------------------------------- Pro tip ---- */}
      <aside className="mt-6 flex items-start gap-4 rounded-card border border-hairline glass p-5 shadow-panel sm:p-6">
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-brand/15 text-brand">
          <Lightbulb size={20} aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="text-md font-semibold text-brand">Pro Tip</p>
          <p className="mt-1 text-md text-white/75">
            You can also create a job from an existing estimate or by using our AI
            Takeoff feature to automatically generate job details from blueprints.
          </p>
        </div>
      </aside>
    </PageTransition>
  )
}

JobCreate.layout = appLayout
