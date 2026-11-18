import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import type { Tone } from '@/types'
import { cn } from '@/utils'

/** Tinted pill, the Breeze app's badge: soft fill, darker same-hue text, no border. */
const TONES: Record<Tone, string> = {
  brand: 'bg-[var(--brand-bg)] text-brand',
  success: 'bg-[var(--ok-bg)] text-[var(--ok-text)]',
  warning: 'bg-[var(--warn-bg)] text-[var(--warn-text)]',
  danger: 'bg-[var(--danger-bg)] text-[var(--danger-text)]',
  info: 'bg-[var(--info-bg)] text-[var(--info-text)]',
  purple: 'bg-[var(--violet-bg)] text-[var(--violet-text)]',
  blue: 'bg-[var(--brand-bg)] text-brand',
  neutral: 'bg-[var(--gray-bg)] text-[var(--gray-text)]',
}

export interface BadgeProps {
  tone?: Tone
  icon?: LucideIcon
  size?: 'sm' | 'md'
  className?: string
  children: ReactNode
}

/** Compact label for counts, categories and statuses. A leading dot marks it as a status unless it carries an icon. */
export function Badge({ tone = 'neutral', icon: Icon, size = 'md', className, children }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-[5px] rounded-full font-[550] whitespace-nowrap',
        size === 'sm' ? 'h-[19px] px-1.5 text-[11px]' : 'h-[23px] px-[9px] text-[12.5px]',
        TONES[tone],
        !Icon && "before:size-1.5 before:shrink-0 before:rounded-full before:bg-current before:opacity-80 before:content-['']",
        className,
      )}
    >
      {Icon && <Icon size={size === 'sm' ? 11 : 13} aria-hidden />}
      {children}
    </span>
  )
}
