import { Globe, LifeBuoy, MessageSquare, type LucideIcon } from 'lucide-react'
import { APP_NAME, FOOTER_LINKS } from '@/constants'
import { cn } from '@/utils'

/** Generic (non-brand) icons — no third-party logos are reproduced. */
const SOCIALS: readonly { label: string; icon: LucideIcon }[] = [
  { label: 'Community', icon: MessageSquare },
  { label: 'Support', icon: LifeBuoy },
  { label: 'Website', icon: Globe },
]

export function Footer({ className }: { className?: string }) {
  const year = new Date().getFullYear()

  return (
    <footer
      className={cn(
        'mt-12 rounded-card border border-hairline glass px-6 py-8 sm:px-8',
        className,
      )}
    >
      <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p className="text-md font-semibold text-white">
            {APP_NAME} — AI Electrical Takeoff
          </p>
          <p className="mt-1 text-sm text-white/50">
            © {year} {APP_NAME}. Every takeoff is reviewed by a person before it
            reaches a job or an estimate.
          </p>
        </div>

        <nav aria-label="Footer">
          <ul className="flex flex-wrap items-center gap-x-6 gap-y-2">
            {FOOTER_LINKS.map((link) => (
              <li key={link.label}>
                <a
                  href={link.href}
                  className="text-sm text-white/65 transition-colors hover:text-brand"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>

        <ul className="flex items-center gap-2">
          {SOCIALS.map(({ label, icon: Icon }) => (
            <li key={label}>
              <a
                href="#"
                aria-label={label}
                className="grid size-9 place-items-center rounded-full border border-hairline text-white/70 transition-colors hover:border-brand/60 hover:bg-white/10 hover:text-brand"
              >
                <Icon size={16} aria-hidden />
              </a>
            </li>
          ))}
        </ul>
      </div>
    </footer>
  )
}
