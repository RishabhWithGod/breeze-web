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
  /**
   * Wear the section accent in `tone` — the lit border the rest of the app's
   * cards carry.
   *
   * Opt-in, because a stat tile is usually one of a row of four and a row of
   * four lit borders is a fence. Where the tiles *are* the section, as on the
   * billing summary, it is what makes them read as one.
   */
  accent?: boolean
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
  flat: 'text-white/85',
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
  accent = false,
  index,
}: StatCardProps) {
  const TrendIcon = TREND_ICON[trend]

  return (
    <Card
      hoverable
      className="h-full"
      {...(accent ? { accent: tone } : {})}
      {...(index !== undefined ? { index } : {})}
    >
      <div className="flex items-start justify-between gap-3">
        <p className="text-md text-white/85">{label}</p>
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
        {hint && <span className="text-sm text-white/70">{hint}</span>}
      </div>
    </Card>
  )
}
