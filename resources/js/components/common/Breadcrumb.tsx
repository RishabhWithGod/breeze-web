import { Fragment } from 'react'
import { Link } from '@inertiajs/react'
import { ChevronRight, Home } from 'lucide-react'
import { ROUTES } from '@/constants'
import type { BreadcrumbItem } from '@/types'
import { cn } from '@/utils'

export interface BreadcrumbProps {
  items: readonly BreadcrumbItem[]
  /** Prefixes the trail with a home icon linking to the upload page. */
  showHome?: boolean
  className?: string
}

export function Breadcrumb({ items, showHome = false, className }: BreadcrumbProps) {
  return (
    <nav aria-label="Breadcrumb" className={cn('min-w-0', className)}>
      <ol className="flex flex-wrap items-center gap-1.5 text-[13px] text-[var(--text-3)]">
        {showHome && (
          <li className="flex items-center gap-1.5">
            <Link
              href={ROUTES.home}
              className="inline-flex items-center gap-1.5 transition-colors hover:text-brand"
            >
              <Home size={14} aria-hidden />
              <span className="sr-only">Home</span>
            </Link>
            <ChevronRight size={13} aria-hidden />
          </li>
        )}

        {items.map((item, index) => {
          const isLast = index === items.length - 1

          return (
            <Fragment key={`${item.label}-${index}`}>
              <li>
                {item.href && !isLast ? (
                  <Link
                    href={item.href}
                    className="transition-colors hover:text-brand"
                  >
                    {item.label}
                  </Link>
                ) : (
                  <span
                    className={cn()}
                    aria-current={isLast ? 'page' : undefined}
                  >
                    {item.label}
                  </span>
                )}
              </li>
              {!isLast && (
                <li aria-hidden>
                  <ChevronRight size={13} />
                </li>
              )}
            </Fragment>
          )
        })}
      </ol>
    </nav>
  )
}
