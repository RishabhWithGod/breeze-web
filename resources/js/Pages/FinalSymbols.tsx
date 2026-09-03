import type { FormEvent } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm, usePage } from '@inertiajs/react'
import { ArrowLeft, ArrowRight, ExternalLink, Save } from 'lucide-react'
import {
  Alert,
  ButtonLink,
  Button,
  Card,
  RadioGroup,
  SectionHeading,
  TextArea,
  TextInput,
  WorkflowProgress,
} from '@/components/common'
import { JobSitePicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition, StepFooter } from '@/components/layout'
import { JOB_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type {
  ApprovalHistoryEntry,
  BoqLine,
  BoqMaterial,
  CircuitRow,
  ProjectOption,
  EngineBoqLine,
  EquipmentRow,
  FinalSymbolRow,
  JobType,
  Paginated,
  PanelScheduleRow,
  PipelineStage,
  SharedPageProps,
  WireSizeRow,
} from '@/types'
import { formatDate } from '@/utils'

/** Payload for the "Create the job" form below — mirrors `FinalTakeoffController::storeJob`. */
interface CreateJobForm {
  name: string
  /** The client this job is for. Defaults to the takeoff's, but can be another. */
  project_id: string
  /** The client sites this job is at. Its `location` is written from the first. */
  address_ids: number[]
  description: string
  job_type: JobType | ''
  start_date: string
  end_date: string
  budget: string
}

interface FinalResultSummary {
  readonly id: number
  readonly projectId: number
  /** The client's name — `Project.client` holds the same string. */
  readonly projectName: string
  readonly drawingName: string | null
  readonly modelVersion: string | null
  readonly isFinalised: boolean
  readonly finalisedAt: string | null
  readonly pageCount: number
  readonly workJobId: number | null
  /** The job as it stands, once it has been raised. */
  readonly job: {
    readonly name: string
    readonly projectId: number | null
    readonly addressIds: readonly number[]
    readonly description: string | null
    readonly jobType: JobType | null
    readonly startDate: string | null
    readonly endDate: string | null
    readonly budget: number | null
  } | null
  readonly workJobName: string | null
  readonly estimateId: number | null
  readonly estimateNumber: string | null
  readonly hasAnnotatedPdf: boolean
  /** Reported by the engine. */
  readonly runId: string | null
  readonly processingTime: number | null
  readonly pipelineStatus: readonly PipelineStage[]
  readonly warnings: readonly string[]
  readonly engineEstimate: {
    readonly subtotal?: number
    readonly tax_rate?: number
    readonly tax?: number
    readonly grand_total?: number
    readonly currency?: string
    readonly line_count?: number
  }
}

export interface FinalSymbolsProps {
  result: FinalResultSummary
  symbols: Paginated<FinalSymbolRow>
  filters: { search: string; source: string; sort: string }
  totals: {
    symbolTypes: number
    items: number
    approved: number
    rejected: number
    modified: number
    aiItems: number
    laborHours: number
    materialCost: number
  }
  boq: { lines: readonly BoqLine[]; materials: readonly BoqMaterial[] }
  /** The engine's own priced bill of quantities. */
  engineBoq: readonly EngineBoqLine[]
  wireSizes: readonly WireSizeRow[]
  panelSchedules: readonly PanelScheduleRow[]
  equipment: readonly EquipmentRow[]
  circuits: readonly CircuitRow[]
  /** Every project, with its sites, exactly as Create Job offers them. */
  projects: readonly ProjectOption[]
  /** The takeoff's own client — where the form starts. */
  defaultProjectId: number
  history: readonly ApprovalHistoryEntry[]
}

/**
 * The signed-off takeoff.
 *
 * Every quantity here comes from final_response.json — the reviewed document —
 * and is what the job and estimate are built from. The AI response is kept for
 * audit only and is never read again past this point.
 */
export default function FinalSymbols({ result, projects, defaultProjectId }: FinalSymbolsProps) {
  const { flash } = usePage<SharedPageProps>().props

  /*
   * Seeded from the job once it exists, so coming back to this step shows what
   * was filled in rather than a card describing it. Before that, from the
   * takeoff and its client.
   */
  const jobForm = useForm<CreateJobForm>({
    name: result.job?.name ?? result.projectName,
    project_id: String(result.job?.projectId ?? defaultProjectId),
    // That client's primary site, exactly as Create Job starts.
    address_ids:
      result.job?.addressIds.slice() ??
      projects
        .find((option) => option.id === defaultProjectId)
        ?.addresses.filter((site) => site.isPrimary)
        .map((site) => site.id) ??
      [],
    description: result.job?.description ?? '',
    /*
     * Recorded against the site, so it does not have to be answered twice — it
     * is the building that decides the type, and a client can own a house and a
     * warehouse. Still a field: this site's usual type is not every job's.
     */
    job_type:
      result.job?.jobType ??
      projects
        .find((option) => option.id === defaultProjectId)
        ?.addresses.find((site) => site.isPrimary)?.siteType ??
      '',
    start_date: result.job?.startDate ?? '',
    end_date: result.job?.endDate ?? '',
    budget: String(result.job?.budget ?? result.engineEstimate.grand_total ?? ''),
  })

  const updateJobField = <K extends FormDataKeys<CreateJobForm>>(
    field: K,
    value: FormDataValues<CreateJobForm, K>,
  ) => {
    jobForm.setData(field, value)
    if (jobForm.errors[field]) jobForm.clearErrors(field)
  }

  const selectedProject = projects.find(
    (option) => String(option.id) === jobForm.data.project_id,
  )

  const submitJob = (event: FormEvent) => {
    event.preventDefault()
    jobForm.post(routeTo.finalCreateJob(result.id))
  }

  return (
    <PageTransition>
      <Head title={`Review summary — ${result.projectName}`} />

      <PageHeader
        title="Review Summary"
        subtitle={`What the review produced for ${result.projectName}${
          result.finalisedAt ? `, signed off ${formatDate(result.finalisedAt)}` : ''
        }.`}
        breadcrumbs={[
          { label: 'AI Takeoff', href: ROUTES.aiTakeoff },
          { label: result.projectName, href: routeTo.review(result.id) },
          { label: 'Review summary' },
        ]}
        actions={
          /*
           * The step before, named outright: the estimate this screen follows,
           * or the review itself while there is no estimate yet. Not
           * `history.back()` — a POST that redirects does not always leave the
           * previous step as the entry behind it.
           */
          <ButtonLink
            href={
              result.estimateId
                // Still in the flow: going back a step must not leave it.
                ? routeTo.estimateInFlow(result.estimateId)
                : routeTo.review(result.id)
            }
            variant="secondary"
            size="sm"
            leftIcon={ArrowLeft}
          >
            Back
          </ButtonLink>
        }
      />

      {flash.warning && (
        <Alert tone="warning" className="mb-4">
          {flash.warning}
        </Alert>
      )}
      {flash.success && (
        <Alert tone="success" className="mb-4">
          {flash.success}
        </Alert>
      )}

      {/*
        Where this takeoff is, before anything else on the page. This screen is
        the job step, whether the job is still a form or already raised — the
        marker stays on it either way rather than pointing at the step after.
      */}
      <WorkflowProgress
        current="job"
        // Never the step you are on: the marker sits there instead of a tick.
        done={[
          'analysis',
          ...(result.isFinalised ? (['review'] as const) : []),
          ...(result.estimateId ? (['estimate'] as const) : []),
        ]}
        className="mb-6"
      />

      {/*
        Nothing is raised automatically once the review is signed off — the
        reviewer fills in the job's details here and creates it explicitly, the
        same fields as the standalone "Create New Job" screen.

        The same form afterwards, filled from the job: coming back to this step
        shows what was entered rather than a card describing it, and saving
        applies the edits to the job that is already there.
      */}
      <Card padding="lg" className="mb-6">
        <SectionHeading
          as="h3"
          title={result.workJobId ? 'The job' : 'Create the job'}
          actions={
            result.workJobId ? (
              <ButtonLink
                href={routeTo.job(result.workJobId)}
                variant="secondary"
                size="sm"
                leftIcon={ExternalLink}
              >
                Open the job
              </ButtonLink>
            ) : undefined
          }
        />

        <form onSubmit={submitJob} noValidate className="mt-4 space-y-6">
          <TextInput
            id="job-name"
            label="Job Name"
            value={jobForm.data.name}
            onChange={(event) => updateJobField('name', event.target.value)}
            {...(jobForm.errors.name ? { error: jobForm.errors.name } : {})}
          />

          {/*
            Stated, not chosen. This is the takeoff's own project, and the
            takeoff is what the job is being built from — offering to change it
            here would raise a job under a client whose drawing it is not.
            Changing either is done from the project's own screen.
          */}
          <div className="rounded-panel border border-hairline bg-white/4 p-4">
            <p className="text-2xs tracking-wide text-white/70 uppercase">
              Client and project
            </p>
            <p className="mt-1 truncate text-md text-white">
              {selectedProject
                ? `${selectedProject.clientName ?? 'Unassigned'} — ${selectedProject.name}`
                : 'This takeoff has no project on record'}
            </p>
          </div>

          <fieldset>
            <legend className="mb-1 text-md font-medium text-white">Site Location</legend>
            <JobSitePicker
              clientId={selectedProject?.clientId ?? null}
              clientName={selectedProject?.clientName ?? ''}
              sites={selectedProject?.addresses ?? []}
              value={jobForm.data.address_ids}
              onChange={(addressIds) => {
                // Changing the site changes the kind of building, so the type
                // follows it. A site with none recorded leaves it alone.
                const picked = (selectedProject?.addresses ?? []).find(
                  (site) => site.id === addressIds[0],
                )

                jobForm.setData((current) => ({
                  ...current,
                  address_ids: addressIds,
                  job_type: picked?.siteType ?? current.job_type,
                }))

                if (jobForm.errors.address_ids) jobForm.clearErrors('address_ids')
              }}
              disabled={jobForm.processing}
              {...(jobForm.errors.address_ids
                ? { error: jobForm.errors.address_ids }
                : {})}
            />
          </fieldset>

          <div className="grid gap-6 lg:grid-cols-3">
            <TextInput
              id="job-start"
              type="date"
              label="Start Date"
              value={jobForm.data.start_date}
              onChange={(event) => updateJobField('start_date', event.target.value)}
              {...(jobForm.errors.start_date ? { error: jobForm.errors.start_date } : {})}
            />
            <TextInput
              id="job-end"
              type="date"
              label="End Date"
              value={jobForm.data.end_date}
              onChange={(event) => updateJobField('end_date', event.target.value)}
              {...(jobForm.errors.end_date ? { error: jobForm.errors.end_date } : {})}
            />
            <TextInput
              id="job-budget"
              type="number"
              inputMode="decimal"
              min={0}
              step={50}
              label="Budget ($)"
              placeholder="Enter budget amount"
              value={jobForm.data.budget}
              onChange={(event) => updateJobField('budget', event.target.value)}
              {...(jobForm.errors.budget ? { error: jobForm.errors.budget } : {})}
            />
          </div>

          <TextArea
            id="job-description"
            label="Job Description"
            rows={4}
            placeholder="Enter detailed job description"
            value={jobForm.data.description}
            onChange={(event) => updateJobField('description', event.target.value)}
            {...(jobForm.errors.description ? { error: jobForm.errors.description } : {})}
          />

          <RadioGroup
            name="job-type"
            label="Job Type"
            options={JOB_TYPE_OPTIONS}
            value={jobForm.data.job_type}
            onChange={(value) => updateJobField('job_type', value as JobType)}
            {...(jobForm.errors.job_type ? { error: jobForm.errors.job_type } : {})}
          />

          <div className="flex flex-wrap justify-end gap-3 pt-2">
            {/*
              Saving on a job that exists is a save, not a second creation —
              `storeJob` applies the typed fields to it. Forward is then the
              task step, which the footer below offers on its own.
            */}
            <Button
              type="submit"
              {...(result.workJobId ? {} : { rightIcon: ArrowRight })}
              {...(result.workJobId ? { leftIcon: Save } : {})}
              isLoading={jobForm.processing}
            >
              {result.workJobId ? 'Save job' : 'Create Job'}
            </Button>
          </div>
        </form>

          {/*
            The way on, once there is a job. Without it, coming back to this
            step from the tasks left nowhere to go and the flow collapsed
            backwards into the estimate.
          */}
        {result.workJobId && (
          <StepFooter
            current="job"
            href={routeTo.jobTaskSetup(result.workJobId)}
            continueLabel="Continue to tasks"
          />
        )}
      </Card>
    </PageTransition>
  )
}

FinalSymbols.layout = appLayout
