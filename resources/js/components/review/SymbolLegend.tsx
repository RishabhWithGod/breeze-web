import { useMemo } from 'react'
import type { OverlaySymbol } from '@/types'
import { categoryKey, cn, symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'

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
  if (names.length === 0) {
    return <p className="text-2xs text-white/65">No symbols on this page yet.</p>
  }

  return (
    <ul className="flex flex-col gap-1 overflow-y-auto pr-1">
      {names.map((name) => {
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
  )
}
