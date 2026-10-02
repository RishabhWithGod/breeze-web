import { Globe, LifeBuoy, MessageSquare, type LucideIcon } from 'lucide-react'
import { APP_NAME, FOOTER_LINKS } from '@/constants'
import { cn } from '@/utils'

/** Generic (non-brand) icons — no third-party logos are reproduced. */
const SOCIALS: readonly { label: string; icon: LucideIcon }[] = [
  { label: 'Community', icon: MessageSquare },
  { label: 'Support', icon: LifeBuoy },
  { label: 'Website', icon: Globe },
]

const TAGLINE = 'Every takeoff is reviewed by a person before it reaches a job or an estimate.'

export function Footer({ className }: { className?: string }) {
  return (
    <footer
      className={cn(
        'mt-12 rounded-card border border-hairline glass px-5 py-4 sm:px-6',
        className,
      )}
    >
      <p className="text-sm font-semibold text-white">{APP_NAME}</p>
      <p className="mt-0.5 text-xs text-white/80">{TAGLINE}</p>

      <div className="mt-3 flex items-center justify-between gap-4 border-t border-hairline pt-3">
        <nav aria-label="Footer" className="min-w-0">
          <ul className="flex flex-wrap items-center gap-x-5 gap-y-1">
            {FOOTER_LINKS.map((link) => (
              <li key={link.label}>
                <a
                  href={link.href}
                  className="text-xs whitespace-nowrap text-white/90 transition-colors hover:text-brand"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>

        <ul className="flex shrink-0 items-center gap-2">
          {SOCIALS.map(({ label, icon: Icon }) => (
            <li key={label}>
              <a
                href="#"
                aria-label={label}
                className="grid size-8 place-items-center rounded-full border border-hairline text-white/90 transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-brand"
              >
                <Icon size={15} aria-hidden />
              </a>
            </li>
          ))}
        </ul>
      </div>
    </footer>
  )
}
