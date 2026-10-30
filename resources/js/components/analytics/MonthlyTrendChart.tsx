import { motion } from 'framer-motion'
import type { AnalyticsTrendPoint } from '@/types'
import { formatCurrency } from '@/utils'

const SERIES = [
  { key: 'billed', label: 'Billed', className: 'bg-brand-deep' },
  { key: 'collected', label: 'Collected', className: 'bg-status-success' },
  { key: 'cost', label: 'Logged cost', className: 'bg-status-warning' },
] as const

/** Billed, collected and logged cost per month, as grouped bars on one shared scale. */
export function MonthlyTrendChart({ points }: { points: readonly AnalyticsTrendPoint[] }) {
  const max = Math.max(1, ...points.flatMap((p) => [p.billed, p.collected, p.cost]))

  return (
    <div>
      <div className="mb-3 flex flex-wrap gap-4 text-sm text-white/85">
        {SERIES.map((series) => (
          <span key={series.key} className="inline-flex items-center gap-2">
            <span className={`h-2.5 w-2.5 rounded-full ${series.className}`} aria-hidden />
            {series.label}
          </span>
        ))}
      </div>
      <div className="flex h-48 items-end gap-2 overflow-x-auto pb-1" role="img" aria-label="Monthly billed, collected and cost">
        {points.map((point) => (
          <div key={point.month} className="flex min-w-[44px] flex-1 flex-col items-center gap-1.5">
            <div className="flex h-40 w-full items-end justify-center gap-0.5">
              {SERIES.map((series) => (
                <motion.div
                  key={series.key}
                  initial={{ height: 0 }}
                  animate={{ height: `${(point[series.key] / max) * 100}%` }}
                  transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1] }}
                  title={`${point.label} · ${series.label}: ${formatCurrency(point[series.key], 0)}`}
                  className={`w-1/4 min-w-[6px] rounded-t ${series.className}`}
                />
              ))}
            </div>
            <span className="text-2xs text-white/70">{point.label}</span>
          </div>
        ))}
      </div>
    </div>
  )
}
