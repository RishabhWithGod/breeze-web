import type { Tone } from '@/types'
import { TONE_DOT_CLASS, cn } from '@/utils'

const TEXT_TONES: Record<Tone, string> = {
  brand: 'text-brand',
  success: 'text-status-success',
  warning: 'text-status-warning',
  danger: 'text-red-300',
  info: 'text-status-info',
  neutral: 'text-white/70',
}

export interface StatusChipProps {
  tone: Tone
  label: string
  /** Adds a soft pulsing halo — used for in-flight states. */
  pulse?: boolean
  /** Colour-only variant: drops the leading dot, keeping just the label. */
  hideDot?: boolean
  className?: string
}

/** Dot + label status indicator used in tables and headers. */
export function StatusChip({
  tone,
  label,
  pulse = false,
  hideDot = false,
  className,
}: StatusChipProps) {
  return (
    <span
      className={cn('inline-flex items-center gap-2 text-md font-medium', className)}
    >
      <span className={cn('relative inline-flex size-2.5', hideDot && 'hidden')}>
        <span className={cn('size-2.5 rounded-full', TONE_DOT_CLASS[tone])} />
        {pulse && (
          <span
            className={cn(
              'absolute inset-0 rounded-full animate-pulse-ring',
              TONE_DOT_CLASS[tone],
            )}
            aria-hidden
          />
        )}
      </span>
      <span className={TEXT_TONES[tone]}>{label}</span>
    </span>
  )
}
