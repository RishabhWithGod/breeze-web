import { Head, usePage } from '@inertiajs/react'
import {
  ArrowRight,
  Briefcase,
  PencilLine,
  Sparkles,
} from 'lucide-react'
import {
  Alert,
  Badge,
  ButtonLink,
  Card,
  SectionHeading,
  StatusChip,
  WorkflowProgress,
} from '@/components/common'
import { EstimateItemsTable } from '@/components/estimates'
import {
  EquipmentPanel,
  PanelSchedulesPanel,
  WireSizesPanel,
} from '@/components/finals'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import { ApprovalHistoryPanel } from '@/components/review'
import { ROUTES, routeTo } from '@/constants'
import type {
  ApprovalHistoryEntry,
  EquipmentRow,
  EstimateItemRow,
  EstimateStatus,
  EstimateTotals,
  PanelScheduleRow,
  SelectOption,
  SharedPageProps,
  WireSizeRow,
} from '@/types'
import {
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

interface EstimateSummary {
  readonly id: number
  readonly number: string
  readonly client: string
  readonly project: string
  readonly status: string
  readonly issuedOn: string | null
  readonly notes: string | null
  readonly jobId: number | null
  readonly jobName: string | null
  readonly projectId: number | null
  readonly aiResultId: number | null
  readonly fromTakeoff: boolean
  readonly createdAt: string
  /** The drawing these numbers came from, when there is one. */
  readonly drawingUrl: string | null
  readonly drawingName: string | null
  readonly editUrl: string
  /** False while the AI lines carry the engine's quantities, pre-review. */
  readonly reviewed: boolean
  readonly reviewUrl: string | null
}

export interface EstimateShowProps {
  estimate: EstimateSummary
  items: readonly EstimateItemRow[]
  sections: Record<string, { label: string; lines: number; total: number }>
  totals: EstimateTotals
  categories: readonly SelectOption[]
  statuses: readonly string[]
  /** Read off the same drawing but not priced by the engine. */
  drawingData: {
    wireSizes: readonly WireSizeRow[]
    equipment: readonly EquipmentRow[]
    panelSchedules: readonly PanelScheduleRow[]
  }
  history: readonly ApprovalHistoryEntry[]
}

/**
 * Estimate detail.
 *
 * Generated from a reviewed takeoff when there is one, but nothing here is
 * frozen: rates, quantities, markup and tax are all editable, and the totals are
 * recalculated from the line items on every save.
 */
export default function EstimateShow({
  estimate,
  items,
  totals,
  categories,
  drawingData,
  history,
}: EstimateShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  const totalRows: readonly { label: string; value: number; strong?: boolean }[] = [
    { label: 'Materials and fixtures', value: totals.material },
    { label: 'Labor', value: totals.labor },
    { label: 'Equipment', value: totals.equipment },
    { label: 'Subtotal', value: totals.subtotal, strong: true },
    { label: `Markup (${totals.markupPct}%)`, value: totals.markup },
    { label: `Tax (${totals.taxPct}%)`, value: totals.tax },
    { label: 'Grand total', value: totals.grandTotal, strong: true },
  ]

  return (
    <PageTransition>
      <Head title={`Estimate ${estimate.number}`} />

      <PageHeader
        title={`Estimate ${estimate.number}`}
        subtitle={`${estimate.client} · ${estimate.project}`}
        breadcrumbs={[
          { label: 'Estimates', href: ROUTES.estimates },
          { label: estimate.number },
        ]}
        actions={
          estimate.jobId ? (
            <ButtonLink
              href={routeTo.job(estimate.jobId)}
              variant="ghost"
              size="sm"
              leftIcon={Briefcase}
            >
              {estimate.jobName}
            </ButtonLink>
          ) : undefined
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

      {/* Where this takeoff is, carried over from the review summary screen so
          the roadmap travels with it instead of resetting between pages. */}
      {estimate.aiResultId && (
        <WorkflowProgress
          current={estimate.jobId ? 'schedule' : 'job'}
          done={['analysis', 'review', 'estimate', ...(estimate.jobId ? (['job'] as const) : [])]}
          className="mb-6"
        />
      )}

      {/* The next step after a takeoff's estimate — raising the job, back on
          the review summary screen — until one exists. */}
      {estimate.aiResultId && !estimate.jobId && (
        <Alert tone="info" className="mb-4" title="Estimate ready">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <span>Check the numbers above, then continue to create the job.</span>
            <ButtonLink
              href={routeTo.finalSymbols(estimate.aiResultId)}
              size="sm"
              className="ml-auto"
              rightIcon={ArrowRight}
            >
              Continue
            </ButtonLink>
          </div>
        </Alert>
      )}

      {/* Whose quantities these are, before anyone prices work off them. */}
      {!estimate.reviewed && estimate.reviewUrl && (
        <Alert tone="warning" className="mb-4" title="Priced from an unreviewed takeoff">
          These lines carry the AI&apos;s own quantities, so the estimate exists from
          the start. Signing off the review rewrites the AI lines at the reviewed
          counts — anything you add by hand is kept.
          <ButtonLink
            href={estimate.reviewUrl}
            size="sm"
            className="mt-3"
            leftIcon={Sparkles}
          >
            Review the symbols
          </ButtonLink>
        </Alert>
      )}

      <div className="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <div className="flex min-w-0 flex-col gap-6">
          <Card padding="lg">
            <SectionHeading
              as="h3"
              title="Line items"
              subtitle="Generated from the reviewed takeoff — edit anything"
              actions={
                estimate.fromTakeoff ? (
                  <Badge tone="brand">From AI takeoff</Badge>
                ) : (
                  <Badge tone="neutral">Manual estimate</Badge>
                )
              }
            />
            <EstimateItemsTable
              estimateId={estimate.id}
              items={items}
              categories={categories}
            />
          </Card>

          <Card padding="lg">
            <SectionHeading
              as="h3"
              title="Estimate details"
              subtitle="Client, status and the rates applied"
              actions={
                <ButtonLink
                  href={estimate.editUrl}
                  variant="secondary"
                  size="sm"
                  leftIcon={PencilLine}
                >
                  Edit details
                </ButtonLink>
              }
            />

            <dl className="grid gap-4 sm:grid-cols-2">
              {[
                { label: 'Client', value: estimate.client },
                { label: 'Project', value: estimate.project },
                {
                  label: 'Issued on',
                  value: estimate.issuedOn ? formatDate(estimate.issuedOn) : '—',
                },
                { label: 'Markup', value: `${totals.markupPct}%` },
                { label: 'Tax', value: `${totals.taxPct}%` },
                {
                  label: 'Drawing',
                  value: estimate.drawingName ?? 'Not from a drawing',
                },
              ].map((field) => (
                <div key={field.label} className="min-w-0">
                  <dt className="text-2xs tracking-wide text-white/80 uppercase">
                    {field.label}
                  </dt>
                  <dd className="mt-1 truncate text-md text-white" title={field.value}>
                    {field.value}
                  </dd>
                </div>
              ))}

              <div className="min-w-0">
                <dt className="text-2xs tracking-wide text-white/80 uppercase">Status</dt>
                <dd className="mt-1">
                  <StatusChip
                    tone={ESTIMATE_STATUS_TONE[estimate.status as EstimateStatus] ?? 'neutral'}
                    label={
                      ESTIMATE_STATUS_LABEL[estimate.status as EstimateStatus] ??
                      estimate.status
                    }
                  />
                </dd>
              </div>
            </dl>

            {estimate.notes && (
              <p className="mt-4 border-t border-hairline pt-4 text-sm text-white/90">
                {estimate.notes}
              </p>
            )}
          </Card>
        </div>

        <div className="flex min-w-0 flex-col gap-6">
          <Card padding="lg">
            <SectionHeading as="h3" title="Totals" subtitle="Recalculated from the lines" />

            <dl className="flex flex-col gap-2">
              {totalRows.map((row) => (
                <div
                  key={row.label}
                  className={
                    row.strong
                      ? 'flex items-center justify-between gap-3 border-t border-hairline pt-2'
                      : 'flex items-center justify-between gap-3'
                  }
                >
                  <dt
                    className={
                      row.strong ? 'text-md font-semibold text-white' : 'text-md text-white/90'
                    }
                  >
                    {row.label}
                  </dt>
                  <dd
                    className={
                      row.strong
                        ? 'text-lg font-bold text-white'
                        : 'text-md font-medium text-white/90'
                    }
                  >
                    {formatCurrency(row.value, 2)}
                  </dd>
                </div>
              ))}
            </dl>

            <div className="mt-4 flex flex-col gap-1 border-t border-hairline pt-4 text-sm text-white/85">
              <p>
                {totals.laborHours > 0 && <span>{totals.laborHours} labor hours · </span>}
                raised {formatDate(estimate.createdAt)}
              </p>
              {/* What was priced automatically before review, for comparison. */}
              {totals.engineGrandTotal > 0 && (
                <p>
                  {totals.engineLineCount} lines were priced automatically at{' '}
                  {formatCurrency(totals.engineGrandTotal, 2)} before review
                </p>
              )}
            </div>
          </Card>

          {history.length > 0 && (
            <Card padding="lg">
              <SectionHeading as="h3" title="Takeoff history" subtitle="Audit trail" />
              <ApprovalHistoryPanel entries={history} />
            </Card>
          )}
        </div>
      </div>

      {/*
        Measured off the drawing but not priced by the engine — wire runs are
        priced by length and schedules describe equipment rather than count it.
        Kept beside the estimate so an estimator can price them by hand.
      */}
      {(drawingData.wireSizes.length > 0 ||
        drawingData.equipment.length > 0 ||
        drawingData.panelSchedules.length > 0) && (
        <div className="mt-6 grid gap-6 xl:grid-cols-2">
          {drawingData.wireSizes.length > 0 && (
            <WireSizesPanel wireSizes={drawingData.wireSizes} />
          )}
          {drawingData.equipment.length > 0 && (
            <EquipmentPanel equipment={drawingData.equipment} />
          )}
          {drawingData.panelSchedules.length > 0 && (
            <PanelSchedulesPanel schedules={drawingData.panelSchedules} />
          )}
        </div>
      )}
    </PageTransition>
  )
}

EstimateShow.layout = appLayout
