import { Clock } from 'lucide-react'
import { ButtonLink, Card, CardHeader } from '@/components/common'
import { ROUTES } from '@/constants'
import type { JobLaborSummary } from '@/types'
import { formatCurrency, formatHours } from '@/utils'

export interface JobTimeTrackingSummaryProps {
  jobId: number
  summary: JobLaborSummary
  /** Hidden entirely for anyone without permission to see job costs. */
  canViewCosts: boolean
}

/** The Job Detail widget: hours logged, billable, overtime, and labor cost. */
export function JobTimeTrackingSummary({
  jobId,
  summary,
  canViewCosts,
}: JobTimeTrackingSummaryProps) {
  return (
    <Card padding="lg">
      <CardHeader
        title="Time Tracking"
        actions={
          <ButtonLink
            href={`${ROUTES.timeEntries}?job=${jobId}`}
            variant="secondary"
            leftIcon={Clock}
          >
            View Time
          </ButtonLink>
        }
      />

      <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Stat label="Logged" value={formatHours(summary.actualHours)} />
        <Stat label="Billable" value={formatHours(summary.billableHours)} />
        <Stat
          label="Overtime"
          value={formatHours(summary.overtimeHours)}
          tone={summary.overtimeHours > 0 ? 'text-status-warning' : undefined}
        />
        {canViewCosts && <Stat label="Labor Cost" value={formatCurrency(summary.laborCost, 0)} />}
      </dl>

      {summary.estimatedHours > 0 && (
        <p className="mt-4 text-sm text-white/75">
          {formatHours(summary.actualHours)} of {formatHours(summary.estimatedHours)} estimated
          {summary.remainingHours > 0
            ? ` — ${formatHours(summary.remainingHours)} remaining.`
            : ' — at or over the estimate.'}
        </p>
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
