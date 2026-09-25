import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import type { OverlaySymbol } from '@/types'
import { categoryKey, cn, symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'

/** Keeps the card at a fixed height regardless of how many symbols the drawing has. */
const PAGE_SIZE = 15

export interface SymbolLegendProps {
  symbols: readonly OverlaySymbol[]
  /** Counts, by name, of occurrences actually on the active page — what the legend's "(24)" reports. */
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
}

/** Name → color key for every symbol actually drawn on the overlay. Clicking a row filters the drawing to that category. */
export function SymbolLegend({
  symbols,
  countsByName,
  activeCategory,
  onSelectCategory,
  hoveredCategory = null,
  colors,
}: SymbolLegendProps) {
  const names = useMemo(
    () => [...new Set(symbols.map((symbol) => symbol.name))].sort((a, b) => a.localeCompare(b)),
    [symbols],
  )

  const pageCount = Math.max(1, Math.ceil(names.length / PAGE_SIZE))
  const [page, setPage] = useState(1)

  // Back to page 1 whenever the underlying list changes shape — paging the
  // drawing, or switching a filter, must never leave this stranded on a page
  // that no longer exists.
  useEffect(() => {
    setPage(1)
  }, [names])

  if (names.length === 0) {
    return <p className="text-2xs text-white/65">No symbols on this page yet.</p>
  }

  const safePage = Math.min(page, pageCount)
  const visible = names.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE)

  return (
    <div className="flex flex-col gap-2">
      <ul className="flex flex-col gap-1 overflow-y-auto pr-1">
        {visible.map((name) => {
          /*
           * Compared on the normalised key, never on the raw name. Engine names
           * arrive in every case, so `"Wall Mount" === "wall mount"` is false
           * and the row that lit up under the pointer was whichever one happened
           * to match exactly — often not the one being hovered.
           */
          const key = categoryKey(name)
          const color = colors.get(key)
          const isActive = activeCategory !== null && categoryKey(activeCategory) === key
          const isHovered = !isActive
            && hoveredCategory !== null
            && categoryKey(hoveredCategory) === key

          return (
            <li key={name}>
              <button
                type="button"
                onClick={() => onSelectCategory(isActive ? null : name)}
                aria-pressed={isActive}
                className={cn(
                  'flex w-full items-center gap-2 rounded-panel px-1.5 py-1 text-left text-2xs text-white/90 transition-colors duration-150',
                  'hover:bg-white/10',
                  isActive && 'bg-white/15 ring-1 ring-white/40',
                  isHovered && 'bg-brand/30 ring-1 ring-brand/60',
                )}
              >
                <span
                  className="size-3 shrink-0 rounded-sm border"
                  style={{ backgroundColor: color?.fill, borderColor: color?.border }}
                  aria-hidden
                />
                <span className="min-w-0 flex-1 truncate" title={name}>
                  {symbolLabel(name)}
                </span>
                <span className="shrink-0 text-white/60">{countsByName.get(key) ?? 0}</span>
              </button>
            </li>
          )
        })}
      </ul>

      {pageCount > 1 && (
        <div className="flex items-center justify-between gap-2 border-t border-hairline pt-2 text-2xs text-white/65">
          <button
            type="button"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={safePage <= 1}
            aria-label="Previous symbols"
            className="grid size-6 shrink-0 place-items-center rounded-panel border border-hairline text-white transition-colors hover:border-brand/60 hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <ChevronLeft size={14} aria-hidden />
          </button>

          <span>
            {(safePage - 1) * PAGE_SIZE + 1}–{Math.min(safePage * PAGE_SIZE, names.length)} of {names.length}
          </span>

          <button
            type="button"
            onClick={() => setPage((current) => Math.min(pageCount, current + 1))}
            disabled={safePage >= pageCount}
            aria-label="Next symbols"
            className="grid size-6 shrink-0 place-items-center rounded-panel border border-hairline text-white transition-colors hover:border-brand/60 hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <ChevronRight size={14} aria-hidden />
          </button>
        </div>
      )}
    </div>
  )
}
