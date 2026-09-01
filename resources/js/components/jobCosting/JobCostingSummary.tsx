import { ChartColumn } from 'lucide-react'
import { ButtonLink, Card, CardHeader } from '@/components/common'
import { routeTo } from '@/constants'
import type { JobCostRow } from '@/types'
import { formatCurrency, formatHours } from '@/utils'

export interface JobCostingSummaryProps {
  jobId: number
  summary: JobCostRow
  /** Hidden entirely for anyone without permission to see job costs. */
  canViewCosts: boolean
}

/** The Job Detail widget: estimated vs actual cost, variance, profit and margin. */
export function JobCostingSummary({ jobId, summary, canViewCosts }: JobCostingSummaryProps) {
  return (
    <Card padding="lg">
      <CardHeader
        title="Job Costing"
        actions={
          <ButtonLink href={routeTo.jobCosting(jobId)} variant="secondary" leftIcon={ChartColumn}>
            View Job Costing
          </ButtonLink>
        }
      />

      {canViewCosts && summary.estimatedTotalCost !== null && summary.actualTotalCost !== null && summary.totalCostVariance !== null && summary.profit !== null ? (
        <>
          <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Stat label="Estimated Cost" value={formatCurrency(summary.estimatedTotalCost, 0)} />
            <Stat label="Actual Cost" value={formatCurrency(summary.actualTotalCost, 0)} />
            <Stat
              label="Variance"
              value={`${summary.totalCostVariance >= 0 ? '+' : ''}${formatCurrency(summary.totalCostVariance, 0)}`}
              tone={summary.totalCostVariance > 0 ? 'text-status-danger' : 'text-status-success'}
            />
            <Stat
              label="Profit"
              value={formatCurrency(summary.profit, 0)}
              tone={summary.profit >= 0 ? 'text-status-success' : 'text-status-danger'}
            />
          </dl>
          <p className="mt-4 text-sm text-white/75">
            {formatHours(summary.actualLaborHours)} of {formatHours(summary.estimatedLaborHours)} estimated labor hours
            {summary.marginPct !== null ? ` — ${summary.marginPct}% margin.` : '.'}
          </p>
        </>
      ) : (
        <p className="text-md text-white/70">Only a Client Manager, Admin or Owner can see this job's cost figures.</p>
      )}
    </Card>
  )
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div>
      <dt className="text-xs tracking-wide text-white/70 uppercase">{label}</dt>
      <dd className={`mt-1 text-xl font-bold text-white ${tone ?? ''}`}>{value}</dd>
    </div>
  )
}
