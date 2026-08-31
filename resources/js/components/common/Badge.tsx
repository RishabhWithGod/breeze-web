import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import type { Tone } from '@/types'
import { cn } from '@/utils'

const TONES: Record<Tone, string> = {
  brand: 'bg-brand/15 text-brand border-brand/40',
  success: 'bg-status-success/12 text-status-success border-status-success/40',
  warning: 'bg-status-warning/15 text-status-warning border-status-warning/40',
  danger: 'bg-status-danger/20 text-red-300 border-status-danger/50',
  info: 'bg-status-info/15 text-status-info border-status-info/40',
  neutral: 'bg-white/10 text-white border-hairline-strong',
}

export interface BadgeProps {
  tone?: Tone
  icon?: LucideIcon
  size?: 'sm' | 'md'
  className?: string
  children: ReactNode
}

/** Compact label for counts, categories and metadata. */
export function Badge({
  tone = 'neutral',
  icon: Icon,
  size = 'md',
  className,
  children,
}: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border font-medium whitespace-nowrap',
        size === 'sm' ? 'px-2 py-0.5 text-2xs' : 'px-3 py-1 text-xs',
        TONES[tone],
        className,
      )}
    >
      {Icon && <Icon size={size === 'sm' ? 11 : 13} aria-hidden />}
      {children}
    </span>
  )
}
