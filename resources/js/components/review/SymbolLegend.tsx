import { useMemo } from 'react'
import type { OverlaySymbol } from '@/types'
import { cn, resolveSymbolColors } from '@/utils'

export interface SymbolLegendProps {
  symbols: readonly OverlaySymbol[]
  /** Counts, by name, of occurrences actually on the active page — what the legend's "(24)" reports. */
  countsByName: ReadonlyMap<string, number>
  activeCategory: string | null
  onSelectCategory: (name: string | null) => void
}

/** Name → color key for every symbol actually drawn on the overlay. Clicking a row filters the drawing to that category. */
export function SymbolLegend({
  symbols,
  countsByName,
  activeCategory,
  onSelectCategory,
}: SymbolLegendProps) {
  const names = useMemo(
    () => [...new Set(symbols.map((symbol) => symbol.name))].sort((a, b) => a.localeCompare(b)),
    [symbols],
  )
  const colors = useMemo(() => resolveSymbolColors(names), [names])

  if (names.length === 0) {
    return <p className="text-2xs text-white/65">No symbols on this page yet.</p>
  }

  return (
    <ul className="flex flex-col gap-1 overflow-y-auto pr-1">
      {names.map((name) => {
        const key = name.trim().toLowerCase()
        const color = colors.get(key)
        const isActive = activeCategory === name

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
              )}
            >
              <span
                className="size-3 shrink-0 rounded-sm border"
                style={{ backgroundColor: color?.fill, borderColor: color?.border }}
                aria-hidden
              />
              <span className="min-w-0 flex-1 truncate" title={name}>
                {name}
              </span>
              <span className="shrink-0 text-white/60">{countsByName.get(key) ?? 0}</span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}
