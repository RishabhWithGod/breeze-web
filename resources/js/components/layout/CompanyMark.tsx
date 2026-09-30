import { useState } from 'react'
import { Building2 } from 'lucide-react'
import { cn } from '@/utils'

export interface CompanyMarkProps {
  /** The uploaded logo, when there is one. */
  logoUrl: string | null | undefined
  name: string
  /** Side of the square, in pixels. */
  size?: number
  className?: string
}

/**
 * A company's logo where one is shown — its uploaded logo when there is one, a
 * building icon when there is not (or when the file cannot be loaded).
 */
export function CompanyMark({ logoUrl, name, size = 40, className }: CompanyMarkProps) {
  // Remembers the address that failed, so a new upload gets a fresh chance.
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const showLogo = Boolean(logoUrl) && failedUrl !== logoUrl

  return (
    <span
      style={{ width: size, height: size }}
      className={cn(
        'grid shrink-0 place-items-center overflow-hidden rounded-panel border border-hairline',
        showLogo ? 'bg-white/90' : 'bg-white/8 text-white/85',
        className,
      )}
    >
      {showLogo ? (
        <img
          src={logoUrl ?? undefined}
          alt={`${name} logo`}
          onError={() => setFailedUrl(logoUrl ?? null)}
          className="size-full object-contain p-0.5"
        />
      ) : (
        <Building2 size={Math.round(size * 0.55)} aria-label={`${name} — no logo uploaded`} role="img" />
      )}
    </span>
  )
}
