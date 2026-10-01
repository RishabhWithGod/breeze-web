import type { Tone } from '@/types'
import { TONE_DOT_CLASS, cn } from '@/utils'

const TEXT_TONES: Record<Tone, string> = {
  brand: 'text-brand',
  success: 'text-status-success',
  warning: 'text-status-warning',
  danger: 'text-red-300',
  info: 'text-status-info',
  purple: 'text-status-purple',
  blue: 'text-status-blue',
  neutral: 'text-white/90',
}

const PILL_TONES: Record<Tone, string> = {
  brand: 'bg-brand/15 border-brand/40',
  success: 'bg-status-success/12 border-status-success/40',
  warning: 'bg-status-warning/15 border-status-warning/40',
  danger: 'bg-status-danger/20 border-status-danger/50',
  info: 'bg-status-info/15 border-status-info/40',
  purple: 'bg-status-purple/15 border-status-purple/40',
  blue: 'bg-status-blue/15 border-status-blue/40',
  neutral: 'bg-white/10 border-hairline-strong',
}

export interface StatusChipProps {
  tone: Tone
  label: string
  /** Adds a soft pulsing halo — used for in-flight states. */
  pulse?: boolean
  /** Colour-only variant: drops the leading dot, keeping just the label. */
  hideDot?: boolean
  /** Bordered, tinted pill — the workspace header's "Active" look. */
  pill?: boolean
  className?: string
}

/** Dot + label status indicator used in tables and headers. */
export function StatusChip({
  tone,
  label,
  pulse = false,
  hideDot = false,
  pill = false,
  className,
}: StatusChipProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 text-md font-medium',
        pill && cn('rounded-full border px-3 py-1 text-xs', PILL_TONES[tone]),
        className,
      )}
    >
      <span className={cn('relative inline-flex size-2', hideDot && 'hidden')}>
        <span className={cn('size-2 rounded-full', TONE_DOT_CLASS[tone])} />
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
