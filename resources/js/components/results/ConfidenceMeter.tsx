import { cn, confidenceLabel, confidenceTone, formatPercent } from '@/utils'
import type { Tone } from '@/types'

const BAR_TONES: Record<Tone, string> = {
  brand: 'bg-brand',
  success: 'bg-status-success',
  warning: 'bg-status-warning',
  danger: 'bg-status-danger',
  info: 'bg-status-info',
  neutral: 'bg-white/50',
}

const TEXT_TONES: Record<Tone, string> = {
  brand: 'text-brand',
  success: 'text-status-success',
  warning: 'text-status-warning',
  danger: 'text-red-300',
  info: 'text-status-info',
  neutral: 'text-white/90',
}

/** Quantised widths keep the meter styling in Tailwind rather than inline CSS. */
const WIDTH_STEPS = [
  'w-0',
  'w-[10%]',
  'w-[20%]',
  'w-[30%]',
  'w-[40%]',
  'w-[50%]',
  'w-[60%]',
  'w-[70%]',
  'w-[80%]',
  'w-[90%]',
  'w-full',
] as const

export interface ConfidenceMeterProps {
  /** 0–1 confidence ratio. */
  value: number
  showLabel?: boolean
  className?: string
}

/** Compact bar + percentage used inside the symbol legend table. */
export function ConfidenceMeter({
  value,
  showLabel = true,
  className,
}: ConfidenceMeterProps) {
  const tone = confidenceTone(value)
  const step = Math.min(
    WIDTH_STEPS.length - 1,
    Math.max(0, Math.round(value * (WIDTH_STEPS.length - 1))),
  )

  return (
    <div className={cn('flex items-center gap-3', className)}>
      <div className="h-1.5 w-20 shrink-0 overflow-hidden rounded-pill bg-white/15">
        <div
          className={cn('h-full rounded-pill transition-all', BAR_TONES[tone], WIDTH_STEPS[step])}
        />
      </div>
      <span className={cn('text-sm font-semibold tabular-nums', TEXT_TONES[tone])}>
        {formatPercent(value)}
      </span>
      {showLabel && (
        <span className="hidden text-xs text-white/70 lg:inline">
          {confidenceLabel(value)}
        </span>
      )}
    </div>
  )
}
