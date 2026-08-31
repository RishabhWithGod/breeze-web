import type { Size, Variant } from '@/types'
import { cn } from '@/utils'

const BASE =
  'inline-flex items-center justify-center gap-2 font-medium whitespace-nowrap rounded-panel border transition-all duration-200 ' +
  'select-none disabled:cursor-not-allowed disabled:opacity-50 active:translate-y-px'

const VARIANTS: Record<Variant, string> = {
  /** Cyan call-to-action — inverts to white on hover, mirroring the reference. */
  primary:
    'bg-brand border-brand text-brand-ink shadow-panel hover:bg-white hover:border-brand hover:text-brand-ink hover:shadow-glow',
  /** Frosted secondary action. */
  secondary:
    'glass-strong border-hairline-strong text-white hover:bg-white/20 hover:border-brand/60 hover:text-white',
  /** Midnight gradient — the reference `btn-dark`. */
  dark: 'grad-midnight border-navy-800 text-white hover:bg-brand hover:bg-none hover:border-brand hover:text-navy-800',
  white:
    'bg-white border-white text-brand-ink hover:bg-brand hover:border-brand hover:text-brand-ink',
  outline:
    'bg-transparent border-brand/70 text-brand hover:bg-brand hover:text-brand-ink',
  ghost:
    'bg-transparent border-transparent text-white/80 hover:bg-white/10 hover:text-white',
  danger:
    'bg-status-danger/90 border-status-danger text-white hover:bg-status-danger hover:shadow-panel',
}

const SIZES: Record<Size, string> = {
  sm: 'text-sm px-3 py-1.5',
  md: 'text-base px-6 py-1.5',
  lg: 'text-base px-8 py-3',
}

export interface ButtonStyleProps {
  variant?: Variant
  size?: Size
  fullWidth?: boolean
  className?: string
}

/** Shared style resolver so links, buttons and icon buttons stay identical. */
export function buttonStyles({
  variant = 'primary',
  size = 'md',
  fullWidth = false,
  className,
}: ButtonStyleProps = {}): string {
  return cn(BASE, VARIANTS[variant], SIZES[size], fullWidth && 'w-full', className)
}

/** Circular icon-only geometry, layered on top of a variant. */
export const ICON_BUTTON_SIZES: Record<Size, { box: string; icon: number }> = {
  sm: { box: 'size-8', icon: 15 },
  md: { box: 'size-10', icon: 18 },
  lg: { box: 'size-12', icon: 22 },
}
