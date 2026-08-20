import { motion } from 'framer-motion'
import { cn } from '@/utils'

export interface ComparisonBarChartRow {
  readonly label: string
  readonly value: number
  /** Tailwind fill class, spelled out at each call site — see `DonutChart`'s note on why. */
  readonly barClassName: string
}

export interface ComparisonBarChartProps {
  rows: readonly ComparisonBarChartRow[]
  /** Formats the axis ticks and the value shown at each bar's end. */
  formatValue: (value: number) => string
  className?: string
}

/**
 * Horizontal comparison bars — "Estimated" vs "Actual", or any other small
 * set of labeled magnitudes on a shared scale.
 */
export function ComparisonBarChart({ rows, formatValue, className }: ComparisonBarChartProps) {
  const max = Math.max(1, ...rows.map((row) => row.value))
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => fraction * max)

  return (
    <div className={cn('flex flex-col gap-5', className)}>
      {rows.map((row) => (
        <div key={row.label}>
          <div className="mb-1.5 flex items-center justify-between text-sm">
            <span className="text-white/90">{row.label}</span>
            <span className="font-semibold tabular-nums text-white">{formatValue(row.value)}</span>
          </div>
          <div className="h-6 w-full overflow-hidden rounded-full bg-white/8">
            <motion.div
              initial={{ width: 0 }}
              animate={{ width: `${(row.value / max) * 100}%` }}
              transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
              className={cn('h-full rounded-full', row.barClassName)}
            />
          </div>
        </div>
      ))}

      <div className="flex justify-between text-2xs text-white/60">
        {ticks.map((tick) => (
          <span key={tick}>{formatValue(tick)}</span>
        ))}
      </div>
    </div>
  )
}
