import { AlertTriangle, ChevronRight, Clock, FileWarning, TrendingDown } from 'lucide-react'
import { ButtonLink, noticeClasses } from '@/components/common'
import { routeTo } from '@/constants'
import type { AnalyticsAlert } from '@/types'

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
            className={noticeClasses(alert.severity === 'danger' ? 'danger' : 'warning', 'flex items-start gap-2.5 px-3.5 py-3')}
          >
            <Icon size={16} className="mt-[3px] shrink-0" aria-hidden />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-bold">
                  {alert.title}
                  {alert.jobName && <span className="font-normal opacity-75"> · {alert.jobName}</span>}
                </p>
                {alert.jobId !== null && (
                  <ButtonLink href={routeTo.jobCosting(alert.jobId)} variant="ghost" size="sm" rightIcon={ChevronRight}>
                    View Details
                  </ButtonLink>
                )}
              </div>
              <p className="mt-0.5">{alert.detail}</p>
            </div>
          </li>
        )
      })}
    </ul>
  )
}
