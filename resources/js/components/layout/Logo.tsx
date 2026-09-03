import { Link } from '@inertiajs/react'
import breezeIcon from '@/assets/breeze-icon.png'
import { APP_NAME, ROUTES } from '@/constants'
import { cn } from '@/utils'

export interface LogoProps {
  /** Hides the wordmark, leaving only the mark (used on narrow screens). */
  compact?: boolean
  className?: string
}

/** The brand mark, plus the "Breeze AI" wordmark except on the narrowest screens. */
export function Logo({ compact = false, className }: LogoProps) {
  return (
    <Link
      href={ROUTES.home}
      aria-label={APP_NAME}
      className={cn('group inline-flex items-center gap-3', className)}
    >
      <span className="relative grid size-10 shrink-0 place-items-center rounded-panel grad-midnight ring-1 ring-brand/40 transition-shadow duration-300 group-hover:shadow-glow">
        <img src={breezeIcon} alt="" className="size-7 object-contain" />
      </span>

      {/* The wordmark is dropped on the narrowest screens to protect the
          header layout; the mark alone still identifies the product. */}
      {!compact && (
        <span className="hidden min-w-0 truncate text-lg font-black tracking-wide text-white sm:block">
          {APP_NAME}
        </span>
      )}
    </Link>
  )
}
