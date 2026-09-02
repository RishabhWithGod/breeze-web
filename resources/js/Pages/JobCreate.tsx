import { useMemo, useState } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, FileCheck2, Lightbulb } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  RadioGroup,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { JobSitePicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_TYPE_OPTIONS, ROUTES } from '@/constants'
import type {
  ClientOption,
  JobDraft,
  JobForeman,
  JobType,
  TakeoffUploadOption,
} from '@/types'
import { formatCurrency } from '@/utils'

export interface JobCreateProps {
  foremen: readonly JobForeman[]
  /** The client register. Clients are projects, so this is one list, not two. */
  clients: readonly ClientOption[]
  /** Their drawings — narrowed to the picked client once one is chosen. */
  uploads: readonly TakeoffUploadOption[]
}

/**
 * Create New Job.
 *
 * Posts to StoreJobRequest and renders whatever it rejects, so the form never
 * restates the server's rules. "Save as Draft" and "Create Job" post the same
 * payload — the server picks the status from the `save_as_draft` flag.
 */
export default function JobCreate({ foremen, clients, uploads }: JobCreateProps) {
  const [savingDraft, setSavingDraft] = useState(false)

  const { data, setData, post, processing, errors, hasErrors, clearErrors, transform } =
    useForm<JobDraft>({
      name: '',
      address_ids: [],
      description: '',
      job_type: '',
      start_date: '',
      end_date: '',
      budget: '',
      foreman_id: '',
      save_as_draft: false,
      project_id: '',
      upload_id: '',
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
    post(ROUTES.jobs)
  }

  const clientOptions = [
    { label: 'Select client', value: '' },
    ...clients.map((client) => ({ label: client.name, value: String(client.id) })),
  ]

  /**
   * Picking the client also carries over its location, due date and type —
   * but only into a field still blank, never over something already typed.
   */
  const selectClient = (clientId: string) => {
    const client = clients.find((option) => String(option.id) === clientId)

    setData((current) => ({
      ...current,
      project_id: clientId,
      // Both belong to the old client, so neither survives the change.
      upload_id: '',
      address_ids: client
        ? client.addresses.filter((site) => site.isPrimary).map((site) => site.id)
        : [],
      // Filled from the client, but only into a field still blank — never over
      // something already typed.
      name: current.name || (client?.name ?? ''),
      start_date: current.start_date || (client?.dueDate ?? ''),
      job_type: current.job_type || (client?.projectType ?? ''),
    }))

    clearErrors('project_id', 'address_ids')
  }

  const selectedClient = clients.find((option) => String(option.id) === data.project_id)

  const foremanOptions = [
    { label: 'Assign later', value: '' },
    ...foremen.map((foreman) => ({
      label: foreman.name,
      value: String(foreman.id),
    })),
  ]

  /** Only the picked client's drawings — a job never links to someone else's. */
  const uploadsForClient = useMemo(
    () => uploads.filter((upload) => String(upload.projectId) === data.project_id),
    [uploads, data.project_id],
  )

  const uploadOptions = [
    { label: 'No linked drawing', value: '' },
    ...uploadsForClient.map((upload) => ({ label: upload.name, value: String(upload.id) })),
  ]

  const linkedEstimate = uploadsForClient.find(
    (upload) => String(upload.id) === data.upload_id,
  )?.estimate

  return (
    <PageTransition>
      <Head title="Create New Job" />

      <PageHeader
        title="Create New Job"
        subtitle="Fill in the details below to create a new job in the system"
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Create' },
        ]}
        actions={
          <ButtonLink href={ROUTES.jobs} variant="secondary" leftIcon={ArrowLeft}>
            Back to jobs
          </ButtonLink>
        }
      />

      <section className="overflow-hidden rounded-card border border-hairline glass shadow-panel">
        <div className="p-6 sm:p-8 xl:p-10">

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
            {/* Picked first — location, schedule and job type below all carry
                over from the client the moment it's chosen (and only fill a
                field that's still blank), so this has to come before them. */}
            <fieldset>
              <legend className="mb-3 text-md font-medium text-white">Client</legend>
              <div className="grid gap-6 lg:grid-cols-2">
                <SelectField
                  id="job-client"
                  label="Client*"
                  hint="Not listed? Add them under Clients first."
                  options={clientOptions}
                  value={data.project_id}
                  onChange={(event) => selectClient(event.target.value)}
                  {...(errors.project_id ? { error: errors.project_id } : {})}
                />
                <SelectField
                  id="job-upload"
                  label="AI Takeoff PDF (optional)"
                  options={uploadOptions}
                  value={data.upload_id}
                  disabled={!data.project_id}
                  onChange={(event) => update('upload_id', event.target.value)}
                  {...(errors.upload_id ? { error: errors.upload_id } : {})}
                />
              </div>

              {linkedEstimate && (
                <Alert tone="info" icon={FileCheck2} title="This drawing already has an estimate" className="mt-4">
                  <p>
                    Estimate {linkedEstimate.number} —{' '}
                    {formatCurrency(linkedEstimate.amount, 2)}. Creating this job links it here
                    instead of raising a new one.
                  </p>
                  <ButtonLink
                    href={linkedEstimate.editUrl}
                    variant="secondary"
                    size="sm"
                    className="mt-3"
                  >
                    Edit estimate
                  </ButtonLink>
                </Alert>
              )}
            </fieldset>

            <TextInput
              id="job-name"
              label="Job Name*"
              placeholder="Enter job name"
              hint="Filled from the client when you pick one — change it to anything."
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            {/*
              Sites come from the client's own address book, so an address on
              file is never retyped — and one that is not on it yet can be added
              without leaving this form.
            */}
            <fieldset>
              <legend className="mb-1 text-md font-medium text-white">Site(s)*</legend>
              <JobSitePicker
                client={selectedClient}
                value={data.address_ids}
                onChange={(addressIds) => {
                  setData('address_ids', addressIds)
                  if (errors.address_ids) clearErrors('address_ids')
                }}
                disabled={processing}
                {...(errors.address_ids ? { error: errors.address_ids } : {})}
              />
            </fieldset>

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

            <SelectField
              id="job-foreman"
              label="Foreman"
              options={foremanOptions}
              value={data.foreman_id}
              onChange={(event) => update('foreman_id', event.target.value)}
              {...(errors.foreman_id ? { error: errors.foreman_id } : {})}
            />

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
          <p className="mt-1 text-md text-white">
            You can also create a job from an existing estimate or by using our AI
            Takeoff feature to automatically generate job details from blueprints.
          </p>
        </div>
      </aside>
    </PageTransition>
  )
}

JobCreate.layout = appLayout
