import { useState, type ReactNode } from 'react'
import { Head, usePage } from '@inertiajs/react'
import {
  ArrowLeft,
  Boxes,
  Cable,
  ChevronsDownUp,
  ChevronsUpDown,
  LayoutGrid,
  FileText,
  ListChecks,
  PencilLine,
  Sparkles,
  Wallet,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  CollapsibleCard,
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
  Tone,
  WireSizeRow,
} from '@/types'
import {
  cn,
  ESTIMATE_STATUS_LABEL,
  ESTIMATE_STATUS_TONE,
  formatCurrency,
  formatDate,
} from '@/utils'

/** One of the reference tables read off the drawing, as its own section. */
interface DrawingSection {
  readonly key: string
  readonly title: string
  readonly subtitle: string
  readonly icon: LucideIcon
  readonly tone: Tone
  readonly count: number
  readonly node: ReactNode
}

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
  /**
   * True only when the takeoff flow itself handed over to this screen. Opened
   * from a job or the estimates list it is a record to read, not a step.
   */
  inFlow: boolean
  /** Where Back goes — the screen this one was actually reached from. */
  backUrl: string
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
  inFlow,
  backUrl,
  totals,
  categories,
  drawingData,
}: EstimateShowProps) {
  const { flash } = usePage<SharedPageProps>().props

  /**
   * Only the drawing panels that actually have rows. Each is its own section:
   * wire is priced by length and schedules describe equipment rather than
   * count it, so they are reference beside the lines, not part of them.
   */
  const drawingPanels: readonly DrawingSection[] = [
    drawingData.wireSizes.length > 0 && {
      key: 'wire-sizes',
      title: 'Wire sizes',
      subtitle: 'Conductor sizes read off the drawing — priced by length',
      icon: Cable,
      tone: 'warning' as const,
      count: drawingData.wireSizes.length,
      node: <WireSizesPanel wireSizes={drawingData.wireSizes} bare />,
    },
    drawingData.equipment.length > 0 && {
      key: 'equipment',
      title: 'Equipment',
      subtitle: 'Tagged equipment the engine read off the drawing',
      icon: Boxes,
      tone: 'warning' as const,
      count: drawingData.equipment.length,
      node: <EquipmentPanel equipment={drawingData.equipment} bare />,
    },
    drawingData.panelSchedules.length > 0 && {
      key: 'panel-schedules',
      title: 'Panel schedules',
      subtitle: 'Schedule tables extracted from the sheet',
      icon: LayoutGrid,
      tone: 'info' as const,
      count: drawingData.panelSchedules.length,
      node: <PanelSchedulesPanel schedules={drawingData.panelSchedules} bare />,
    },
  ].filter((panel) => panel !== false)

  /*
   * Every section starts closed. The screen is five or six tables stacked down
   * a page, and reading any one of them meant scrolling past the rest — each
   * header says what it holds, so the page is a contents list you open.
   */
  const [open, setOpen] = useState<Readonly<Record<string, boolean>>>({})

  const sectionKeys = ['lines', 'details', ...drawingPanels.map((p) => p.key), 'totals']
  const allOpen = sectionKeys.every((key) => open[key])

  const toggle = (key: string) =>
    setOpen((current) => ({ ...current, [key]: !current[key] }))

  const setAll = (isOpen: boolean) =>
    setOpen(Object.fromEntries(sectionKeys.map((key) => [key, isOpen])))

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
            {/*
              Back is top-right on every screen, and goes to the one this was
              reached from — the job whose list it was opened from, the review
              in the flow, or the estimates index. Worked out server-side, where
              the claim can be checked.
            */}
            <ButtonLink
              href={backUrl}
              variant="secondary"
              size="sm"
              leftIcon={ArrowLeft}
            >
              Back
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

      {/* Where this takeoff is. The marker sits on the stage this screen *is*,
          never on the next one — a page you are reading is not finished work,
          and ticking it while pointing further along reads as both at once. */}
      {inFlow && estimate.aiResultId && (
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

      {/* One control for the lot, so a whole estimate can be read at once. */}
      <div className="mb-4 flex justify-end">
        <Button
          variant="secondary"
          size="sm"
          leftIcon={allOpen ? ChevronsDownUp : ChevronsUpDown}
          onClick={() => setAll(!allOpen)}
        >
          {allOpen ? 'Collapse all' : 'Expand all'}
        </Button>
      </div>

      <div className="flex min-w-0 flex-col gap-4">
        <CollapsibleCard
          title="Line items"
          subtitle="What is being priced, and at what rate"
          icon={ListChecks}
          tone="brand"
          summary={
            <span className="flex items-center gap-3">
              <span className="tabular-nums">
                {items.length} {items.length === 1 ? 'line' : 'lines'}
              </span>
              {estimate.fromTakeoff ? (
                <Badge tone="brand" size="sm">
                  From AI takeoff
                </Badge>
              ) : (
                <Badge tone="neutral" size="sm">
                  Manual
                </Badge>
              )}
            </span>
          }
          isOpen={Boolean(open['lines'])}
          onToggle={() => toggle('lines')}
        >
          <EstimateItemsTable
            estimateId={estimate.id}
            items={items}
            categories={categories}
          />
        </CollapsibleCard>

        <CollapsibleCard
          title="Estimate details"
          subtitle="The dates and rates these numbers were built on"
          icon={FileText}
          tone="info"
          summary={
            <span className="tabular-nums">
              {totals.markupPct}% markup · {totals.taxPct}% tax
            </span>
          }
          isOpen={Boolean(open['details'])}
          onToggle={() => toggle('details')}
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
        >
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
        </CollapsibleCard>

        {/*
          Measured off the drawing but not priced by the engine — wire runs are
          priced by length and schedules describe equipment rather than count it.
          Kept beside the estimate so an estimator can price them by hand.
        */}
        {drawingPanels.map((panel) => (
          <CollapsibleCard
            key={panel.key}
            title={panel.title}
            subtitle={panel.subtitle}
            icon={panel.icon}
            tone={panel.tone}
            summary={<span className="tabular-nums">{panel.count}</span>}
            isOpen={Boolean(open[panel.key])}
            onToggle={() => toggle(panel.key)}
          >
            {panel.node}
          </CollapsibleCard>
        ))}

        <CollapsibleCard
          title="Totals"
          subtitle="Recalculated from the lines above on every save"
          icon={Wallet}
          tone="success"
          summary={
            <span className="font-semibold tabular-nums text-white">
              {formatCurrency(totals.grandTotal, 2)}
            </span>
          }
          isOpen={Boolean(open['totals'])}
          onToggle={() => toggle('totals')}
        >
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
        </CollapsibleCard>
      </div>

      {/*
        Forward is the step after this one, which is the job step — the takeoff's
        own summary, where the job is raised and, once it exists, named. It used
        to jump straight to the job's detail screen whenever the estimate was
        linked to one, which skipped the step and dropped out of the flow. An
        estimate's `job_id` is not the same question either: the takeoff's
        estimate is linked to whichever job was raised first.
      */}
      {inFlow && estimate.aiResultId && (
        <StepFooter
          current="estimate"
          href={routeTo.finalSymbols(estimate.aiResultId)}
        />
      )}
    </PageTransition>
  )
}

EstimateShow.layout = appLayout
