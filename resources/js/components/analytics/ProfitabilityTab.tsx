import { Card, EmptyState, SectionHeading } from '@/components/common'
import { ButtonLink } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobCostRow } from '@/types'
import { JOB_STATUS_LABEL, formatCurrency, formatHours } from '@/utils'
import { SortableTable, type SortableColumn } from './shared'

const money = (value: number | null) => (value === null ? '—' : formatCurrency(value, 0))

export function ProfitabilityTab({ rows, canViewCosts }: { rows: readonly JobCostRow[]; canViewCosts: boolean }) {
  const columns: SortableColumn<JobCostRow>[] = [
    {
      key: 'job',
      header: 'Job',
      sortValue: (r) => r.jobName.toLowerCase(),
      render: (r) => (
        <ButtonLink href={routeTo.jobCosting(r.jobId)} variant="ghost" size="sm" className="h-auto p-0 text-left font-semibold">
          {r.jobName}
        </ButtonLink>
      ),
    },
    { key: 'client', header: 'Client', sortValue: (r) => r.client ?? '', render: (r) => r.client ?? '—' },
    { key: 'status', header: 'Status', sortValue: (r) => r.jobStatus, render: (r) => JOB_STATUS_LABEL[r.jobStatus as keyof typeof JOB_STATUS_LABEL] ?? r.jobStatus },
    {
      key: 'hours',
      header: 'Hours (act / est)',
      align: 'right',
      sortValue: (r) => r.laborHoursVariance,
      render: (r) => `${formatHours(r.actualLaborHours)} / ${formatHours(r.estimatedLaborHours)}`,
    },
    ...(canViewCosts
      ? ([
          { key: 'revenue', header: 'Revenue', align: 'right', sortValue: (r) => r.revenue, render: (r) => money(r.revenue) },
          { key: 'cost', header: 'Cost', align: 'right', sortValue: (r) => r.actualTotalCost, render: (r) => money(r.actualTotalCost) },
          {
            key: 'profit',
            header: 'Profit',
            align: 'right',
            sortValue: (r) => r.profit,
            render: (r) => <span className={(r.profit ?? 0) < 0 ? 'text-status-danger' : 'text-status-success'}>{money(r.profit)}</span>,
          },
          { key: 'margin', header: 'Margin', align: 'right', sortValue: (r) => r.marginPct, render: (r) => (r.marginPct === null ? '—' : `${r.marginPct}%`) },
          {
            key: 'variance',
            header: 'Cost vs est.',
            align: 'right',
            sortValue: (r) => r.totalCostVariancePct,
            render: (r) => (r.totalCostVariancePct === null ? '—' : `${r.totalCostVariancePct > 0 ? '+' : ''}${r.totalCostVariancePct}%`),
          },
        ] satisfies SortableColumn<JobCostRow>[])
      : []),
    ...(canViewCosts
      ? ([
          {
            key: 'scope',
            header: 'Scope change',
            align: 'right',
            sortValue: (r) => r.scopeChange,
            render: (r) => (r.scopeChange === null ? '—' : <span className={r.scopeChange > 0 ? 'text-status-danger' : ''}>{`${r.scopeChange > 0 ? '+' : ''}${money(r.scopeChange)}`}</span>),
          },
        ] satisfies SortableColumn<JobCostRow>[])
      : []),
    {
      key: 'flag',
      header: 'Budget',
      sortValue: (r) => (r.isOverBudget ? 1 : 0),
      render: (r) => (r.isOverBudget ? <span className="font-medium text-status-danger">Over</span> : <span className="text-status-success">On track</span>),
    },
  ]

  return (
    <Card accent="brand" padding="lg">
      <SectionHeading as="h3" title="Job Profitability" />
      {rows.length === 0 ? (
        <EmptyState title="No jobs in this view" description="Adjust the filters to see job profitability." />
      ) : (
        <SortableTable columns={columns} rows={rows} getRowId={(r) => r.jobId} initialSort={{ key: canViewCosts ? 'profit' : 'hours', dir: 'asc' }} emptyText="No jobs." />
      )}
    </Card>
  )
}
