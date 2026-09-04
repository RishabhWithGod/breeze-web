import { useMemo } from 'react'
import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import {
  ArrowLeft,
  Building2,
  CalendarDays,
  FileCheck2,
  FileText,
  Save,
  Wallet,
} from 'lucide-react'
import {
  Alert,
  Button,
  ButtonLink,
  Card,
  SectionHeading,
  SelectField,
  StatusChip,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ESTIMATE_STATUS_OPTIONS, ROUTES } from '@/constants'
import type {
  ClientOption,
  EstimateDraft,
  ProjectOption,
  TakeoffUploadOption,
} from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

export interface EstimateCreateProps {
  /** Reference the server will assign on save. */
  nextNumber: string
  /** Who the estimate can be for. */
  clients: readonly ClientOption[]
  /** Their projects — the list narrows to the picked client's. */
  projects: readonly ProjectOption[]
  /** And their drawings, narrowed in turn to the picked project's. */
  uploads: readonly TakeoffUploadOption[]
}

/**
 * Create Estimate — a full screen rather than a dialog, so the form has room
 * for a live summary beside it.
 *
 * Posts to StoreEstimateRequest and renders whatever it rejects, so the form
 * never restates the server's rules. On success Laravel redirects to the
 * estimates list with the new row already in place.
 */
export default function EstimateCreate({
  nextNumber,
  clients,
  projects,
  uploads,
}: EstimateCreateProps) {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<EstimateDraft>({
      issued_on: '',
      amount: '',
      status: 'draft',
      client_id: '',
      project_id: '',
      upload_id: '',
    })

  /**
   * Inertia keeps server errors until the next request. On a full screen that
   * would leave "Client is required" sitting under a field the user has just
   * filled in, so each edit clears its own message.
   */
  const update = <K extends FormDataKeys<EstimateDraft>>(
    field: K,
    value: FormDataValues<EstimateDraft, K>,
  ) => {
    setData(field, value)
    if (errors[field]) clearErrors(field)
  }

  const submit = (event?: React.FormEvent) => {
    event?.preventDefault()
    post(ROUTES.estimates)
  }

  const amount = Number(data.amount)
  const hasAmount = data.amount !== '' && Number.isFinite(amount)

  const clientOptions = [
    { label: 'Select client', value: '' },
    ...clients.map((client) => ({ label: client.name, value: String(client.id) })),
  ]

  /** Only the picked client's projects — an estimate is never on someone else's. */
  const projectsForClient = useMemo(
    () => projects.filter((project) => String(project.clientId) === data.client_id),
    [projects, data.client_id],
  )

  const projectOptions = [
    { label: data.client_id === '' ? 'Select a client first' : 'Select project', value: '' },
    ...projectsForClient.map((project) => ({ label: project.name, value: String(project.id) })),
  ]

  /** And only that project's drawings — the same narrowing, one step further. */
  const uploadsForProject = useMemo(
    () => uploads.filter((upload) => String(upload.projectId) === data.project_id),
    [uploads, data.project_id],
  )

  const uploadOptions = [
    { label: 'No linked drawing', value: '' },
    ...uploadsForProject.map((upload) => ({ label: upload.name, value: String(upload.id) })),
  ]

  const linkedEstimate = uploadsForProject.find(
    (upload) => String(upload.id) === data.upload_id,
  )?.estimate

  /** Changing the client invalidates the project picked under the old one, and its drawing. */
  const selectClient = (clientId: string) => {
    setData((current) => ({ ...current, client_id: clientId, project_id: '', upload_id: '' }))
    clearErrors('client_id', 'project_id', 'upload_id')
  }

  /** The project's own drawing, so the usual case takes no second choice. */
  const selectProject = (projectId: string) => {
    const project = projects.find((option) => String(option.id) === projectId)

    setData((current) => ({
      ...current,
      project_id: projectId,
      upload_id: project?.defaultUploadId ? String(project.defaultUploadId) : '',
    }))

    clearErrors('project_id', 'upload_id')
  }

  const clientName = clients.find((option) => String(option.id) === data.client_id)?.name

  return (
    <PageTransition>
      <Head title="Create Estimate" />

      <PageHeader
        title="Create Estimate"
        subtitle="Add a client-facing estimate to your workspace."
        breadcrumbs={[
          { label: 'Estimates', href: ROUTES.estimates },
          { label: 'Create' },
        ]}
        actions={
          <ButtonLink href={ROUTES.estimates} variant="secondary" leftIcon={ArrowLeft}>
            Back to estimates
          </ButtonLink>
        }
      />

      {hasErrors && (
        <Alert tone="danger" title="Check the form" className="mb-6">
          Some fields need attention before this estimate can be saved.
        </Alert>
      )}

      <form onSubmit={submit} noValidate>
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] xl:items-start">
          {/* ------------------------------------------------------ Form ---- */}
          <Card accent="brand" padding="lg">
            <SectionHeading
              title="Estimate details"
              subtitle={`Reference ${nextNumber} is reserved for this estimate.`}
            />

            <div className="space-y-5">
              <div className="grid gap-5 sm:grid-cols-2">
                <SelectField
                  id="estimate-client"
                  label="Client*"
                  hint="Not listed? Add them under Clients first."
                  options={clientOptions}
                  value={data.client_id}
                  onChange={(event) => selectClient(event.target.value)}
                  {...(errors.client_id ? { error: errors.client_id } : {})}
                />
                <SelectField
                  id="estimate-project"
                  label="Project*"
                  options={projectOptions}
                  value={data.project_id}
                  disabled={!data.client_id}
                  onChange={(event) => selectProject(event.target.value)}
                  {...(errors.project_id ? { error: errors.project_id } : {})}
                />
                <SelectField
                  id="estimate-linked-upload"
                  label="AI Takeoff drawing (optional)"
                  className="sm:col-span-2"
                  options={uploadOptions}
                  value={data.upload_id}
                  disabled={!data.project_id}
                  onChange={(event) => update('upload_id', event.target.value)}
                  {...(errors.upload_id ? { error: errors.upload_id } : {})}
                />
              </div>

              {linkedEstimate && (
                <Alert
                  tone="info"
                  icon={FileCheck2}
                  title="This drawing already has an estimate"
                >
                  <p>
                    Estimate {linkedEstimate.number} —{' '}
                    {formatCurrency(linkedEstimate.amount, 2)}. Edit that one instead of
                    creating a duplicate.
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

              <div className="grid gap-5 sm:grid-cols-2">
                <TextInput
                  id="estimate-date"
                  type="date"
                  label="Date"
                  value={data.issued_on}
                  onChange={(event) => update('issued_on', event.target.value)}
                  {...(errors.issued_on ? { error: errors.issued_on } : {})}
                />
                <SelectField
                  id="estimate-status"
                  label="Status"
                  options={ESTIMATE_STATUS_OPTIONS}
                  value={data.status}
                  onChange={(event) =>
                    update('status', event.target.value as EstimateDraft['status'])
                  }
                  {...(errors.status ? { error: errors.status } : {})}
                />
              </div>

              <TextInput
                id="estimate-amount"
                type="number"
                inputMode="decimal"
                min={0}
                step={50}
                label="Amount (USD)"
                placeholder="24850"
                hint="Excluding tax."
                value={data.amount}
                onChange={(event) => update('amount', event.target.value)}
                {...(errors.amount ? { error: errors.amount } : {})}
              />
            </div>

            <div className="mt-8 flex flex-wrap items-center gap-3 border-t border-hairline pt-6">
              {linkedEstimate ? (
                <ButtonLink href={linkedEstimate.editUrl} leftIcon={Save}>
                  Edit {linkedEstimate.number} instead
                </ButtonLink>
              ) : (
                <Button type="submit" leftIcon={Save} isLoading={processing}>
                  Create Estimate
                </Button>
              )}
              <ButtonLink href={ROUTES.estimates} variant="secondary">
                Cancel
              </ButtonLink>
            </div>
          </Card>

          {/* --------------------------------------------------- Summary ---- */}
          <Card accent="success" padding="lg" className="xl:sticky xl:top-24">
            <SectionHeading
              title="Summary"
              subtitle="Updates as you type — nothing is saved until you submit."
            />

            <dl className="space-y-4">
              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <FileText size={14} aria-hidden className="text-brand" />
                  Estimate #
                </dt>
                <dd className="mt-2 font-mono text-lg font-semibold text-white">
                  {nextNumber}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <Building2 size={14} aria-hidden className="text-brand" />
                  Client
                </dt>
                <dd className="mt-2 text-md text-white">
                  {clientName ?? <span className="text-white/60">Not set</span>}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <CalendarDays size={14} aria-hidden className="text-brand" />
                  Date
                </dt>
                <dd className="mt-2 text-md text-white">
                  {data.issued_on ? (
                    formatDate(data.issued_on)
                  ) : (
                    <span className="text-white/60">Not set</span>
                  )}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/70 uppercase">
                  <Wallet size={14} aria-hidden className="text-brand" />
                  Amount
                </dt>
                <dd className="mt-2 text-2xl font-bold tabular-nums text-white">
                  {hasAmount ? (
                    formatCurrency(amount, 2)
                  ) : (
                    <span className="text-lg font-medium text-white/60">Not set</span>
                  )}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="text-xs tracking-wide text-white/70 uppercase">Status</dt>
                <dd className="mt-2">
                  <StatusChip
                    tone={ESTIMATE_STATUS_TONE[data.status]}
                    label={ESTIMATE_STATUS_LABEL[data.status]}
                  />
                </dd>
              </div>
            </dl>
          </Card>
        </div>
      </form>
    </PageTransition>
  )
}

EstimateCreate.layout = appLayout
