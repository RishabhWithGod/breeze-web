import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  Checkbox,
  RadioGroup,
  SectionHeading,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { JobSitePicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_STATUS_OPTIONS, JOB_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type { ClientOption, JobDetail, JobStatus, JobType } from '@/types'

/** Edit payload — snake_case to match UpdateJobRequest. */
interface JobEditForm {
  name: string
  /** The client. Clients are projects, so this is a `projects` id. */
  project_id: string
  /** The client sites this job is at. Its `location` is written from the first. */
  address_ids: number[]
  description: string
  job_type: JobType | ''
  status: JobStatus
  start_date: string
  end_date: string
  budget: string
  create_estimate: boolean
  assign_team: boolean
  notify_client: boolean
}

export interface JobEditProps {
  job: JobDetail
  /** The client register. Clients are projects, so this is one list, not two. */
  clients: readonly ClientOption[]
}

/** Date inputs need `yyyy-MM-dd`; the server sends ISO timestamps. */
function toDateInput(iso: string | null): string {
  return iso ? iso.slice(0, 10) : ''
}

/**
 * Edit Job.
 *
 * Puts to UpdateJobRequest. Status changes made here are recorded in the status
 * history by the controller, exactly as they are from the detail screen.
 */
export default function JobEdit({ job, clients }: JobEditProps) {
  const { data, setData, put, processing, errors, hasErrors, clearErrors } =
    useForm<JobEditForm>({
      name: job.name,
      project_id: job.clientId === null ? '' : String(job.clientId),
      address_ids: [...job.addressIds],
      description: job.description ?? '',
      job_type: job.jobType ?? '',
      status: job.status,
      start_date: toDateInput(job.startDate),
      end_date: toDateInput(job.endDate),
      budget: job.budget === null ? '' : String(job.budget),
      create_estimate: job.options.createEstimate,
      assign_team: job.options.assignTeam,
      notify_client: job.options.notifyClient,
    })

  const update = <K extends FormDataKeys<JobEditForm>>(
    field: K,
    value: FormDataValues<JobEditForm, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const selectedClient = clients.find((option) => String(option.id) === data.project_id)

  /** Changing the client drops sites that belonged to the old one. */
  const selectClient = (clientId: string) => {
    setData((current) => ({ ...current, project_id: clientId, address_ids: [] }))
    clearErrors('project_id', 'address_ids')
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    put(routeTo.job(job.id))
  }

  /**
   * A job created before clients and projects were merged may name a client
   * that never became a record. Its stored name becomes the placeholder, so the
   * select shows who the job is for rather than a blank "Select client" — but
   * the placeholder has no value, so saving still requires picking a real one.
   */
  const clientOptions = [
    job.clientId === null && job.client
      ? { label: `${job.client} — not yet a client record`, value: '' }
      : { label: 'Select client', value: '' },
    ...clients.map((client) => ({ label: client.name, value: String(client.id) })),
  ]

  return (
    <PageTransition>
      <Head title={`Edit ${job.name}`} />

      <PageHeader
        title="Edit Job"
        subtitle={job.name}
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Details', href: routeTo.job(job.id) },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink
            href={routeTo.job(job.id)}
            variant="secondary"
            leftIcon={ArrowLeft}
          >
            Back to job
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this job can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <Card padding="lg">
          <SectionHeading title="Job details" />

          <div className="space-y-6">
            <TextInput
              id="job-name"
              label="Job Name*"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            <SelectField
              id="job-client"
              label="Client*"
              hint="Not listed? Add them under Clients first."
              className="lg:max-w-md"
              options={clientOptions}
              value={data.project_id}
              onChange={(event) => selectClient(event.target.value)}
              {...(errors.project_id ? { error: errors.project_id } : {})}
            />

            {/* Same picker as Create Job, so a site can be added from here too. */}
            <fieldset>
              <legend className="mb-1 text-md font-medium text-white">Site Location*</legend>
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

            <div className="grid gap-6 lg:grid-cols-3">
              <SelectField
                id="job-status"
                label="Status"
                options={JOB_STATUS_OPTIONS}
                value={data.status}
                onChange={(event) => update('status', event.target.value as JobStatus)}
                {...(errors.status ? { error: errors.status } : {})}
              />
              <TextInput
                id="job-budget"
                type="number"
                inputMode="decimal"
                min={0}
                step={50}
                label="Budget ($)"
                value={data.budget}
                onChange={(event) => update('budget', event.target.value)}
                {...(errors.budget ? { error: errors.budget } : {})}
              />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              <TextInput
                id="job-start"
                type="date"
                label="Start date"
                value={data.start_date}
                onChange={(event) => update('start_date', event.target.value)}
                {...(errors.start_date ? { error: errors.start_date } : {})}
              />
              <TextInput
                id="job-end"
                type="date"
                label="End date"
                value={data.end_date}
                onChange={(event) => update('end_date', event.target.value)}
                {...(errors.end_date ? { error: errors.end_date } : {})}
              />
            </div>

            <TextArea
              id="job-description"
              label="Job Description"
              rows={5}
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
          </div>

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            <ButtonLink href={routeTo.job(job.id)} variant="white">
              Cancel
            </ButtonLink>
            <Button type="submit" leftIcon={Save} isLoading={processing}>
              Save changes
            </Button>
          </div>
        </Card>
      </form>
    </PageTransition>
  )
}

JobEdit.layout = appLayout
