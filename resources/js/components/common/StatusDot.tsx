import type { Tone } from '@/types'
import { TONE_DOT_CLASS, cn } from '@/utils'

export interface StatusDotProps {
  tone: Tone
  /** Accessible name — omit only when an adjacent label already states it. */
  label?: string
  /** Soft pulsing halo for in-flight states. */
  pulse?: boolean
  className?: string
}

/** Bare status marker, for when the label lives in another column. */
export function StatusDot({ tone, label, pulse = false, className }: StatusDotProps) {
  return (
    <span
      className={cn('relative inline-flex size-2.5 shrink-0', className)}
      {...(label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': true })}
    >
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
  )
}
