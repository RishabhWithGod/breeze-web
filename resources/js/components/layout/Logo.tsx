import { Link } from '@inertiajs/react'
import breezeIcon from '@/assets/breeze-icon.png'
import breezeLogoFull from '@/assets/breeze-logo-full.png'
import { APP_NAME, ROUTES } from '@/constants'
import { cn } from '@/utils'

export interface LogoProps {
  /** Shows the icon alone, without the full lockup (used on narrow screens). */
  compact?: boolean
  className?: string
}

/** The brand mark: the full lockup, or just the icon on the narrowest screens. */
export function Logo({ compact = false, className }: LogoProps) {
  return (
    <Link href={ROUTES.home} aria-label={APP_NAME} className={cn('inline-flex items-center', className)}>
      {compact ? (
        <span className="relative grid size-10 shrink-0 place-items-center rounded-panel grad-midnight ring-1 ring-brand/40 transition-shadow duration-300 hover:shadow-glow">
          <img src={breezeIcon} alt={APP_NAME} className="size-7 object-contain" />
        </span>
      ) : (
        <img src={breezeLogoFull} alt={APP_NAME} className="h-9 w-auto object-contain" />
      )}
    </Link>
  )
}
