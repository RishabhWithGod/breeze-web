import { motion } from 'framer-motion'
import type { Tone } from '@/types'
import { cn } from '@/utils'

const FILL_TONES: Record<Tone, string> = {
  brand: 'bg-brand-deep',
  success: 'bg-status-success',
  warning: 'bg-status-warning',
  danger: 'bg-status-danger',
  info: 'bg-status-info',
  neutral: 'bg-white/60',
}

const HEIGHTS = {
  sm: 'h-1.5',
  md: 'h-2.5',
  lg: 'h-[30px]',
} as const

export interface ProgressBarProps {
  /** 0–100. Values outside the range are clamped. */
  value: number
  tone?: Tone
  size?: keyof typeof HEIGHTS
  /** Renders the percentage inside (lg) or above (sm/md) the track. */
  showValue?: boolean
  label?: string
  /** Animated diagonal stripes for indeterminate-feeling activity. */
  striped?: boolean
  className?: string
}

export function ProgressBar({
  value,
  tone = 'brand',
  size = 'md',
  showValue = false,
  label,
  striped = false,
  className,
}: ProgressBarProps) {
  const clamped = Math.min(100, Math.max(0, Math.round(value)))

  return (
    <div className={cn('w-full', className)}>
      {(label || (showValue && size !== 'lg')) && (
        <div className="mb-2 flex items-center justify-between text-sm text-white/70">
          {label && <span>{label}</span>}
          {showValue && <span className="font-medium text-white">{clamped}%</span>}
        </div>
      )}

      <div
        role="progressbar"
        aria-valuenow={clamped}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label ?? 'Progress'}
        className={cn(
          'w-full overflow-hidden rounded-pill bg-white/25',
          HEIGHTS[size],
        )}
      >
        <motion.div
          initial={{ width: 0 }}
          animate={{ width: `${clamped}%` }}
          transition={{ duration: 0.5, ease: 'easeOut' }}
          className={cn(
            'flex h-full items-center justify-end rounded-pill',
            FILL_TONES[tone],
            striped && 'shimmer',
          )}
        >
          {showValue && size === 'lg' && clamped > 8 && (
            <span className="px-3 text-sm font-semibold text-brand-ink">
              {clamped}%
            </span>
          )}
        </motion.div>
      </div>
    </div>
  )
}

/** Fixed ring geometries keep sizing in the design system instead of inline styles. */
const RING_SIZES = {
  sm: { box: 'size-16', px: 64, stroke: 6 },
  md: { box: 'size-24', px: 96, stroke: 8 },
  lg: { box: 'size-36', px: 144, stroke: 10 },
} as const

export interface CircularProgressProps {
  value: number
  size?: keyof typeof RING_SIZES
  tone?: Tone
  className?: string
  children?: React.ReactNode
}

const STROKE_TONES: Record<Tone, string> = {
  brand: 'stroke-brand',
  success: 'stroke-status-success',
  warning: 'stroke-status-warning',
  danger: 'stroke-status-danger',
  info: 'stroke-status-info',
  neutral: 'stroke-white/60',
}

/** Ring variant used for confidence scores. */
export function CircularProgress({
  value,
  size = 'md',
  tone = 'brand',
  className,
  children,
}: CircularProgressProps) {
  const { box, px, stroke } = RING_SIZES[size]
  const clamped = Math.min(100, Math.max(0, value))
  const radius = (px - stroke) / 2
  const circumference = 2 * Math.PI * radius

  return (
    <div className={cn('relative inline-grid place-items-center', box, className)}>
      <svg width={px} height={px} className="-rotate-90" aria-hidden>
        <circle
          cx={px / 2}
          cy={px / 2}
          r={radius}
          strokeWidth={stroke}
          className="fill-none stroke-white/15"
        />
        <motion.circle
          cx={px / 2}
          cy={px / 2}
          r={radius}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={circumference}
          initial={{ strokeDashoffset: circumference }}
          animate={{ strokeDashoffset: circumference * (1 - clamped / 100) }}
          transition={{ duration: 1, ease: 'easeOut' }}
          className={cn('fill-none', STROKE_TONES[tone])}
        />
      </svg>
      <div className="absolute inset-0 grid place-items-center">{children}</div>
    </div>
  )
}
