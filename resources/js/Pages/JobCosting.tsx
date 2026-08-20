import { useCallback, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { AnimatePresence, motion } from 'framer-motion'
import {
  AlertTriangle,
  ChevronRight,
  Download,
  SlidersHorizontal,
} from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
  EmptyState,
  ProgressBar,
  SectionHeading,
  SelectField,
  TextInput,
} from '@/components/common'
import { ComparisonBarChart, DonutChart, TrendLineChart } from '@/components/jobCosting'
import { appLayout, PageHeader, PageTransition } from '@/components/layout'
import {
  JOB_COSTING_COST_TYPE_FILTERS,
  JOB_COSTING_OVERRUN_LABEL,
  JOB_STATUS_FILTERS,
  MOTION,
  ROUTES,
  routeTo,
} from '@/constants'
import { useDisclosure } from '@/hooks'
import type { JobCostingDashboardProps, Tone } from '@/types'
import { JOB_STATUS_LABEL, formatCurrency, formatHours } from '@/utils'

const STATUS_TONE_CYCLE: readonly Tone[] = ['brand', 'success', 'info', 'warning', 'danger', 'neutral']

function statusTone(index: number): Tone {
  return STATUS_TONE_CYCLE[index % STATUS_TONE_CYCLE.length] ?? 'neutral'
}

export default function JobCosting({
  filters,
  canViewCosts,
  laborTotals,
  materialTotals,
  profitLoss,
  overrunAlerts,
  jobsByStatus,
  topProfitable,
  leastProfitable,
  jobCount,
  clients,
  jobs,
  teamMembers,
}: JobCostingDashboardProps) {
  const [draft, setDraft] = useState(filters)
  const filterBar = useDisclosure(false)

  const applyFilters = useCallback((changes: Partial<typeof filters>) => {
    const params = new URLSearchParams()
    const merged = { ...filters, ...changes }

    for (const [key, value] of Object.entries(merged)) {
      if (value === '' || value === 'all' || value === null || value === undefined) continue
      params.set(key, String(value))
    }

    const queryString = params.toString()
    router.get(queryString ? `${ROUTES.jobCosting}?${queryString}` : ROUTES.jobCosting, {}, { preserveScroll: true })
  }, [filters])

  const resetFilters = useCallback(() => {
    setDraft({ ...filters, job: null, client: 'all', status: 'all', cost_type: 'all', team_member: null })
    router.get(ROUTES.jobCosting, {}, { preserveScroll: true })
  }, [filters])

  const laborRows = [
    { label: 'Estimated', value: laborTotals.estimatedCost, barClassName: 'bg-brand-deep' },
    { label: 'Actual', value: laborTotals.actualCost, barClassName: laborTotals.actualCost > laborTotals.estimatedCost ? 'bg-status-danger' : 'bg-status-success' },
  ]

  const laborVarianceHours = laborTotals.actualHours - laborTotals.estimatedHours
  const laborVariancePct = laborTotals.estimatedHours > 0 ? (laborVarianceHours / laborTotals.estimatedHours) * 100 : null

  const materialVariance = materialTotals.actualCost - materialTotals.estimatedCost
  const materialVariancePct = materialTotals.estimatedCost > 0 ? (materialVariance / materialTotals.estimatedCost) * 100 : null

  return (
    <PageTransition>
      <Head title="Job Costing Dashboard" />

      <PageHeader
        title="Job Costing Dashboard"
        subtitle="Track financial performance across all active jobs"
        breadcrumbs={[{ label: 'Job Costing' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="secondary"
              leftIcon={SlidersHorizontal}
              aria-expanded={filterBar.isOpen}
              onClick={filterBar.toggle}
            >
              Filter
            </Button>
            <span className="inline-flex items-center rounded-panel border border-hairline-strong bg-white/10 px-4 py-2 text-sm text-white/90">
              {filters.date_from} – {filters.date_to}
            </span>
            <Button leftIcon={Download} onClick={() => window.open(routeTo.jobCostingExport('csv'), '_blank')}>
              Export Report
            </Button>
          </div>
        }
      />

      <AnimatePresence initial={false}>
        {filterBar.isOpen && (
          <motion.div
            key="filters"
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            transition={{ duration: MOTION.base }}
            className="mb-6 overflow-hidden rounded-card border border-hairline glass p-5 shadow-panel sm:p-6"
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <TextInput
                id="jc-date-from"
                type="date"
                label="Date From"
                value={draft.date_from}
                onChange={(event) => setDraft({ ...draft, date_from: event.target.value })}
              />
              <TextInput
                id="jc-date-to"
                type="date"
                label="Date To"
                value={draft.date_to}
                onChange={(event) => setDraft({ ...draft, date_to: event.target.value })}
              />
              <SelectField
                id="jc-job"
                label="Job"
                options={[{ label: 'All Jobs', value: 'all' }, ...jobs.map((job) => ({ label: job.name, value: String(job.id) }))]}
                value={draft.job !== null ? String(draft.job) : 'all'}
                onChange={(event) => setDraft({ ...draft, job: event.target.value === 'all' ? null : Number(event.target.value) })}
              />
              <SelectField
                id="jc-client"
                label="Client"
                options={[{ label: 'All Clients', value: 'all' }, ...clients.map((client) => ({ label: client, value: client }))]}
                value={draft.client}
                onChange={(event) => setDraft({ ...draft, client: event.target.value })}
              />
              <SelectField
                id="jc-status"
                label="Status"
                options={JOB_STATUS_FILTERS}
                value={draft.status}
                onChange={(event) => setDraft({ ...draft, status: event.target.value })}
              />
              <SelectField
                id="jc-cost-type"
                label="Cost Type"
                options={JOB_COSTING_COST_TYPE_FILTERS}
                value={draft.cost_type}
                onChange={(event) => setDraft({ ...draft, cost_type: event.target.value })}
              />
              <SelectField
                id="jc-team-member"
                label="Team Member"
                options={[{ label: 'All Team Members', value: 'all' }, ...teamMembers.map((member) => ({ label: member.name, value: String(member.id) }))]}
                value={draft.team_member !== null ? String(draft.team_member) : 'all'}
                onChange={(event) => setDraft({ ...draft, team_member: event.target.value === 'all' ? null : Number(event.target.value) })}
              />
              <div className="flex items-end gap-3">
                <Button onClick={() => applyFilters(draft)}>Apply</Button>
                <Button variant="white" onClick={resetFilters}>Reset</Button>
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <p className="mb-6 text-sm text-white/70">{jobCount} active job{jobCount === 1 ? '' : 's'} in this view.</p>

      {/* ============================================ Labor + Materials ======= */}
      <div className="grid gap-6 xl:grid-cols-2">
        <Card padding="lg">
          <SectionHeading as="h3" title="Estimated vs Actual Labor" />
          <ComparisonBarChart rows={laborRows} formatValue={(v) => formatCurrency(v, 0)} />
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
        </Card>

        <Card padding="lg">
          <SectionHeading as="h3" title="Estimated vs Actual Materials" />
          {materialTotals.estimatedCost === 0 && materialTotals.actualCost === 0 ? (
            <EmptyState title="No material costing data yet" description="Log an estimate with material lines, or record an actual material cost on a job, to see this chart." />
          ) : (
            <DonutChart
              segments={[
                { label: 'Estimated', value: materialTotals.estimatedCost, tone: 'info' },
                { label: 'Actual', value: materialTotals.actualCost, tone: materialTotals.actualCost > materialTotals.estimatedCost ? 'danger' : 'success' },
              ]}
            />
          )}
          <div className="mt-6 grid gap-4 sm:grid-cols-2">
            <StatBlock label="Total Estimated" value={formatCurrency(materialTotals.estimatedCost, 2)} detail={`Across ${jobCount} jobs`} />
            <StatBlock
              label="Total Actual"
              value={formatCurrency(materialTotals.actualCost, 2)}
              detail={`Across ${jobCount} jobs`}
              note={materialVariancePct !== null ? `${Math.abs(materialVariancePct).toFixed(1)}% ${materialVariancePct >= 0 ? 'over' : 'under'} budget` : undefined}
              noteTone={materialVariance > 0 ? 'danger' : 'success'}
            />
          </div>
        </Card>
      </div>

      {/* ============================================ Profit/Loss + Alerts ==== */}
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card padding="lg">
          <SectionHeading as="h3" title="Profit/Loss" />
          {canViewCosts ? (
            <>
              <TrendLineChart
                points={[
                  { label: 'Total Revenue', value: profitLoss.revenue },
                  { label: 'Net Profit', value: profitLoss.profit },
                ]}
                formatValue={(v) => formatCurrency(v, 0)}
              />
              <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <StatBlock label="Total Revenue" value={formatCurrency(profitLoss.revenue, 0)} />
                <StatBlock
                  label="Net Profit"
                  value={formatCurrency(profitLoss.profit, 0)}
                  note={profitLoss.marginPct !== null ? `${profitLoss.marginPct}% margin` : undefined}
                  noteTone={profitLoss.profit >= 0 ? 'success' : 'danger'}
                />
              </div>
            </>
          ) : (
            <EmptyState title="Cost and revenue figures are restricted" description="Only a Project Manager, Admin or Owner can see this job's financial totals." />
          )}
        </Card>

        <Card padding="lg">
          <SectionHeading
            as="h3"
            title="Cost Overrun Alerts"
            actions={overrunAlerts.length > 0 ? <ButtonLink href={ROUTES.jobs} variant="secondary" size="sm">View All</ButtonLink> : undefined}
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
                      <ButtonLink href={routeTo.jobCosting(row.jobId)} variant="ghost" size="sm" rightIcon={ChevronRight}>
                        View Details
                      </ButtonLink>
                    </div>
                    <p className="mt-1 text-sm text-white/85">
                      {JOB_COSTING_OVERRUN_LABEL[row.overrunReason ?? ''] ?? 'Exceeding budget'}
                      {row.overrunPct !== null && ` by ${row.overrunPct}%`}
                    </p>
                    {canViewCosts && (
                      <p className="mt-0.5 text-sm font-medium text-status-danger">
                        {formatCurrency(row.overrunAmount, 2)} over budget
                      </p>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {/* ============================================ Status + Rankings ======= */}
      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <Card padding="lg">
          <SectionHeading as="h3" title="Jobs by Status" />
          {jobsByStatus.length === 0 ? (
            <EmptyState title="No jobs in this view" description="Adjust the filters to see jobs by status." />
          ) : (
            <>
              <DonutChart
                segments={jobsByStatus.map((row, index) => ({
                  label: JOB_STATUS_LABEL[row.status as keyof typeof JOB_STATUS_LABEL] ?? row.status,
                  value: row.count,
                  tone: statusTone(index),
                }))}
              />
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

        <Card padding="lg">
          <SectionHeading as="h3" title="Top Profitable Jobs" />
          {topProfitable.length === 0 ? (
            <EmptyState title="No costed jobs yet" description="Jobs with revenue and cost data will rank here." />
          ) : (
            <ul className="flex flex-col gap-4">
              {topProfitable.map((row) => (
                <RankingRow key={row.jobId} row={row} canViewCosts={canViewCosts} tone="success" />
              ))}
            </ul>
          )}
        </Card>

        <Card padding="lg">
          <SectionHeading as="h3" title="Least Profitable Jobs" />
          {leastProfitable.length === 0 ? (
            <EmptyState title="No costed jobs yet" description="Jobs with revenue and cost data will rank here." />
          ) : (
            <ul className="flex flex-col gap-4">
              {leastProfitable.map((row) => (
                <RankingRow key={row.jobId} row={row} canViewCosts={canViewCosts} tone="danger" />
              ))}
            </ul>
          )}
        </Card>
      </div>
    </PageTransition>
  )
}

function StatBlock({
  label,
  value,
  detail,
  note,
  noteTone,
}: {
  label: string
  value: string
  detail?: string
  note?: string
  noteTone?: 'success' | 'danger'
}) {
  return (
    <div className="rounded-panel border border-hairline bg-white/4 p-4">
      <p className="text-2xs tracking-wide text-white/70 uppercase">{label}</p>
      <p className="mt-1 text-xl font-bold text-white">{value}</p>
      {detail && <p className="mt-0.5 text-sm text-white/75">{detail}</p>}
      {note && (
        <p className={noteTone === 'danger' ? 'mt-1 text-sm font-medium text-status-danger' : 'mt-1 text-sm font-medium text-status-success'}>
          {note}
        </p>
      )}
    </div>
  )
}

function RankingRow({
  row,
  canViewCosts,
  tone,
}: {
  row: JobCostingDashboardProps['topProfitable'][number]
  canViewCosts: boolean
  tone: 'success' | 'danger'
}) {
  const progress = row.revenue > 0 ? Math.min(100, Math.max(0, (row.profit / row.revenue) * 100)) : 0

  return (
    <li>
      <ButtonLink href={routeTo.jobCosting(row.jobId)} variant="ghost" className="block w-full p-0 text-left hover:bg-transparent">
        <div className="flex items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="truncate font-semibold text-white">{row.jobName}</p>
            <p className="truncate text-sm text-white/70">{row.client ?? 'No client on record'}</p>
          </div>
          {canViewCosts && (
            <span className={tone === 'success' ? 'shrink-0 font-semibold text-status-success' : 'shrink-0 font-semibold text-status-danger'}>
              {row.marginPct !== null ? `${row.marginPct}%` : '—'}
            </span>
          )}
        </div>
        {canViewCosts && (
          <div className="mt-2 flex items-center gap-3">
            <ProgressBar value={Math.abs(progress)} tone={tone} size="sm" className="flex-1" />
            <span className="shrink-0 text-sm font-medium text-white/90">{formatCurrency(row.profit, 0)}</span>
          </div>
        )}
      </ButtonLink>
    </li>
  )
}

JobCosting.layout = appLayout
