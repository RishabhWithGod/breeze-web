import type { FormDataKeys, FormDataValues } from '@inertiajs/core'
import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Building2, CalendarDays, FileText, Save, Wallet } from 'lucide-react'
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
import type { EstimateDraft } from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

export interface EstimateCreateProps {
  /** Reference the server will assign on save. */
  nextNumber: string
  /** Existing clients, offered as a datalist so spelling stays consistent. */
  clients: readonly string[]
}

/**
 * Create Estimate — a full screen rather than a dialog, so the form has room
 * for a live summary beside it.
 *
 * Posts to StoreEstimateRequest and renders whatever it rejects, so the form
 * never restates the server's rules. On success Laravel redirects to the
 * estimates list with the new row already in place.
 */
export default function EstimateCreate({ nextNumber, clients }: EstimateCreateProps) {
  const { data, setData, post, processing, errors, hasErrors, clearErrors } =
    useForm<EstimateDraft>({
      client: '',
      project: '',
      issued_on: '',
      amount: '',
      status: 'draft',
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
    post(ROUTES.estimates, { preserveScroll: true })
  }

  const amount = Number(data.amount)
  const hasAmount = data.amount !== '' && Number.isFinite(amount)

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
          <Card padding="lg">
            <SectionHeading
              title="Estimate details"
              subtitle={`Reference ${nextNumber} is reserved for this estimate.`}
            />

            <div className="space-y-5">
              <div>
                <TextInput
                  id="estimate-client"
                  label="Client"
                  list="estimate-client-options"
                  placeholder="e.g. Westview Properties"
                  autoComplete="off"
                  value={data.client}
                  onChange={(event) => update('client', event.target.value)}
                  {...(errors.client ? { error: errors.client } : {})}
                />
                <datalist id="estimate-client-options">
                  {clients.map((client) => (
                    <option key={client} value={client} />
                  ))}
                </datalist>
              </div>

              <TextInput
                id="estimate-project"
                label="Project"
                placeholder="e.g. Office Building Renovation"
                value={data.project}
                onChange={(event) => update('project', event.target.value)}
                {...(errors.project ? { error: errors.project } : {})}
              />

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
              <Button type="submit" leftIcon={Save} isLoading={processing}>
                Create Estimate
              </Button>
              <ButtonLink href={ROUTES.estimates} variant="secondary">
                Cancel
              </ButtonLink>
            </div>
          </Card>

          {/* --------------------------------------------------- Summary ---- */}
          <Card padding="lg" className="xl:sticky xl:top-24">
            <SectionHeading
              title="Summary"
              subtitle="Updates as you type — nothing is saved until you submit."
            />

            <dl className="space-y-4">
              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/45 uppercase">
                  <FileText size={14} aria-hidden className="text-brand" />
                  Estimate #
                </dt>
                <dd className="mt-2 font-mono text-lg font-semibold text-white">
                  {nextNumber}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/45 uppercase">
                  <Building2 size={14} aria-hidden className="text-brand" />
                  Client
                </dt>
                <dd className="mt-2 text-md text-white">
                  {data.client.trim() || <span className="text-white/35">Not set</span>}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/45 uppercase">
                  <FileText size={14} aria-hidden className="text-brand" />
                  Project
                </dt>
                <dd className="mt-2 text-md text-white">
                  {data.project.trim() || <span className="text-white/35">Not set</span>}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/45 uppercase">
                  <CalendarDays size={14} aria-hidden className="text-brand" />
                  Date
                </dt>
                <dd className="mt-2 text-md text-white">
                  {data.issued_on ? (
                    formatDate(data.issued_on)
                  ) : (
                    <span className="text-white/35">Not set</span>
                  )}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="flex items-center gap-2 text-xs tracking-wide text-white/45 uppercase">
                  <Wallet size={14} aria-hidden className="text-brand" />
                  Amount
                </dt>
                <dd className="mt-2 text-2xl font-bold tabular-nums text-white">
                  {hasAmount ? (
                    formatCurrency(amount, 2)
                  ) : (
                    <span className="text-lg font-medium text-white/35">Not set</span>
                  )}
                </dd>
              </div>

              <div className="rounded-panel border border-hairline bg-white/4 p-4">
                <dt className="text-xs tracking-wide text-white/45 uppercase">Status</dt>
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
