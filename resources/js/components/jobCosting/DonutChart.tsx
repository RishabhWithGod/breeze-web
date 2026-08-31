import { useId } from 'react'
import type { Tone } from '@/types'
import { cn } from '@/utils'

/*
 * Tailwind's build-time scanner extracts literal class-name strings from the
 * source text — it cannot see a class assembled at runtime (e.g. via
 * `.replace('fill-', 'stroke-')`), so `stroke-*` and `bg-*` variants are
 * spelled out here rather than derived from `TONE_FILL`.
 */
const TONE_STROKE: Record<Tone, string> = {
  brand: 'stroke-brand-deep',
  success: 'stroke-status-success',
  warning: 'stroke-status-warning',
  danger: 'stroke-status-danger',
  info: 'stroke-status-info',
  neutral: 'stroke-white/30',
}

const TONE_BG: Record<Tone, string> = {
  brand: 'bg-brand-deep',
  success: 'bg-status-success',
  warning: 'bg-status-warning',
  danger: 'bg-status-danger',
  info: 'bg-status-info',
  neutral: 'bg-white/30',
}

export interface DonutSegment {
  readonly label: string
  readonly value: number
  readonly tone: Tone
}

export interface DonutChartProps {
  segments: readonly DonutSegment[]
  /** Shown in the center of the ring. */
  centerLabel?: string
  centerValue?: string
  className?: string
}

const SIZE = 160
const STROKE = 28
const RADIUS = (SIZE - STROKE) / 2
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

/**
 * Generic donut — a proportional ring of segments plus a real-number legend.
 * Used for both "Estimated vs Actual" comparisons and status breakdowns; the
 * caller decides what each segment means, this only draws it.
 */
export function DonutChart({ segments, centerLabel, centerValue, className }: DonutChartProps) {
  const gradientId = useId()
  const total = segments.reduce((sum, s) => sum + s.value, 0)

  let offset = 0
  const arcs = segments.map((segment) => {
    const fraction = total > 0 ? segment.value / total : 0
    const length = fraction * CIRCUMFERENCE
    const arc = { ...segment, length, offset }
    offset += length
    return arc
  })

  return (
    <div className={cn('flex flex-col items-center gap-5 sm:flex-row sm:items-center sm:justify-center', className)}>
      <svg
        viewBox={`0 0 ${SIZE} ${SIZE}`}
        width={SIZE}
        height={SIZE}
        role="img"
        aria-label={segments.map((s) => `${s.label}: ${s.value}`).join(', ')}
        className="shrink-0 -rotate-90"
      >
        <defs>
          <clipPath id={gradientId}>
            <circle cx={SIZE / 2} cy={SIZE / 2} r={RADIUS} />
          </clipPath>
        </defs>
        <circle
          cx={SIZE / 2}
          cy={SIZE / 2}
          r={RADIUS}
          fill="none"
          strokeWidth={STROKE}
          className="stroke-white/10"
        />
        {total > 0 &&
          arcs.map((arc) => (
            <circle
              key={arc.label}
              cx={SIZE / 2}
              cy={SIZE / 2}
              r={RADIUS}
              fill="none"
              strokeWidth={STROKE}
              strokeDasharray={`${arc.length} ${CIRCUMFERENCE - arc.length}`}
              strokeDashoffset={-arc.offset}
              className={cn(TONE_STROKE[arc.tone], 'transition-all')}
            >
              <title>{`${arc.label}: ${arc.value}`}</title>
            </circle>
          ))}
      </svg>

      <div className="flex flex-col items-center gap-2 sm:items-start">
        {centerValue && (
          <div className="text-center sm:text-left">
            <p className="text-2xl font-bold text-white">{centerValue}</p>
            {centerLabel && <p className="text-sm text-white/70">{centerLabel}</p>}
          </div>
        )}
        <ul className="flex flex-col gap-1.5">
          {segments.map((segment) => (
            <li key={segment.label} className="flex items-center gap-2 text-sm text-white/90">
              <span
                aria-hidden
                className={cn('size-2.5 shrink-0 rounded-full', TONE_BG[segment.tone])}
              />
              {segment.label}
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
