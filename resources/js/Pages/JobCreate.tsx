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
  UnfinishedTakeoffNotice,
} from '@/components/common'
import { JobSitePicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type {
  ClientOption,
  JobDraft,
  JobType,
  ProjectOption,
  ResumableTakeoff,
  TakeoffUploadOption,
} from '@/types'
import { formatCurrency } from '@/utils'

export interface JobCreateProps {
  /** The client register — who the work is for. */
  clients: readonly ClientOption[]
  /** Their projects — what the work is. Each carries its sites and drawing. */
  projects: readonly ProjectOption[]
  /** Their drawings — narrowed to the picked client once one is chosen. */
  uploads: readonly TakeoffUploadOption[]
  /**
   * A takeoff already part-way through. Raising a job by hand is a fork of it:
   * the takeoff's own job is raised from its review summary.
   */
  unfinishedTakeoff: ResumableTakeoff | null
}

/**
 * Create New Job.
 *
 * Posts to StoreJobRequest and renders whatever it rejects, so the form never
 * restates the server's rules. "Save as Draft" and "Create Job" post the same
 * payload — the server picks the status from the `save_as_draft` flag.
 */
export default function JobCreate({
  clients,
  projects,
  uploads,
  unfinishedTakeoff,
}: JobCreateProps) {
  const [savingDraft, setSavingDraft] = useState(false)

  const { data, setData, post, processing, errors, hasErrors, clearErrors, transform } =
    useForm<JobDraft>({
      client_id: '',
      name: '',
      address_ids: [],
      description: '',
      job_type: '',
      start_date: '',
      end_date: '',
      budget: '',
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
   * Choosing the client narrows everything below it. Their projects are the
   * next question, and nothing of the old client's survives the change.
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
   * And the project answers the rest: its drawing, its site, and through that
   * site the kind of building the work is in. The name is filled only if still
   * blank — never over something already typed.
   */
  const selectProject = (projectId: string) => {
    const project = projects.find((option) => String(option.id) === projectId)
    const primary = project?.addresses.find((site) => site.isPrimary)

    setData((current) => ({
      ...current,
      project_id: projectId,
      // The project's own drawing, so the usual case takes no second choice.
      upload_id: project?.defaultUploadId ? String(project.defaultUploadId) : '',
      address_ids: primary ? [primary.id] : [],
      name: current.name || (project?.name ?? ''),
      /*
       * The type comes from the site, because it is the building that decides
       * it — a client can own a house and a warehouse. Only a site that has
       * been answered for speaks; otherwise whatever was already chosen stands.
       */
      job_type: primary?.siteType ?? current.job_type,
    }))

    clearErrors('project_id', 'address_ids', 'upload_id')
  }

  const projectsForClient = useMemo(
    () => projects.filter((project) => String(project.clientId) === data.client_id),
    [projects, data.client_id],
  )

  const projectOptions = [
    {
      label: data.client_id === '' ? 'Pick a client first' : 'Select project',
      value: '',
    },
    ...projectsForClient.map((project) => ({
      label: project.name,
      value: String(project.id),
    })),
  ]

  const selectedClient = clients.find((option) => String(option.id) === data.client_id)
  const selectedProject = projects.find((option) => String(option.id) === data.project_id)

  const uploadsForProject = useMemo(
    () => uploads.filter((upload) => String(upload.projectId) === data.project_id),
    [uploads, data.project_id],
  )

  const uploadOptions = [
    { label: 'Select a drawing', value: '' },
    ...uploadsForProject.map((upload) => ({ label: upload.name, value: String(upload.id) })),
  ]

  const linkedEstimate = uploadsForProject.find(
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
            Back
          </ButtonLink>
        }
      />

      <UnfinishedTakeoffNotice
        takeoff={unfinishedTakeoff}
        starting="a job by hand"
        className="mb-6"
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
            {/*
              Picked first, and each narrows the next: the client says which
              projects, the project says which drawing, which sites and which
              type. Everything below carries over from the project the moment
              it is chosen — and only into a field still blank — so this has to
              come before them.
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
                  disabled={!data.client_id}
                  onChange={(event) => selectProject(event.target.value)}
                  {...(errors.project_id ? { error: errors.project_id } : {})}
                />
              </div>

              <div className="mt-6">
                <SelectField
                  id="job-upload"
                  label="AI Takeoff PDF*"
                  className="lg:max-w-md"
                  options={uploadOptions}
                  value={data.upload_id}
                  disabled={!data.project_id}
                  onChange={(event) => update('upload_id', event.target.value)}
                  {...(errors.upload_id ? { error: errors.upload_id } : {})}
                />
              </div>

              {/*
                A job is the work on a drawing, so one is required — which would
                be a dead end for a project that has none. Say so, and offer the
                one screen that fixes it.
              */}
              {selectedProject && uploadsForProject.length === 0 && (
                <Alert tone="warning" title="No drawing on record" className="mt-4">
                  <p>
                    {selectedProject.name} has no drawing yet, and a job is
                    raised against one. Upload it in AI Takeoff, then come back.
                  </p>
                  <ButtonLink
                    href={routeTo.uploadForProject(selectedProject.id)}
                    variant="secondary"
                    size="sm"
                    className="mt-3"
                  >
                    Upload a drawing
                  </ButtonLink>
                </Alert>
              )}

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
              value={data.name}
              onChange={(event) => update('name', event.target.value)}
              {...(errors.name ? { error: errors.name } : {})}
            />

            {/*
              The sites come from the client's own book, so an address on file
              is never retyped — and one the book does not have yet is added
              without leaving this form.
            */}
            <fieldset>
              <legend className="mb-1 text-md font-medium text-white">Site Location*</legend>
              <JobSitePicker
                clientId={selectedClient?.id ?? null}
                clientName={selectedClient?.name ?? ''}
                sites={selectedClient?.addresses ?? []}
                value={data.address_ids}
                onChange={(addressIds) => {
                  /*
                   * The site carries the type, so changing the site changes it
                   * — including a site added from inside the picker, which
                   * arrives here the moment its props refresh. A site with no
                   * type recorded leaves the choice alone rather than blanking
                   * it, and the field below stays editable either way.
                   */
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
