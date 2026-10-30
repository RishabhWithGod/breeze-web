import { router } from '@inertiajs/react'
import { AlertTriangle, ChevronRight } from 'lucide-react'
import { Button, ButtonLink, Card, EmptyState, ProgressBar, SectionHeading } from '@/components/common'
import { ComparisonBarChart, DonutChart } from '@/components/jobCosting'
import { JOB_COSTING_OVERRUN_LABEL, ROUTES, routeTo } from '@/constants'
import type { AnalyticsTab, JobCostingDashboardProps, Tone } from '@/types'
import { JOB_STATUS_LABEL, formatCurrency, formatHours } from '@/utils'
import { AlertList } from './AlertList'
import { MonthlyTrendChart } from './MonthlyTrendChart'
import { deltaNote } from './deltaNote'
import { StatBlock } from './shared'

const STATUS_TONE_CYCLE: readonly Tone[] = ['brand', 'success', 'info', 'warning', 'danger', 'neutral']
const COST_TYPE_LABEL: Record<string, string> = {
  all: 'Actual Cost',
  total: 'Actual Cost',
  labor: 'Labor Cost',
  material: 'Material Cost',
  equipment: 'Equipment Cost',
  other: 'Other Cost',
}

export function OverviewTab({ props, onTab }: { props: JobCostingDashboardProps; onTab: (tab: AnalyticsTab) => void }) {
  const {
    filters, canViewCosts, laborTotals, materialTotals, profitLoss, billing, overBudgetCount, overrunAlerts,
    jobsByStatus, topProfitable, leastProfitable, jobCount, comparison, costBreakdown, selectedCost, trend, alerts, scope,
  } = props

  const costType = filters.cost_type
  const showLabor = costType === 'all' || costType === 'labor' || costType === 'total'
  const showMaterials = costType === 'all' || costType === 'material' || costType === 'total'

  const laborVarianceHours = laborTotals.actualHours - laborTotals.estimatedHours
  const laborVariancePct = laborTotals.estimatedHours > 0 ? (laborVarianceHours / laborTotals.estimatedHours) * 100 : null
  const materialVariance = canViewCosts && materialTotals.actualCost !== null && materialTotals.estimatedCost !== null ? materialTotals.actualCost - materialTotals.estimatedCost : null
  const materialVariancePct = materialVariance !== null && materialTotals.estimatedCost && materialTotals.estimatedCost > 0 ? (materialVariance / materialTotals.estimatedCost) * 100 : null

  const costDelta = comparison && canViewCosts && selectedCost !== null
    ? deltaNote(selectedCost, costType === 'labor' ? comparison.laborCost : comparison.totalCost)
    : {}
  const hoursDelta = comparison ? deltaNote(laborTotals.actualHours, comparison.laborHours) : {}

  return (
    <>
      {filters.team_member !== null && (
        <p className="mb-6 rounded-panel border border-status-warning/30 bg-status-warning/10 p-3 text-sm text-white/90">
          Filtering by team member narrows hours and labor cost to that person. Profit and margin still use the whole job, so read them as job-level figures.
        </p>
      )}

      {/* ================================================ KPI row ============ */}
      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {canViewCosts && profitLoss.revenue !== null && profitLoss.profit !== null && (
          <>
            <StatBlock label="Revenue" value={formatCurrency(profitLoss.revenue, 0)} detail="current estimate + approved change orders" />
            <StatBlock
              label="Scope Changes"
              value={`${(scope.change ?? 0) >= 0 ? '+' : ''}${formatCurrency(scope.change ?? 0, 0)}`}
              detail={`vs estimate at job creation (${formatCurrency(scope.baseline ?? 0, 0)})`}
              note={`${scope.finalJobs} final · ${scope.liveJobs} still live`}
              noteTone={(scope.change ?? 0) > 0 ? 'danger' : 'neutral'}
              onClick={() => onTab('materials')}
            />
            <StatBlock
              label={COST_TYPE_LABEL[costType] ?? 'Actual Cost'}
              value={formatCurrency(selectedCost ?? 0, 0)}
              {...costDelta}
            />
            <StatBlock
              label="Net Profit"
              value={formatCurrency(profitLoss.profit, 0)}
              note={profitLoss.marginPct !== null ? `${profitLoss.marginPct}% margin` : undefined}
              noteTone={profitLoss.profit >= 0 ? 'success' : 'danger'}
              onClick={() => onTab('profitability')}
            />
            <StatBlock label="Outstanding" value={formatCurrency(billing.outstanding ?? 0, 0)} detail={`${formatCurrency(billing.paid ?? 0, 0)} paid`} onClick={() => onTab('billing')} />
            <StatBlock label="Unbilled" value={formatCurrency(billing.unbilled ?? 0, 0)} detail={`${formatCurrency(billing.billed ?? 0, 0)} billed`} onClick={() => onTab('billing')} />
          </>
        )}
        <StatBlock label="Labor Hours" value={formatHours(laborTotals.actualHours)} detail={`of ${formatHours(laborTotals.estimatedHours)} estimated`} {...hoursDelta} onClick={() => onTab('labor')} />
        <StatBlock
          label="Over Budget"
          value={String(overBudgetCount)}
          detail={`of ${jobCount} job${jobCount === 1 ? '' : 's'}`}
          note={overBudgetCount > 0 ? 'Needs attention' : 'All within budget'}
          noteTone={overBudgetCount > 0 ? 'danger' : 'success'}
          onClick={() => onTab('profitability')}
        />
        <StatBlock
          label="Alerts"
          value={String(alerts.length)}
          detail="margin, hours, billing, timers"
          note={alerts.length > 0 ? 'Review now' : 'All clear'}
          noteTone={alerts.length > 0 ? 'danger' : 'success'}
          onClick={() => onTab('alerts')}
        />
      </div>

      {comparison && (
        <p className="-mt-3 mb-6 text-xs text-white/60">
          Compared with {comparison.from} – {comparison.to}. Only hours and cost are compared; revenue and estimates do not change with a date window.
        </p>
      )}

      {/* ================================================ Trend ============== */}
      {trend && trend.length > 0 && canViewCosts && (
        <Card accent="brand" padding="lg" className="mb-6">
          <SectionHeading as="h3" title="Monthly Billing, Collections & Cost" />
          <MonthlyTrendChart points={trend} />
          <p className="mt-3 text-xs text-white/60">Cost is labor from logged time at the project rate plus manually logged costs.</p>
        </Card>
      )}

      {/* ================================================ Labor + Materials == */}
      <div className="grid gap-6 xl:grid-cols-2">
        {showLabor && (
          <Card accent="brand" padding="lg">
            <SectionHeading as="h3" title="Estimated vs Actual Labor" />
            {canViewCosts && laborTotals.estimatedCost !== null && laborTotals.actualCost !== null ? (
              <>
                <ComparisonBarChart
                  rows={[
                    { label: 'Estimated', value: laborTotals.estimatedCost, barClassName: 'bg-brand-deep' },
                    { label: 'Actual', value: laborTotals.actualCost, barClassName: laborTotals.actualCost > laborTotals.estimatedCost ? 'bg-status-danger' : 'bg-status-success' },
                  ]}
                  formatValue={(v) => formatCurrency(v, 0)}
                />
                <div className="mt-6 grid gap-4 sm:grid-cols-2">
                  <StatBlock label="Total Estimated" value={formatHours(laborTotals.estimatedHours)} detail={formatCurrency(laborTotals.estimatedCost, 2)} />
                  <StatBlock
                    label="Total Actual"
                    value={formatHours(laborTotals.actualHours)}
                    detail={formatCurrency(laborTotals.actualCost, 2)}
                    note={laborVariancePct !== null ? `${laborVarianceHours >= 0 ? '+' : ''}${formatHours(laborVarianceHours)} · ${laborVarianceHours >= 0 ? '+' : ''}${laborVariancePct.toFixed(1)}%` : undefined}
                    noteTone={laborVarianceHours > 0 ? 'danger' : 'success'}
                  />
                </div>
              </>
            ) : (
              <>
                <EmptyState title="Cost figures are restricted" description="Only a Client Manager, Admin or Owner can see labor cost totals. Hours worked are not restricted." />
                <div className="mt-6 grid gap-4 sm:grid-cols-2">
                  <StatBlock label="Estimated Hours" value={formatHours(laborTotals.estimatedHours)} />
                  <StatBlock label="Actual Hours" value={formatHours(laborTotals.actualHours)} />
                </div>
              </>
            )}
          </Card>
        )}

        {showMaterials && (
          <Card accent="success" padding="lg">
            <SectionHeading as="h3" title="Materials: Estimated vs Actual" />
            {!canViewCosts ? (
              <EmptyState title="Cost figures are restricted" description="Only a Client Manager, Admin or Owner can see material cost totals." />
            ) : materialTotals.estimatedCost === 0 && materialTotals.actualCost === 0 ? (
              <EmptyState title="No material costing data yet" description="Log an estimate with material lines, or record an actual material cost on a job, to see this chart." />
            ) : (
              <>
                <ComparisonBarChart
                  rows={[
                    { label: 'Estimated (at job creation)', value: materialTotals.estimatedCost ?? 0, barClassName: 'bg-brand-deep' },
                    { label: 'Actual (final / current estimate)', value: materialTotals.actualCost ?? 0, barClassName: (materialTotals.actualCost ?? 0) > (materialTotals.estimatedCost ?? 0) ? 'bg-status-danger' : 'bg-status-success' },
                  ]}
                  formatValue={(v) => formatCurrency(v, 0)}
                />
                <div className="mt-6 grid gap-4 sm:grid-cols-2">
                  <StatBlock label="Total Estimated" value={formatCurrency(materialTotals.estimatedCost ?? 0, 2)} detail={`Across ${jobCount} jobs`} />
                  <StatBlock
                    label="Total Actual"
                    value={formatCurrency(materialTotals.actualCost ?? 0, 2)}
                    detail={`${scope.finalJobs} final · ${scope.liveJobs} live estimate`}
                    note={materialVariancePct !== null ? `${Math.abs(materialVariancePct).toFixed(1)}% ${materialVariancePct >= 0 ? 'over' : 'under'} budget` : undefined}
                    noteTone={(materialVariance ?? 0) > 0 ? 'danger' : 'success'}
                  />
                </div>
              </>
            )}
          </Card>
        )}
      </div>

      {/* ================================================ Breakdown + Profit == */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        {canViewCosts && costBreakdown.length > 0 && (
          <Card accent="info" padding="lg">
            <SectionHeading as="h3" title="Cost by Category" />
            <ul className="flex flex-col gap-4">
              {costBreakdown.map((category) => {
                const highlighted = costType === category.key
                const over = category.variance > 0
                const used = category.estimated > 0 ? Math.min(100, (category.actual / category.estimated) * 100) : category.actual > 0 ? 100 : 0
                return (
                  <li key={category.key} className={highlighted ? 'rounded-panel bg-white/8 p-3' : 'p-3'}>
                    <div className="flex items-center justify-between gap-3 text-sm">
                      <span className="font-semibold text-white">{category.label}</span>
                      <span className="text-white/85">
                        {formatCurrency(category.actual, 0)} <span className="text-white/60">/ {formatCurrency(category.estimated, 0)}</span>
                      </span>
                    </div>
                    <ProgressBar value={used} tone={over ? 'danger' : 'success'} size="sm" className="mt-2" />
                    <p className={over ? 'mt-1 text-xs font-medium text-status-danger' : 'mt-1 text-xs text-white/60'}>
                      {category.variancePct !== null ? `${category.variance >= 0 ? '+' : ''}${formatCurrency(category.variance, 0)} (${category.variancePct}%) vs estimate` : category.actual > 0 ? `${formatCurrency(category.actual, 0)} with no estimate` : 'No spend'}
                    </p>
                  </li>
                )
              })}
            </ul>
          </Card>
        )}

        <Card accent="warning" padding="lg">
          <SectionHeading as="h3" title="Profit/Loss" />
          {canViewCosts && profitLoss.revenue !== null && profitLoss.profit !== null ? (
            <>
              <ComparisonBarChart
                rows={[
                  { label: 'Total Revenue', value: Math.max(0, profitLoss.revenue), barClassName: 'bg-brand-deep' },
                  { label: 'Total Cost', value: Math.max(0, profitLoss.totalCost ?? 0), barClassName: 'bg-status-warning' },
                  { label: 'Net Profit', value: Math.max(0, profitLoss.profit), barClassName: profitLoss.profit >= 0 ? 'bg-status-success' : 'bg-status-danger' },
                ]}
                formatValue={(v) => formatCurrency(v, 0)}
              />
              <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <StatBlock label="Total Revenue" value={formatCurrency(profitLoss.revenue, 0)} />
                <StatBlock label="Net Profit" value={formatCurrency(profitLoss.profit, 0)} note={profitLoss.marginPct !== null ? `${profitLoss.marginPct}% margin` : undefined} noteTone={profitLoss.profit >= 0 ? 'success' : 'danger'} />
              </div>
            </>
          ) : (
            <EmptyState title="Cost and revenue figures are restricted" description="Only a Client Manager, Admin or Owner can see this job's financial totals." />
          )}
        </Card>
      </div>

      {/* ================================================ Alerts ============== */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card accent="neutral" padding="lg">
          <SectionHeading
            as="h3"
            title="Cost Overrun Alerts"
            actions={overrunAlerts.length > 0 ? <Button variant="secondary" size="sm" onClick={() => onTab('profitability')}>View All</Button> : undefined}
          />
          {overrunAlerts.length === 0 ? (
            <EmptyState title="No cost overruns" description="Every job in this view is within its estimated budget." />
          ) : (
            <ul className="flex flex-col gap-3">
              {overrunAlerts.map((row) => (
                <li key={row.jobId} className="flex items-start gap-3 rounded-panel border border-status-danger/30 bg-status-danger/10 p-4">
                  <AlertTriangle size={18} className="mt-0.5 shrink-0 text-status-warning" aria-hidden />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-semibold text-white">{row.jobName}</p>
                      <ButtonLink href={routeTo.jobCosting(row.jobId)} variant="ghost" size="sm" rightIcon={ChevronRight}>View Details</ButtonLink>
                    </div>
                    <p className="mt-1 text-sm text-white/85">
                      {JOB_COSTING_OVERRUN_LABEL[row.overrunReason ?? ''] ?? 'Exceeding budget'}
                      {row.overrunPct !== null && ` by ${row.overrunPct}%`}
                    </p>
                    {canViewCosts && row.overrunAmount !== null && <p className="mt-0.5 text-sm font-medium text-status-danger">{formatCurrency(row.overrunAmount, 2)} over budget</p>}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card accent="warning" padding="lg">
          <SectionHeading as="h3" title="Needs Attention" actions={alerts.length > 5 ? <Button variant="secondary" size="sm" onClick={() => onTab('alerts')}>View All ({alerts.length})</Button> : undefined} />
          {alerts.length === 0 ? <EmptyState title="All clear" description="No margin, hours, billing or timer issues right now." /> : <AlertList alerts={alerts.slice(0, 5)} />}
        </Card>
      </div>

      {/* ================================================ Status + Rankings === */}
      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <Card accent="brand" padding="lg">
          <SectionHeading as="h3" title="Jobs by Status" />
          {jobsByStatus.length === 0 ? (
            <EmptyState title="No jobs in this view" description="Adjust the filters to see jobs by status." />
          ) : (
            <>
              <DonutChart segments={jobsByStatus.map((row, index) => ({ label: JOB_STATUS_LABEL[row.status as keyof typeof JOB_STATUS_LABEL] ?? row.status, value: row.count, tone: STATUS_TONE_CYCLE[index % STATUS_TONE_CYCLE.length] ?? 'neutral' }))} />
              <ul className="mt-5 flex flex-col gap-2">
                {jobsByStatus.map((row) => (
                  <li key={row.status}>
                    <button
                      type="button"
                      onClick={() => router.get(ROUTES.jobs, { status: row.status }, { preserveScroll: true })}
                      className="flex w-full items-center justify-between rounded-panel px-2 py-1.5 text-left text-md text-white/90 transition-colors hover:bg-white/8 hover:text-white"
                    >
                      <span>{JOB_STATUS_LABEL[row.status as keyof typeof JOB_STATUS_LABEL] ?? row.status}</span>
                      <span className="font-semibold tabular-nums text-white">{row.count}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </>
          )}
        </Card>

        <RankingCard title="Top Profitable Jobs" accent="success" tone="success" rows={topProfitable} canViewCosts={canViewCosts} />
        <RankingCard title="Least Profitable Jobs" accent="warning" tone="danger" rows={leastProfitable} canViewCosts={canViewCosts} />
      </div>
    </>
  )
}

function RankingCard({
  title,
  accent,
  tone,
  rows,
  canViewCosts,
}: {
  title: string
  accent: 'success' | 'warning'
  tone: 'success' | 'danger'
  rows: JobCostingDashboardProps['topProfitable']
  canViewCosts: boolean
}) {
  return (
    <Card accent={accent} padding="lg">
      <SectionHeading as="h3" title={title} />
      {rows.length === 0 ? (
        <EmptyState title="No costed jobs yet" description="Jobs with revenue and cost data will rank here." />
      ) : (
        <ul className="flex flex-col gap-4">
          {rows.map((row) => {
            const showFinancials = canViewCosts && row.revenue !== null && row.profit !== null
            const progress = showFinancials && row.revenue! > 0 ? Math.min(100, Math.max(0, (row.profit! / row.revenue!) * 100)) : 0
            return (
              <li key={row.jobId}>
                <ButtonLink href={routeTo.jobCosting(row.jobId)} variant="ghost" className="block w-full p-0 text-left hover:bg-transparent">
                  <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate font-semibold text-white">{row.jobName}</p>
                      <p className="truncate text-sm text-white/70">{row.client ?? 'No client on record'}</p>
                    </div>
                    {canViewCosts && <span className={tone === 'success' ? 'shrink-0 font-semibold text-status-success' : 'shrink-0 font-semibold text-status-danger'}>{row.marginPct !== null ? `${row.marginPct}%` : '—'}</span>}
                  </div>
                  {showFinancials && (
                    <div className="mt-2 flex items-center gap-3">
                      <ProgressBar value={Math.abs(progress)} tone={tone} size="sm" className="flex-1" />
                      <span className="shrink-0 text-sm font-medium text-white/90">{formatCurrency(row.profit!, 0)}</span>
                    </div>
                  )}
                </ButtonLink>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
