import { Head, useForm } from '@inertiajs/react'
import { ArrowLeft, Save } from 'lucide-react'
import {
  Badge,
  Button,
  ButtonLink,
  Card,
  SectionHeading,
  SelectField,
  TextArea,
  TextInput,
} from '@/components/common'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type { ClientOption, EstimateTotals, SelectOption } from '@/types'
import { ESTIMATE_STATUS_LABEL, formatCurrency } from '@/utils'

interface EditableEstimate {
  readonly id: number
  readonly number: string
  readonly client: string
  /** The client's own id — clients are projects, so this is a `projects` id. */
  readonly projectId: number | null
  readonly status: string
  readonly issuedOn: string | null
  readonly markupPct: number
  readonly taxPct: number
  readonly notes: string | null
  readonly jobId: number | null
  readonly jobName: string | null
  readonly fromTakeoff: boolean
  /** The drawing the numbers came from, when there is one. */
  readonly drawingUrl: string | null
}

export interface EstimateEditProps {
  estimate: EditableEstimate
  statuses: readonly string[]
  /** Where Back goes — the estimate, carrying whatever brought us to it. */
  backUrl: string
  /** Where the form saves to. Carries the origin so it survives the save. */
  saveUrl: string
  totals: EstimateTotals
  /** The client register. Clients are projects, so this is one list, not two. */
  clients: readonly ClientOption[]
}

/**
 * Edit an estimate's header: client, status, issue date, markup and tax.
 *
 * A full screen rather than an inline panel, matching how a job is edited — the
 * detail screen stays a readable record and a change is a deliberate step. Line
 * items stay on the detail screen, where they are edited in place against their
 * totals.
 *
 * Markup and tax are shown beside the current figures so the effect of changing a
 * rate is visible before saving; the totals themselves are always recomputed
 * server-side from the lines.
 */
export default function EstimateEdit({
  estimate,
  statuses,
  backUrl,
  saveUrl,
  totals,
  clients,
}: EstimateEditProps) {
  const form = useForm({
    project_id: estimate.projectId === null ? '' : String(estimate.projectId),
    status: estimate.status,
    issued_on: estimate.issuedOn ?? '',
    markup_pct: String(estimate.markupPct),
    tax_pct: String(estimate.taxPct),
    notes: estimate.notes ?? '',
  })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    form.put(saveUrl)
  }

  /**
   * An estimate raised before clients and projects were merged may name a client
   * that never became a record. Its stored name becomes the placeholder, so the
   * select shows who the estimate is for rather than a blank "Select client" —
   * but the placeholder has no value, so saving still requires a real one.
   */
  const isUnlinked = estimate.projectId === null

  const clientOptions: readonly SelectOption[] = [
    isUnlinked
      ? { value: '', label: `${estimate.client} — not yet a client record` }
      : { value: '', label: 'Select client' },
    ...clients.map((client) => ({ value: String(client.id), label: client.name })),
  ]

  /** Preview of the effect of the rates being typed, before saving. */
  const markup = (Number(form.data.markup_pct) || 0) / 100
  const tax = (Number(form.data.tax_pct) || 0) / 100
  const previewMarkup = totals.subtotal * markup
  const previewTax = (totals.subtotal + previewMarkup) * tax
  const previewTotal = totals.subtotal + previewMarkup + previewTax

  return (
    <PageTransition>
      <Head title={`Edit estimate ${estimate.number}`} />

      <PageHeader
        title={`Edit estimate ${estimate.number}`}
        subtitle="Client, status, issue date and the rates applied to the line items."
        breadcrumbs={[
          { label: 'Estimates', href: ROUTES.estimates },
          { label: estimate.number, href: routeTo.estimate(estimate.id) },
          { label: 'Edit' },
        ]}
        actions={
          <ButtonLink href={backUrl} variant="secondary" size="sm" leftIcon={ArrowLeft}>
            Back
          </ButtonLink>
        }
      />

      <form onSubmit={submit} className="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <Card padding="lg" className="min-w-0">
          <SectionHeading
            as="h3"
            title="Estimate details"
            subtitle="Everything except the line items"
            actions={
              estimate.fromTakeoff ? (
                <Badge tone="brand">From AI takeoff</Badge>
              ) : (
                <Badge tone="neutral">Manual estimate</Badge>
              )
            }
          />

          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField
              id="estimate-client"
              label="Client"
              className="sm:col-span-2"
              hint="Not listed? Add them under Clients first."
              options={clientOptions}
              value={form.data.project_id}
              onChange={(event) => form.setData('project_id', event.target.value)}
              {...(form.errors.project_id ? { error: form.errors.project_id } : {})}
            />
            <SelectField
              id="estimate-status"
              label="Status"
              options={statuses.map((status) => ({
                value: status,
                label:
                  ESTIMATE_STATUS_LABEL[status as keyof typeof ESTIMATE_STATUS_LABEL] ??
                  status,
              }))}
              value={form.data.status}
              onChange={(event) => form.setData('status', event.target.value)}
            />
            <TextInput
              id="estimate-issued"
              label="Issued on"
              type="date"
              value={form.data.issued_on}
              onChange={(event) => form.setData('issued_on', event.target.value)}
              {...(form.errors.issued_on ? { error: form.errors.issued_on } : {})}
            />
            <TextInput
              id="estimate-markup"
              label="Markup %"
              type="number"
              step="0.01"
              min={0}
              hint="Applied to the subtotal."
              value={form.data.markup_pct}
              onChange={(event) => form.setData('markup_pct', event.target.value)}
              {...(form.errors.markup_pct ? { error: form.errors.markup_pct } : {})}
            />
            <TextInput
              id="estimate-tax"
              label="Tax %"
              type="number"
              step="0.01"
              min={0}
              hint="Applied after markup."
              value={form.data.tax_pct}
              onChange={(event) => form.setData('tax_pct', event.target.value)}
              {...(form.errors.tax_pct ? { error: form.errors.tax_pct } : {})}
            />
            <TextArea
              id="estimate-notes"
              label="Notes"
              rows={4}
              className="sm:col-span-2"
              value={form.data.notes}
              onChange={(event) => form.setData('notes', event.target.value)}
              {...(form.errors.notes ? { error: form.errors.notes } : {})}
            />
          </div>

          <div className="mt-6 flex flex-wrap gap-3 border-t border-hairline pt-6">
            <Button type="submit" leftIcon={Save} isLoading={form.processing}>
              Save changes
            </Button>
            <ButtonLink href={backUrl} variant="secondary">
              Cancel
            </ButtonLink>
          </div>
        </Card>

        <Card padding="lg" className="min-w-0">
          <SectionHeading
            as="h3"
            title="Effect of these rates"
            subtitle="Recalculated from the line items on save"
          />

          <dl className="flex flex-col gap-2">
            {[
              { label: 'Subtotal (from the lines)', value: totals.subtotal },
              { label: `Markup (${form.data.markup_pct || 0}%)`, value: previewMarkup },
              { label: `Tax (${form.data.tax_pct || 0}%)`, value: previewTax },
            ].map((row) => (
              <div key={row.label} className="flex items-center justify-between gap-3">
                <dt className="text-md text-white/90">{row.label}</dt>
                <dd className="text-md font-medium text-white/90">
                  {formatCurrency(row.value, 2)}
                </dd>
              </div>
            ))}

            <div className="flex items-center justify-between gap-3 border-t border-hairline pt-2">
              <dt className="text-md font-semibold text-white">Grand total</dt>
              <dd className="text-lg font-bold text-white">
                {formatCurrency(previewTotal, 2)}
              </dd>
            </div>
          </dl>

          {Math.abs(previewTotal - totals.grandTotal) > 0.005 && (
            <p className="mt-3 text-sm text-status-warning">
              Currently {formatCurrency(totals.grandTotal, 2)} — saving changes it to{' '}
              {formatCurrency(previewTotal, 2)}.
            </p>
          )}

          {totals.engineGrandTotal > 0 && (
            <p className="mt-4 border-t border-hairline pt-4 text-sm text-white/80">
              {totals.engineLineCount} lines were priced automatically at{' '}
              {formatCurrency(totals.engineGrandTotal, 2)} before review.
            </p>
          )}
        </Card>
      </form>
    </PageTransition>
  )
}

EstimateEdit.layout = appLayout
