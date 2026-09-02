import { Head, usePage } from '@inertiajs/react'
import { Briefcase, PencilLine, Sparkles } from 'lucide-react'
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
import { appLayout, PageHeader, PageTransition, StepFooter } from '@/components/layout'
import { ROUTES, routeTo } from '@/constants'
import type {
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
  cn,
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

interface EstimateSummary {
  readonly id: number
  readonly number: string
  readonly client: string
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
}: EstimateShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  /** Only the drawing panels that actually have rows — see the layout below. */
  const drawingPanels: readonly { key: string; node: React.ReactNode }[] = [
    drawingData.wireSizes.length > 0 && {
      key: 'wire-sizes',
      node: <WireSizesPanel wireSizes={drawingData.wireSizes} />,
    },
    drawingData.equipment.length > 0 && {
      key: 'equipment',
      node: <EquipmentPanel equipment={drawingData.equipment} />,
    },
    drawingData.panelSchedules.length > 0 && {
      key: 'panel-schedules',
      node: <PanelSchedulesPanel schedules={drawingData.panelSchedules} />,
    },
  ].filter((panel) => panel !== false)

  const breakdown: readonly { label: string; value: number; strong?: boolean }[] = [
    { label: 'Materials and fixtures', value: totals.material },
    { label: 'Labor', value: totals.labor },
    { label: 'Equipment', value: totals.equipment },
    { label: 'Subtotal', value: totals.subtotal, strong: true },
    { label: `Markup (${totals.markupPct}%)`, value: totals.markup },
    { label: `Tax (${totals.taxPct}%)`, value: totals.tax },
  ]

  return (
    <PageTransition>
      <Head title={`Estimate ${estimate.number}`} />

      {/* Status sits in the header, where this app puts it on every other
          detail screen — visible without reading down the page for it. */}
      <PageHeader
        title={`Estimate ${estimate.number}`}
        subtitle={estimate.client}
        breadcrumbs={[
          { label: 'Estimates', href: ROUTES.estimates },
          { label: estimate.number },
        ]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <StatusChip
              tone={ESTIMATE_STATUS_TONE[estimate.status as EstimateStatus] ?? 'neutral'}
              label={
                ESTIMATE_STATUS_LABEL[estimate.status as EstimateStatus] ?? estimate.status
              }
            />
            {estimate.jobId && (
              <ButtonLink
                href={routeTo.job(estimate.jobId)}
                variant="ghost"
                size="sm"
                leftIcon={Briefcase}
              >
                {estimate.jobName}
              </ButtonLink>
            )}
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

      {/* Where this takeoff is. The marker sits on the stage this screen *is*,
          never on the next one — a page you are reading is not finished work,
          and ticking it while pointing further along reads as both at once. */}
      {estimate.aiResultId && (
        <WorkflowProgress
          current="estimate"
          done={['analysis', 'review', ...(estimate.jobId ? (['job'] as const) : [])]}
          className="mb-6"
        />
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
            subtitle="The dates and rates these numbers were built on"
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

          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
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
              <div
                key={field.label}
                className="min-w-0 rounded-panel border border-hairline bg-white/4 p-4"
              >
                <dt className="text-2xs tracking-wide text-white/80 uppercase">
                  {field.label}
                </dt>
                <dd className="mt-1 truncate text-md text-white" title={field.value}>
                  {field.value}
                </dd>
              </div>
            ))}
          </dl>

          {estimate.notes && (
            <p className="mt-4 border-t border-hairline pt-4 text-sm text-white/90">
              {estimate.notes}
            </p>
          )}
        </Card>

        {/*
          Measured off the drawing but not priced by the engine — wire runs are
          priced by length and schedules describe equipment rather than count it.
          Kept beside the estimate so an estimator can price them by hand.
        */}
        {drawingPanels.length > 0 && (
          <div
            className={cn(
              'grid gap-6',
              // Only pair them up when there is a pair. One panel sitting in half
              // the width with nothing beside it just wastes the other half.
              drawingPanels.length > 1 && 'xl:grid-cols-2',
            )}
          >
            {drawingPanels.map((panel, index) => (
              <div
                key={panel.key}
                className={cn(
                  'min-w-0',
                  // An odd panel last in a two-column grid would be alone on its
                  // row, so it takes the whole row instead.
                  drawingPanels.length % 2 === 1 &&
                    index === drawingPanels.length - 1 &&
                    'xl:col-span-2',
                )}
              >
                {panel.node}
              </div>
            ))}
          </div>
        )}

        {/* ------------------------------------------------------ Totals --- */}
        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Totals"
            subtitle="Recalculated from the lines above on every save"
          />

          <div className="grid gap-6 lg:grid-cols-[1fr_auto] lg:items-start">
            <dl className="flex flex-col gap-2.5">
              {breakdown.map((row) => (
                <div
                  key={row.label}
                  className={cn(
                    'flex items-center justify-between gap-3',
                    row.strong && 'border-t border-hairline pt-2.5',
                  )}
                >
                  <dt
                    className={
                      row.strong ? 'text-md font-semibold text-white' : 'text-md text-white/90'
                    }
                  >
                    {row.label}
                  </dt>
                  <dd
                    className={cn(
                      'tabular-nums',
                      row.strong
                        ? 'text-md font-semibold text-white'
                        : 'text-md font-medium text-white/90',
                    )}
                  >
                    {formatCurrency(row.value, 2)}
                  </dd>
                </div>
              ))}
            </dl>

            {/* The one number anyone came for, given its own weight. */}
            <div className="rounded-panel border border-brand/40 bg-brand/8 p-5 lg:min-w-64">
              <p className="text-2xs tracking-wide text-white/80 uppercase">Grand total</p>
              <p className="mt-1 text-3xl font-bold tabular-nums text-white">
                {formatCurrency(totals.grandTotal, 2)}
              </p>

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
            </div>
          </div>
        </Card>
      </div>

      {/*
        Always drawn, so there is always a way back. What it continues to
        depends on where the estimate has got to: raise the job, open the job it
        already has, or — on a manual estimate with no takeoff behind it —
        nothing, and the footer is only the way back.
      */}
      <StepFooter
        current="estimate"
        showBack
        {...(estimate.jobId
          ? { href: routeTo.job(estimate.jobId), continueLabel: 'Continue to the job' }
          : estimate.aiResultId
            ? {
                href: routeTo.finalSymbols(estimate.aiResultId),
                continueLabel: 'Continue to create the job',
              }
            : {})}
      />
    </PageTransition>
  )
}

EstimateShow.layout = appLayout
