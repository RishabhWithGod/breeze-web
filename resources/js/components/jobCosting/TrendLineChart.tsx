import { motion } from 'framer-motion'
import { cn } from '@/utils'

export interface TrendLineChartPoint {
  readonly label: string
  readonly value: number
}

export interface TrendLineChartProps {
  points: readonly TrendLineChartPoint[]
  formatValue: (value: number) => string
  className?: string
}

const VIEW_WIDTH = 560
const VIEW_HEIGHT = 180
const PAD = 24

/** A small connected-point line — Revenue → Cost → Profit, or any short real series. */
export function TrendLineChart({ points, formatValue, className }: TrendLineChartProps) {
  const values = points.map((p) => p.value)
  const min = Math.min(0, ...values)
  const max = Math.max(1, ...values)
  const span = max - min || 1
  const slot = points.length > 1 ? (VIEW_WIDTH - PAD * 2) / (points.length - 1) : 0

  const coords = points.map((point, index) => ({
    x: PAD + index * slot,
    y: PAD + (1 - (point.value - min) / span) * (VIEW_HEIGHT - PAD * 2),
    point,
  }))

  const path = coords.map((c, i) => `${i === 0 ? 'M' : 'L'} ${c.x} ${c.y}`).join(' ')

  return (
    <div className={cn('', className)}>
      <svg viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`} className="w-full" role="img" aria-label={points.map((p) => `${p.label}: ${formatValue(p.value)}`).join(', ')}>
        <motion.path
          d={path}
          fill="none"
          strokeWidth={3}
          className="stroke-brand-deep"
          initial={{ pathLength: 0 }}
          animate={{ pathLength: 1 }}
          transition={{ duration: 0.8, ease: [0.16, 1, 0.3, 1] }}
        />
        {coords.map((c) => (
          <g key={c.point.label}>
            <circle cx={c.x} cy={c.y} r={5} className="fill-brand" />
            <text x={c.x} y={c.y - 14} textAnchor="middle" className="fill-white text-[13px] font-semibold">
              {formatValue(c.point.value)}
            </text>
          </g>
        ))}
      </svg>
      <div className="mt-1 flex justify-between text-2xs text-white/70">
        {points.map((point) => (
          <span key={point.label}>{point.label}</span>
        ))}
      </div>
    </div>
  )
}
