import { ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/utils'

export interface PaginationProps {
  page: number
  pageCount: number
  onPageChange: (page: number) => void
  /** e.g. "Showing 1–5 of 12 projects" */
  summary?: string
  /** Renders "Previous"/"Next" text buttons instead of chevron-only controls. */
  withLabels?: boolean
  /**
   * `light` renders the reference product's joined white control group
   * (blue labels, solid blue current page); `default` is the dark pill set.
   */
  tone?: 'default' | 'light'
  className?: string
}

/** Builds a page list with ellipses: 1 … 4 5 6 … 12 */
function buildPages(page: number, pageCount: number): (number | 'gap')[] {
  if (pageCount <= 7) {
    return Array.from({ length: pageCount }, (_, index) => index + 1)
  }

  const pages = new Set<number>([1, pageCount, page, page - 1, page + 1])
  const visible = [...pages]
    .filter((value) => value >= 1 && value <= pageCount)
    .sort((a, b) => a - b)

  return visible.flatMap((value, index) => {
    const previous = visible[index - 1]
    return previous !== undefined && value - previous > 1
      ? (['gap', value] as (number | 'gap')[])
      : [value]
  })
}

export function Pagination({
  page,
  pageCount,
  onPageChange,
  summary,
  withLabels = false,
  tone = 'default',
  className,
}: PaginationProps) {
  if (pageCount <= 1 && !summary) return null

  const isLight = tone === 'light'
  const pages = buildPages(page, pageCount)

  const navButton = cn(
    'inline-flex h-9 items-center justify-center border transition-colors disabled:cursor-not-allowed disabled:opacity-40',
    isLight
      ? 'border-hairline-strong bg-white/90 text-ocean-700 hover:bg-white'
      : 'rounded-panel border-hairline text-white/75 hover:border-brand/60 hover:bg-white/10 hover:text-white',
    withLabels ? 'gap-1 px-3 text-sm font-medium' : 'w-9',
  )

  return (
    <nav
      aria-label="Pagination"
      className={cn(
        'flex flex-col-reverse items-center justify-between gap-4 sm:flex-row',
        className,
      )}
    >
      {summary && <p className="text-sm text-white/55">{summary}</p>}

      <div
        className={cn(
          'flex items-center',
          // Light tone joins the controls into one rounded group.
          isLight
            ? 'overflow-hidden rounded-panel shadow-panel [&>*+*]:-ml-px'
            : 'gap-1.5',
        )}
      >
        <button
          type="button"
          className={navButton}
          onClick={() => onPageChange(page - 1)}
          disabled={page <= 1}
          aria-label="Previous page"
        >
          <ChevronLeft size={17} aria-hidden />
          {withLabels && <span>Previous</span>}
        </button>

        {pages.map((value, index) =>
          value === 'gap' ? (
            <span
              key={`gap-${index}`}
              className="px-1 text-sm text-white/40"
              aria-hidden
            >
              …
            </span>
          ) : (
            <button
              key={value}
              type="button"
              onClick={() => onPageChange(value)}
              aria-current={value === page ? 'page' : undefined}
              className={cn(
                'grid size-9 place-items-center border text-sm font-medium transition-colors',
                !isLight && 'rounded-panel',
                isLight
                  ? value === page
                    ? 'border-ocean-700 bg-ocean-700 text-white'
                    : 'border-hairline-strong bg-white/90 text-ocean-700 hover:bg-white'
                  : value === page
                    ? 'border-brand bg-brand text-brand-ink'
                    : 'border-hairline text-white/75 hover:border-brand/60 hover:bg-white/10 hover:text-white',
              )}
            >
              {value}
            </button>
          ),
        )}

        <button
          type="button"
          className={navButton}
          onClick={() => onPageChange(page + 1)}
          disabled={page >= pageCount}
          aria-label="Next page"
        >
          {withLabels && <span>Next</span>}
          <ChevronRight size={17} aria-hidden />
        </button>
      </div>
    </nav>
  )
}
