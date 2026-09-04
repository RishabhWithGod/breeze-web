import { useMemo } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  RadioGroup,
  SectionHeading,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { JobSitePicker, TeamPicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_STATUS_OPTIONS, JOB_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type { JobOrigin } from '@/constants'
import type {
  ClientOption,
  JobDetail,
  JobStatus,
  JobType,
  ProjectOption,
  TakeoffUploadOption,
} from '@/types'

/** Edit payload — snake_case to match UpdateJobRequest. */
interface JobEditForm {
  name: string
  /** Who the work is for. */
  client_id: string
  /** And which of their projects it is on. */
  project_id: string
  /** The drawing the work is taken off. Empty when the job has none yet. */
  upload_id: string
  /** The client sites this job is at. Its `location` is written from the first. */
  address_ids: number[]
  description: string
  job_type: JobType | ''
  /** The crew this job is handed to. Empty means none. */
  team_id: string
  status: JobStatus
  start_date: string
  end_date: string
  budget: string
}

export interface JobEditProps {
  job: JobDetail
  /** Who the work can be for. */
  clients: readonly ClientOption[]
  /** Their projects — the list is narrowed to the picked client's. */
  projects: readonly ProjectOption[]
  /** And their drawings, narrowed in turn to the picked project's. */
  uploads: readonly TakeoffUploadOption[]
  /** The crews this job can be handed to. */
  teams: readonly { readonly id: number; readonly name: string }[]
  /**
   * Which screen this job was reached from, carried through the edit so that
   * saving lands back on a job whose own Back still knows the way out.
   */
  from: JobOrigin | null
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
export default function JobEdit({ job, clients, projects, uploads, teams, from }: JobEditProps) {
  /*
   * Every route out of this screen carries the trail: Back, Cancel, and the
   * save itself. Drop it from any one of them and the job someone lands on has
   * forgotten where they came from.
   */
  const jobUrl = routeTo.jobKeeping(job.id, from)
  const { data, setData, put, processing, errors, hasErrors, clearErrors } =
    useForm<JobEditForm>({
      name: job.name,
      client_id: job.clientId === null ? '' : String(job.clientId),
      project_id: job.projectId === null ? '' : String(job.projectId),
      upload_id: job.uploadId === null ? '' : String(job.uploadId),
      address_ids: [...job.addressIds],
      description: job.description ?? '',
      job_type: job.jobType ?? '',
      team_id: job.teamId === null ? '' : String(job.teamId),
      status: job.status,
      start_date: toDateInput(job.startDate),
      end_date: toDateInput(job.endDate),
      budget: job.budget === null ? '' : String(job.budget),
    })

  const update = <K extends FormDataKeys<JobEditForm>>(
    field: K,
    value: FormDataValues<JobEditForm, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const selectedClient = clients.find((option) => String(option.id) === data.client_id)

  /** Only the picked client's projects — a job never moves to someone else's. */
  const projectsForClient = useMemo(
    () => projects.filter((project) => String(project.clientId) === data.client_id),
    [projects, data.client_id],
  )

  /**
   * Changing the client invalidates everything under it: the project belongs to
   * the old client, and so do the sites.
   */
  const selectClient = (clientId: string) => {
    setData((current) => ({
      ...current,
      client_id: clientId,
      project_id: '',
      upload_id: '',
      address_ids: [],
    }))

    clearErrors('client_id', 'project_id', 'address_ids', 'upload_id')
  }

  /**
   * What a drawing has been priced at, as the budget field reads it — the same
   * rule as Create Job, so the two forms never disagree about the figure.
   */
  const estimatedBudget = (uploadId: string): string | null => {
    const amount = uploads.find((upload) => String(upload.id) === uploadId)?.estimate?.amount

    return amount === undefined || amount === null ? null : String(amount)
  }

  /** And the project answers the site, from its client's own address book. */
  const selectProject = (projectId: string) => {
    const project = projects.find((option) => String(option.id) === projectId)
    const primary = project?.addresses.find((site) => site.isPrimary)

    // The project's own drawing, so the usual case takes no second choice.
    const uploadId = project?.defaultUploadId ? String(project.defaultUploadId) : ''

    setData((current) => ({
      ...current,
      project_id: projectId,
      upload_id: job.drawingIsFixed ? current.upload_id : uploadId,
      address_ids: primary ? [primary.id] : current.address_ids,
      job_type: primary?.siteType ?? current.job_type,
    }))

    clearErrors('project_id', 'address_ids', 'upload_id')
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    put(jobUrl)
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

  const projectOptions = [
    {
      label: data.client_id === '' ? 'Select a client first' : 'Select project',
      value: '',
    },
    ...projectsForClient.map((project) => ({ label: project.name, value: String(project.id) })),
  ]

  const uploadsForProject = useMemo(
    () => uploads.filter((upload) => String(upload.projectId) === data.project_id),
    [uploads, data.project_id],
  )

  const uploadOptions = [
    {
      label: data.project_id === '' ? 'Select a project first' : 'No drawing',
      value: '',
    },
    ...uploadsForProject.map((upload) => ({ label: upload.name, value: String(upload.id) })),
  ]

  return (
    <PageTransition>
      <Head title={`Edit ${job.name}`} />

      <PageHeader
        title="Edit Job"
        subtitle={job.name}
        breadcrumbs={[
          { label: 'Jobs', href: ROUTES.jobs },
          { label: 'Details', href: jobUrl },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink href={jobUrl} variant="secondary" leftIcon={ArrowLeft}>
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

            {/*
              The same pair as Create Job, and in the same order: the client
              says which projects, and the project says which sites. Edit used
              to ask for one thing and post it as the other, which is why a job
              could not be saved from here at all.
            */}
            <fieldset>
              <legend className="mb-3 text-md font-medium text-white">
                Client and project
              </legend>
              <div className="grid gap-6 lg:grid-cols-2">
                <SelectField
                  id="job-client"
                  label="Client*"
                  options={clientOptions}
                  value={data.client_id}
                  onChange={(event) => selectClient(event.target.value)}
                  {...(errors.client_id ? { error: errors.client_id } : {})}
                />
                <SelectField
                  id="job-project"
                  label="Project*"
                  options={projectOptions}
                  value={data.project_id}
                  disabled={data.client_id === ''}
                  onChange={(event) => selectProject(event.target.value)}
                  {...(errors.project_id ? { error: errors.project_id } : {})}
                />
              </div>

              <div className="mt-6">
                <SelectField
                  id="job-upload"
                  label="AI Takeoff PDF"
                  className="lg:max-w-md"
                  options={uploadOptions}
                  value={data.upload_id}
                  disabled={data.project_id === '' || job.drawingIsFixed}
                  hint={
                    job.drawingIsFixed
                      ? 'Raised from a reviewed takeoff — its counts are this drawing’s, so it stays put.'
                      : 'The drawing this job is taken off. Its estimate fills the budget below.'
                  }
                  onChange={(event) => {
                    /*
                     * The drawing decides the budget: it is what its estimate
                     * came to. A drawing with no estimate leaves the figure
                     * alone rather than zeroing it, and it stays editable.
                     */
                    const uploadId = event.target.value

                    setData((current) => ({
                      ...current,
                      upload_id: uploadId,
                      budget: estimatedBudget(uploadId) ?? current.budget,
                    }))

                    if (errors.upload_id) clearErrors('upload_id')
                  }}
                  {...(errors.upload_id ? { error: errors.upload_id } : {})}
                />
              </div>
            </fieldset>

            <TextInput
              id="job-name"
              label="Job Name*"
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            {/* Same picker as Create Job, so a site can be added from here too. */}
            <fieldset>
              <legend className="mb-1 text-md font-medium text-white">Site Location*</legend>
              <JobSitePicker
                clientId={selectedClient?.id ?? null}
                clientName={selectedClient?.name ?? ''}
                sites={selectedClient?.addresses ?? []}
                value={data.address_ids}
                onChange={(addressIds) => {
                  // Moving a job to another site moves it to another kind of
                  // building, so the type follows — still editable below, and
                  // left alone when the new site has no type recorded.
                  const picked = (selectedClient?.addresses ?? []).find(
                    (site) => site.id === addressIds[0],
                  )

                  setData((current) => ({
                    ...current,
                    address_ids: addressIds,
                    job_type: picked?.siteType ?? current.job_type,
                  }))

                  if (errors.address_ids) clearErrors('address_ids')
                }}
                disabled={processing}
                {...(errors.address_ids ? { error: errors.address_ids } : {})}
              />
            </fieldset>

            {/*
              Moving the job to another crew moves who its tasks can be given
              to. Existing tasks keep whoever is on them — reassigning someone's
              work because the crew changed would be a decision, not a rename.
            */}
            <TeamPicker
              teams={teams}
              value={data.team_id}
              onChange={(next) => update('team_id', next)}
              hint="New tasks on this job are handed to this crew."
              disabled={processing}
              {...(errors.team_id ? { error: errors.team_id } : {})}
            />

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

          </div>

          <div className="mt-8 flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-6">
            <ButtonLink href={jobUrl} variant="white">
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
