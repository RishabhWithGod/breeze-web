import { Check, ChevronLeft, ChevronRight, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import type { OverlaySymbol } from '@/types'
import { categoryKey, cn, symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'

/** Keeps the panel at a fixed height regardless of how many symbols the drawing has. */
const PAGE_SIZE = 11

export interface SymbolLegendProps {
  symbols: readonly OverlaySymbol[]
  /** Every symbol on the drawing — a category's verdict is read across all of it, not just this page. */
  allSymbols?: readonly OverlaySymbol[]
  /** Narrows the list by name. */
  query?: string
  /** Counts, by name, of occurrences actually on the active page — what the legend's count column reports. */
  countsByName: ReadonlyMap<string, number>
  activeCategory: string | null
  onSelectCategory: (name: string | null) => void
  /** The marker currently under the pointer on the drawing — highlights the matching row without changing the click-to-filter selection. */
  hoveredCategory?: string | null
  /**
   * Every category's colour, resolved once across the whole drawing.
   *
   * Passed in rather than resolved here. This component only ever sees one
   * page's symbols, and resolving a subset is a different assignment — which
   * is how a symbol used to be one colour on the drawing and another in this
   * list, and how its colour changed as you paged through the PDF.
   */
  colors: ReadonlyMap<string, SymbolColor>
  /** Approve / reject a whole category; `reset` clears a verdict already given. */
  onReview?: (name: string, action: 'approve' | 'reject' | 'reset') => void
}

type Verdict = 'approved' | 'rejected' | 'mixed'

/** Name → colour, description, count and verdict. Clicking a row filters the drawing to that category. */
export function SymbolLegend({
  symbols,
  allSymbols,
  query = '',
  countsByName,
  activeCategory,
  onSelectCategory,
  hoveredCategory = null,
  colors,
  onReview,
}: SymbolLegendProps) {
  const names = useMemo(() => {
    const needle = query.trim().toLowerCase()

    return [...new Set(symbols.map((symbol) => symbol.name))]
      .filter((name) => needle === '' || symbolLabel(name).toLowerCase().includes(needle))
      .sort((a, b) => a.localeCompare(b))
  }, [symbols, query])

  const verdicts = useMemo(() => {
    const byKey = new Map<string, Set<string>>()

    for (const symbol of allSymbols ?? symbols) {
      const key = categoryKey(symbol.name)
      byKey.set(key, (byKey.get(key) ?? new Set()).add(symbol.status))
    }

    const result = new Map<string, Verdict>()
    for (const [key, statuses] of byKey) {
      result.set(
        key,
        statuses.size === 1 && statuses.has('approved')
          ? 'approved'
          : statuses.size === 1 && statuses.has('rejected')
            ? 'rejected'
            : 'mixed',
      )
    }

    return result
  }, [allSymbols, symbols])

  const pageCount = Math.max(1, Math.ceil(names.length / PAGE_SIZE))
  const [page, setPage] = useState(1)

  // Back to page 1 whenever the underlying list changes shape — paging the
  // drawing, or switching a filter, must never leave this stranded on a page
  // that no longer exists.
  useEffect(() => {
    setPage(1)
  }, [names])

  if (names.length === 0) {
    return (
      <p className="px-4 pb-4 text-xs text-white/65">
        {query.trim() ? 'No symbols match your search.' : 'No symbols on this page yet.'}
      </p>
    )
  }

  const safePage = Math.min(page, pageCount)
  const visible = names.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE)

  return (
    <div>
      <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem_5.5rem] items-center gap-1.5 bg-ocean-700/40 px-3 py-2 text-xs font-semibold text-white">
        <span>Symbol</span>
        <span>Description</span>
        <span className="text-right">Count</span>
        <span className="text-center">Review</span>
      </div>

      <ul>
        {visible.map((name) => {
          /*
           * Compared on the normalised key, never on the raw name. Engine names
           * arrive in every case, so `"Wall Mount" === "wall mount"` is false
           * and the row that lit up under the pointer was whichever one happened
           * to match exactly — often not the one being hovered.
           */
          const key = categoryKey(name)
          const color = colors.get(key)
          const verdict = verdicts.get(key)
          const isActive = activeCategory !== null && categoryKey(activeCategory) === key
          const isHovered = !isActive
            && hoveredCategory !== null
            && categoryKey(hoveredCategory) === key

          return (
            <li
              key={name}
              className={cn(
                'grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem_5.5rem] items-center gap-1.5 border-b border-hairline px-3 py-1.5 transition-colors last:border-b-0',
                'hover:bg-white/6',
                isActive && 'bg-white/12 ring-1 ring-inset ring-white/30',
                isHovered && 'bg-brand/20 ring-1 ring-inset ring-brand/50',
              )}
            >
              <button
                type="button"
                onClick={() => onSelectCategory(isActive ? null : name)}
                aria-pressed={isActive}
                aria-label={`Show ${symbolLabel(name)} on the drawing`}
                className="grid size-8 place-items-center rounded-panel"
              >
                <span
                  className="size-4 rounded-sm border-2"
                  style={{ backgroundColor: color?.fill, borderColor: color?.border }}
                  aria-hidden
                />
              </button>

              <button
                type="button"
                onClick={() => onSelectCategory(isActive ? null : name)}
                className="min-w-0 truncate text-left text-sm text-white"
                title={name}
              >
                {symbolLabel(name)}
              </button>

              <span className="text-right text-sm text-white/90">{countsByName.get(key) ?? 0}</span>

              <div className="flex items-center justify-end gap-1.5">
                <button
                  type="button"
                  aria-label={`Approve ${symbolLabel(name)}`}
                  aria-pressed={verdict === 'approved'}
                  disabled={!onReview}
                  onClick={() => onReview?.(name, verdict === 'approved' ? 'reset' : 'approve')}
                  className={cn(
                    'grid size-6 place-items-center rounded-panel transition-all',
                    verdict === 'approved'
                      ? 'bg-emerald-400 text-navy-950 shadow-panel'
                      : 'bg-emerald-400/25 text-emerald-200 hover:bg-emerald-400 hover:text-navy-950',
                  )}
                >
                  <Check size={13} strokeWidth={3} aria-hidden />
                </button>
                <button
                  type="button"
                  aria-label={`Reject ${symbolLabel(name)}`}
                  aria-pressed={verdict === 'rejected'}
                  disabled={!onReview}
                  onClick={() => onReview?.(name, verdict === 'rejected' ? 'reset' : 'reject')}
                  className={cn(
                    'grid size-6 place-items-center rounded-panel transition-all',
                    verdict === 'rejected'
                      ? 'bg-red-500 text-white shadow-panel'
                      : 'bg-red-500/25 text-red-200 hover:bg-red-500 hover:text-white',
                  )}
                >
                  <X size={13} strokeWidth={3} aria-hidden />
                </button>
              </div>
            </li>
          )
        })}
      </ul>

      {pageCount > 1 && (
        <div className="flex items-center justify-between gap-2 border-t border-hairline px-4 py-3 text-xs text-white/75">
          <button
            type="button"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={safePage <= 1}
            aria-label="Previous symbols"
            className="grid size-7 shrink-0 place-items-center rounded-panel border border-hairline text-white transition-colors hover:border-brand/60 hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <ChevronLeft size={15} aria-hidden />
          </button>

          <span>
            {(safePage - 1) * PAGE_SIZE + 1}–{Math.min(safePage * PAGE_SIZE, names.length)} of {names.length}
          </span>

          <button
            type="button"
            onClick={() => setPage((current) => Math.min(pageCount, current + 1))}
            disabled={safePage >= pageCount}
            aria-label="Next symbols"
            className="grid size-7 shrink-0 place-items-center rounded-panel border border-hairline text-white transition-colors hover:border-brand/60 hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <ChevronRight size={15} aria-hidden />
          </button>
        </div>
      )}
    </div>
  )
}
