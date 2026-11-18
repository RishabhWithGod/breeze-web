import type { Size, Variant } from '@/types'
import { cn } from '@/utils'

const BASE =
  'inline-flex items-center justify-center gap-2 font-medium whitespace-nowrap rounded-panel border transition-all duration-200 ' +
  'select-none disabled:cursor-not-allowed disabled:opacity-50 active:translate-y-px'

const VARIANTS: Record<Variant, string> = {
  /** Cyan call-to-action — inverts to white on hover, mirroring the reference. */
  primary:
    'bg-brand border-brand text-brand-ink shadow-panel [background-image:var(--brand-grad)] hover:brightness-110 hover:shadow-glow',
  /** Solid blue call-to-action — the Add Task / Upload buttons of the dark screens. */
  blue:
    'bg-brand border-brand text-brand-ink shadow-panel [background-image:var(--brand-grad)] hover:brightness-110',
  /** Purple accent — matches the Takeoffs cards on the project workspace. */
  purple:
    'bg-status-purple/12 border-status-purple/50 text-status-purple hover:bg-status-purple hover:border-status-purple hover:text-brand-ink',
  /** Frosted secondary action. */
  secondary:
    'glass-strong border-hairline-strong text-white hover:bg-surface-glass-hover hover:border-brand/60 hover:text-white',
  /** Midnight gradient — the reference `btn-dark`. */
  dark: 'grad-midnight border-navy-800 text-brand-ink hover:bg-brand hover:bg-none hover:border-brand hover:text-brand-ink',
  white:
    'bg-surface-glass border-hairline-strong text-white hover:bg-brand hover:border-brand hover:text-brand-ink',
  outline:
    'bg-transparent border-brand/70 text-brand hover:bg-brand hover:text-brand-ink',
  ghost:
    'bg-transparent border-transparent text-white/80 hover:bg-white/10 hover:text-white',
  danger:
    'bg-status-danger/90 border-status-danger text-brand-ink hover:bg-status-danger hover:shadow-panel',
}

const SIZES: Record<Size, string> = {
  sm: 'text-sm px-2.5 py-1.5', // 30px, as the app's .btn-sm
  md: 'text-md px-3.5 py-2', // 36px, as the app's .btn
  lg: 'text-base px-5 py-3', // 44px, as the app's .btn-lg
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
  sm: { box: 'size-[30px]', icon: 15 },
  md: { box: 'size-9', icon: 17 },
  lg: { box: 'size-11', icon: 20 },
}
