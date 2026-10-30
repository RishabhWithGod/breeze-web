import { AlertTriangle, ChevronRight, Clock, FileWarning, TrendingDown } from 'lucide-react'
import { ButtonLink } from '@/components/common'
import { routeTo } from '@/constants'
import type { AnalyticsAlert } from '@/types'
import { cn } from '@/utils'

const ICONS = {
  overrun: AlertTriangle,
  hours_warning: Clock,
  low_margin: TrendingDown,
  unbilled: FileWarning,
  overdue_invoice: FileWarning,
  missing_checkout: Clock,
} as const

export function AlertList({ alerts }: { alerts: readonly AnalyticsAlert[] }) {
  return (
    <ul className="flex flex-col gap-3">
      {alerts.map((alert) => {
        const Icon = ICONS[alert.type]
        return (
          <li
            key={alert.id}
            className={cn(
              'flex items-start gap-3 rounded-panel border p-4',
              alert.severity === 'danger' ? 'border-status-danger/30 bg-status-danger/10' : 'border-status-warning/30 bg-status-warning/10',
            )}
          >
            <Icon size={18} className={cn('mt-0.5 shrink-0', alert.severity === 'danger' ? 'text-status-danger' : 'text-status-warning')} aria-hidden />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-white">
                  {alert.title}
                  {alert.jobName && <span className="font-normal text-white/75"> · {alert.jobName}</span>}
                </p>
                {alert.jobId !== null && (
                  <ButtonLink href={routeTo.jobCosting(alert.jobId)} variant="ghost" size="sm" rightIcon={ChevronRight}>
                    View Details
                  </ButtonLink>
                )}
              </div>
              <p className="mt-1 text-sm text-white/85">{alert.detail}</p>
            </div>
          </li>
        )
      })}
    </ul>
  )
}
