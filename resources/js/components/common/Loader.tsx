import { Loader2 } from 'lucide-react'
import { cn } from '@/utils'

const SIZES = {
  sm: { icon: 16, text: 'text-sm' },
  md: { icon: 24, text: 'text-md' },
  lg: { icon: 38, text: 'text-base' },
} as const

export interface LoaderProps {
  size?: keyof typeof SIZES
  label?: string
  /** Fills the parent and centres the spinner. */
  fullHeight?: boolean
  className?: string
}

export function Loader({
  size = 'md',
  label,
  fullHeight = false,
  className,
}: LoaderProps) {
  const config = SIZES[size]

  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        'flex flex-col items-center justify-center gap-3 text-white/70',
        fullHeight && 'min-h-64 w-full',
        className,
      )}
    >
      <Loader2 size={config.icon} className="animate-spin text-brand" aria-hidden />
      {label && <p className={config.text}>{label}</p>}
      <span className="sr-only">Loading</span>
    </div>
  )
}

/** Three-dot bouncing indicator for inline, low-emphasis waiting states. */
export function DotsLoader({ className }: { className?: string }) {
  return (
    <span className={cn('inline-flex items-center gap-1', className)} aria-hidden>
      <span className="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:-0.3s]" />
      <span className="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:-0.15s]" />
      <span className="size-1.5 animate-bounce rounded-full bg-brand" />
    </span>
  )
}
