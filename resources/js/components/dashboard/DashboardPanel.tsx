import type { ReactNode } from 'react'
import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { ChevronRight } from 'lucide-react'
import { MOTION } from '@/constants'
import { cn } from '@/utils'

export interface DashboardPanelProps {
  title: string
  subtitle?: string
  /** Right-aligned "View all …" link rendered in the header band. */
  link?: { label: string; href: string }
  /** Custom header controls; takes precedence over `link`. */
  actions?: ReactNode
  /** Entrance delay index. */
  index?: number
  className?: string
  bodyClassName?: string
  children: ReactNode
}

/**
 * Glass panel with a tinted header band — the dashboard's primary container,
 * mirroring the reference product's card chrome.
 */
export function DashboardPanel({
  title,
  subtitle,
  link,
  actions,
  index = 0,
  className,
  bodyClassName,
  children,
}: DashboardPanelProps) {
  return (
    <motion.section
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{
        duration: MOTION.slow,
        delay: index * MOTION.stagger,
        ease: [0.22, 1, 0.36, 1],
      }}
      className={cn(
        'flex min-w-0 flex-col overflow-hidden rounded-card border border-hairline',
        'glass shadow-panel',
        className,
      )}
    >
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-hairline grad-ocean-soft px-5 py-4 sm:px-6">
        <div className="min-w-0">
          <h2 className="text-lg font-semibold text-white sm:text-xl">{title}</h2>
          {subtitle && <p className="mt-0.5 text-sm text-white/85">{subtitle}</p>}
        </div>

        {actions ??
          (link && (
            <Link
              href={link.href}
              className="group inline-flex shrink-0 items-center gap-1 text-sm font-medium text-white transition-colors hover:text-brand"
            >
              {link.label}
              <ChevronRight
                size={15}
                aria-hidden
                className="transition-transform group-hover:translate-x-0.5"
              />
            </Link>
          ))}
      </header>

      <div className={cn('flex-1 p-5 sm:p-6', bodyClassName)}>{children}</div>
    </motion.section>
  )
}
