import { ArrowDownRight, ArrowRight, ArrowUpRight, type LucideIcon } from 'lucide-react'
import { Card, IconBubble } from '@/components/common'
import type { Tone } from '@/types'
import { cn } from '@/utils'

export interface StatCardProps {
  label: string
  value: string
  hint?: string
  delta?: string
  trend?: 'up' | 'down' | 'flat'
  icon?: LucideIcon
  tone?: Tone
  index?: number
}

const TREND_ICON = {
  up: ArrowUpRight,
  down: ArrowDownRight,
  flat: ArrowRight,
} as const

const TREND_COLOR = {
  up: 'text-status-success',
  down: 'text-status-warning',
  flat: 'text-white/60',
} as const

/** KPI tile used across the results dashboard and statistics rows. */
export function StatCard({
  label,
  value,
  hint,
  delta,
  trend = 'flat',
  icon,
  tone = 'brand',
  index,
}: StatCardProps) {
  const TrendIcon = TREND_ICON[trend]

  return (
    <Card hoverable className="h-full" {...(index !== undefined ? { index } : {})}>
      <div className="flex items-start justify-between gap-3">
        <p className="text-md text-white/60">{label}</p>
        {icon && <IconBubble icon={icon} tone={tone} size="sm" />}
      </div>

      <p className="mt-3 text-2xl font-bold text-white sm:text-3xl">{value}</p>

      <div className="mt-2 flex flex-wrap items-center gap-2">
        {delta && (
          <span
            className={cn(
              'inline-flex items-center gap-1 text-sm font-semibold',
              TREND_COLOR[trend],
            )}
          >
            <TrendIcon size={14} aria-hidden />
            {delta}
          </span>
        )}
        {hint && <span className="text-sm text-white/45">{hint}</span>}
      </div>
    </Card>
  )
}
