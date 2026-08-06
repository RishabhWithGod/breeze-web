import { Link } from '@inertiajs/react'
import { APP_NAME, APP_TAGLINE, ROUTES } from '@/constants'
import { cn } from '@/utils'

export interface LogoProps {
  /** Hides the wordmark, leaving only the mark (used on narrow screens). */
  compact?: boolean
  className?: string
}

/**
 * Logo placeholder — an original inline SVG mark plus wordmark. No external
 * brand asset is used.
 */
export function Logo({ compact = false, className }: LogoProps) {
  return (
    <Link
      href={ROUTES.home}
      aria-label={`${APP_NAME} — ${APP_TAGLINE}`}
      className={cn('group inline-flex items-center gap-3', className)}
    >
      <span className="relative grid size-10 shrink-0 place-items-center rounded-panel grad-midnight ring-1 ring-brand/40 transition-shadow duration-300 group-hover:shadow-glow">
        <svg viewBox="0 0 24 24" className="size-6" aria-hidden>
          <path
            d="M13.4 2 4.8 13.1h5.3L9.1 22l9.4-11.6h-5.6L13.4 2Z"
            className="fill-brand"
          />
          <path
            d="M13.4 2 9.1 22l9.4-11.6h-5.6L13.4 2Z"
            className="fill-brand-soft opacity-70"
          />
        </svg>
      </span>

      {/* The wordmark is dropped on the narrowest screens to protect the
          header layout; the mark alone still identifies the product. */}
      {!compact && (
        <span className="hidden min-w-0 flex-col leading-tight sm:flex">
          <span className="text-lg font-black tracking-wide text-white">
            {APP_NAME}
          </span>
          <span className="truncate text-2xs tracking-[0.18em] text-brand uppercase">
            {APP_TAGLINE}
          </span>
        </span>
      )}
    </Link>
  )
}
