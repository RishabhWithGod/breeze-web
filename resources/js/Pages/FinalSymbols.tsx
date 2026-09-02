import type { FormEvent } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm, usePage } from '@inertiajs/react'
import { ArrowRight, Table2 } from 'lucide-react'
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
  WorkflowProgress,
} from '@/components/common'
import { JobSitePicker } from '@/components/jobs'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { JOB_TYPE_OPTIONS, ROUTES, routeTo } from '@/constants'
import type {
  ApprovalHistoryEntry,
  BoqLine,
  BoqMaterial,
  CircuitRow,
  ClientOption,
  EngineBoqLine,
  EquipmentRow,
  FinalSymbolRow,
  JobForeman,
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
  foreman_id: string
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
  /** The client register, and its sites, exactly as Create Job offers them. */
  clients: readonly ClientOption[]
  /** The takeoff's own client — where the form starts. */
  defaultClientId: number
  foremen: readonly JobForeman[]
  history: readonly ApprovalHistoryEntry[]
}

/**
 * The signed-off takeoff.
 *
 * Every quantity here comes from final_response.json — the reviewed document —
 * and is what the job and estimate are built from. The AI response is kept for
 * audit only and is never read again past this point.
 */
export default function FinalSymbols({
  result,
  clients,
  defaultClientId,
  foremen,
}: FinalSymbolsProps) {
  const { flash } = usePage<SharedPageProps>().props

  const jobForm = useForm<CreateJobForm>({
    name: result.projectName,
    project_id: String(defaultClientId),
    // That client's primary site, exactly as Create Job starts.
    address_ids:
      clients
        .find((option) => option.id === defaultClientId)
        ?.addresses.filter((site) => site.isPrimary)
        .map((site) => site.id) ?? [],
    description: '',
    job_type: '',
    start_date: '',
    end_date: '',
    budget: result.engineEstimate.grand_total
      ? String(result.engineEstimate.grand_total)
      : '',
    foreman_id: '',
  })

  const updateJobField = <K extends FormDataKeys<CreateJobForm>>(
    field: K,
    value: FormDataValues<CreateJobForm, K>,
  ) => {
    jobForm.setData(field, value)
    if (jobForm.errors[field]) jobForm.clearErrors(field)
  }

  const selectedClient = clients.find(
    (option) => String(option.id) === jobForm.data.project_id,
  )

  const clientOptions = clients.map((option) => ({
    label: option.id === defaultClientId ? `${option.name} (this takeoff)` : option.name,
    value: String(option.id),
  }))

  /** Changing the client drops sites that belonged to the old one. */
  const selectClient = (clientId: string) => {
    const client = clients.find((option) => String(option.id) === clientId)

    jobForm.setData((current) => ({
      ...current,
      project_id: clientId,
      address_ids:
        client?.addresses.filter((site) => site.isPrimary).map((site) => site.id) ?? [],
    }))
  }

  const foremanOptions = [
    { label: 'Assign later', value: '' },
    ...foremen.map((foreman) => ({ label: foreman.name, value: String(foreman.id) })),
  ]

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
          <div className="flex flex-wrap items-center gap-2">
            <ButtonLink
              href={routeTo.review(result.id)}
              variant="ghost"
              size="sm"
              leftIcon={Table2}
            >
              Back to Review
            </ButtonLink>
          </div>
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

      {/* Where this takeoff is, before anything else on the page. */}
      <WorkflowProgress
        current={
          result.workJobId
            ? 'schedule'
            : result.estimateId
              ? 'job'
              : result.isFinalised
                ? 'estimate'
                : 'review'
        }
        done={[
          'analysis',
          ...(result.isFinalised ? (['review'] as const) : []),
          ...(result.estimateId ? (['estimate'] as const) : []),
          ...(result.workJobId ? (['job'] as const) : []),
        ]}
        className="mb-6"
      />

      {/*
        Nothing is raised automatically once the review is signed off — the
        reviewer fills in the job's details here and creates it explicitly,
        the same fields as the standalone "Create New Job" screen.
      */}
      {!result.workJobId && (
        <Card padding="lg" className="mb-6">
          <SectionHeading
            as="h3"
            title="Create the job"
            subtitle="Nothing is created until you submit this — fill in what you know."
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
              Starts on the takeoff's own client, because that is nearly always
              the answer — but work is sometimes taken off one client's drawing
              and built for another, so it can be changed. The takeoff itself
              stays linked either way.
            */}
            <SelectField
              id="job-client"
              label="Client"
              hint={
                jobForm.data.project_id === String(defaultClientId)
                  ? 'The client this takeoff is for.'
                  : 'Not this takeoff’s own client — the takeoff stays linked to the job.'
              }
              options={clientOptions}
              value={jobForm.data.project_id}
              onChange={(event) => selectClient(event.target.value)}
              {...(jobForm.errors.project_id ? { error: jobForm.errors.project_id } : {})}
            />

            <fieldset>
              <legend className="mb-1 text-md font-medium text-white">Site(s)</legend>
              <JobSitePicker
                client={selectedClient}
                value={jobForm.data.address_ids}
                onChange={(addressIds) => updateJobField('address_ids', addressIds)}
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

            <SelectField
              id="job-foreman"
              label="Foreman"
              options={foremanOptions}
              value={jobForm.data.foreman_id}
              onChange={(event) => updateJobField('foreman_id', event.target.value)}
              {...(jobForm.errors.foreman_id ? { error: jobForm.errors.foreman_id } : {})}
            />

            <div className="flex justify-end pt-2">
              <Button type="submit" rightIcon={ArrowRight} isLoading={jobForm.processing}>
                Create Job
              </Button>
            </div>
          </form>
        </Card>
      )}
    </PageTransition>
  )
}

FinalSymbols.layout = appLayout
