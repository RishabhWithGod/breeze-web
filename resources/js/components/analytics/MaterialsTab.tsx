import { Card, EmptyState, SectionHeading } from '@/components/common'
import { ButtonLink } from '@/components/common'
import { routeTo } from '@/constants'
import type { AnalyticsCostCategory, JobCostRow } from '@/types'
import { formatCurrency } from '@/utils'
import { ComparisonBarChart } from '@/components/jobCosting'
import { SortableTable, type SortableColumn } from './shared'

const money = (value: number | null) => (value === null ? '—' : formatCurrency(value, 0))

/**
 * "Estimated" is the estimate the job was raised on. "Actual" is the job's
 * estimate as it stands now — final, once the job is completed — plus
 * anything logged on top. The difference is every scope change since the job
 * was created: estimate edits, the Estimate Builder, and approved change orders.
 */
export function MaterialsTab({
  rows,
  breakdown,
  canViewCosts,
}: {
  rows: readonly JobCostRow[]
  breakdown: readonly AnalyticsCostCategory[]
  canViewCosts: boolean
}) {
  if (!canViewCosts) {
    return (
      <Card accent="success" padding="lg">
        <EmptyState title="Cost figures are restricted" description="Only a Client Manager, Admin or Owner can see material and equipment costs." />
      </Card>
    )
  }

  const goods = breakdown.filter((c) => c.key === 'material' || c.key === 'equipment')
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
    { key: 'mEst', header: 'Material estimated', align: 'right', sortValue: (r) => r.estimatedMaterialCost, render: (r) => money(r.estimatedMaterialCost) },
    { key: 'mAct', header: 'Material actual', align: 'right', sortValue: (r) => r.actualMaterialCost, render: (r) => money(r.actualMaterialCost) },
    {
      key: 'mVar',
      header: 'Material over',
      align: 'right',
      sortValue: (r) => r.materialCostVariance,
      render: (r) => <span className={(r.materialCostVariance ?? 0) > 0 ? 'text-status-danger' : ''}>{money(r.materialCostVariance)}</span>,
    },
    { key: 'eEst', header: 'Equipment estimated', align: 'right', sortValue: (r) => r.estimatedEquipmentCost, render: (r) => money(r.estimatedEquipmentCost) },
    {
      key: 'eVar',
      header: 'Equipment over',
      align: 'right',
      sortValue: (r) => r.equipmentCostVariance,
      render: (r) => <span className={(r.equipmentCostVariance ?? 0) > 0 ? 'text-status-danger' : ''}>{money(r.equipmentCostVariance)}</span>,
    },
    {
      key: 'scope',
      header: 'Scope change',
      align: 'right',
      sortValue: (r) => r.scopeChange,
      render: (r) => (r.scopeChange === null ? '—' : <span className={r.scopeChange > 0 ? 'text-status-danger' : ''}>{`${r.scopeChange > 0 ? '+' : ''}${formatCurrency(r.scopeChange, 0)}`}</span>),
    },
    {
      key: 'state',
      header: 'Estimate',
      sortValue: (r) => (r.isFinal ? 1 : 0),
      render: (r) => (r.isFinal ? 'Final' : r.hasBaseline ? 'Live' : 'No baseline'),
    },
    {
      key: 'oAct',
      header: 'Other costs',
      align: 'right',
      sortValue: (r) => r.actualOtherCost,
      render: (r) => money(r.actualOtherCost),
    },
  ]

  return (
    <div className="grid gap-6">
      <div className="grid gap-6 xl:grid-cols-2">
        {goods.map((category) => (
          <Card key={category.key} accent={category.key === 'material' ? 'success' : 'info'} padding="lg">
            <SectionHeading as="h3" title={`${category.label}: Estimated vs Actual`} />
            <ComparisonBarChart
              rows={[
                { label: 'Estimated (at job creation)', value: category.estimated, barClassName: 'bg-brand-deep' },
                { label: 'Actual (final / current estimate)', value: category.actual, barClassName: category.actual > category.estimated ? 'bg-status-danger' : 'bg-status-success' },
              ]}
              formatValue={(v) => formatCurrency(v, 0)}
            />
            <p className="mt-4 text-sm text-white/75">
              Actual = the job's estimate as it stands now (final once completed) plus logged costs. Change since job creation:{' '}
              <span className={category.variance > 0 ? 'font-semibold text-status-danger' : 'font-semibold text-status-success'}>{formatCurrency(category.variance, 0)}</span>
            </p>
          </Card>
        ))}
      </div>
      <Card accent="neutral" padding="lg">
        <SectionHeading as="h3" title="Materials & Equipment by Job" />
        <SortableTable columns={columns} rows={rows} getRowId={(r) => r.jobId} initialSort={{ key: 'mVar', dir: 'desc' }} emptyText="No jobs in this view." />
      </Card>
    </div>
  )
}
