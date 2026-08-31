import type { LucideIcon } from 'lucide-react'
import type { Tone } from '@/types'
import { cn } from '@/utils'

const SIZES = {
  sm: { box: 'size-9', icon: 16 },
  md: { box: 'size-12', icon: 20 },
  lg: { box: 'size-16', icon: 26 },
  xl: { box: 'size-24', icon: 40 },
} as const

const TONES: Record<Tone, string> = {
  brand: 'bg-brand/15 text-brand ring-brand/30',
  success: 'bg-status-success/12 text-status-success ring-status-success/30',
  warning: 'bg-status-warning/15 text-status-warning ring-status-warning/30',
  danger: 'bg-status-danger/18 text-red-300 ring-status-danger/35',
  info: 'bg-status-info/15 text-status-info ring-status-info/30',
  neutral: 'bg-white/10 text-white ring-hairline-strong',
}

export interface IconBubbleProps {
  icon: LucideIcon
  tone?: Tone
  size?: keyof typeof SIZES
  className?: string
}

/** Circular icon container used across cards, lists and empty states. */
export function IconBubble({
  icon: Icon,
  tone = 'brand',
  size = 'md',
  className,
}: IconBubbleProps) {
  const config = SIZES[size]

  return (
    <span
      className={cn(
        'inline-grid shrink-0 place-items-center rounded-full ring-1',
        config.box,
        TONES[tone],
        className,
      )}
    >
      <Icon size={config.icon} aria-hidden />
    </span>
  )
}
