import { Eye, EyeOff, Layers } from 'lucide-react'
import { categoryKey, cn, symbolLabel } from '@/utils'
import type { SymbolColor } from '@/utils'

export interface ThreeDLayers {
  drawing: boolean
  aiSymbols: boolean
  approved: boolean
  rejected: boolean
  manual: boolean
  labels: boolean
  grid: boolean
}

export interface ThreeDLegendProps {
  /** Category name → how many of it are on the active page, rejected excluded. */
  counts: ReadonlyMap<string, number>
  /** Display names, in the order they should be listed. */
  names: readonly string[]
  colors: ReadonlyMap<string, SymbolColor>
  /** Categories the viewer is currently hiding. Nothing is deleted by hiding. */
  hidden: ReadonlySet<string>
  onToggleCategory: (key: string) => void
  layers: ThreeDLayers
  onToggleLayer: (layer: keyof ThreeDLayers) => void
}

const LAYER_LABELS: Record<keyof ThreeDLayers, string> = {
  drawing: 'Drawing',
  aiSymbols: 'AI symbols',
  approved: 'Approved',
  rejected: 'Rejected',
  manual: 'Manual',
  labels: 'Labels',
  grid: 'Grid',
}

/**
 * What is on the drawing, and what is being shown of it.
 *
 * Every control here is a view filter and nothing else — hiding a category or a
 * layer changes what is drawn and never what is stored. That is worth saying in
 * the UI too, which is why the panel says "view only".
 */
export function ThreeDLegend({
  counts,
  names,
  colors,
  hidden,
  onToggleCategory,
  layers,
  onToggleLayer,
}: ThreeDLegendProps) {
  return (
    <div className="flex flex-col gap-4">
      {/* ----------------------------------------------------- layers ---- */}
      <div>
        <p className="mb-2 flex items-center gap-2 text-2xs tracking-wide text-white/70 uppercase">
          <Layers size={13} aria-hidden className="text-brand" />
          Layers · view only
        </p>
        <div className="flex flex-wrap gap-1.5">
          {(Object.keys(LAYER_LABELS) as (keyof ThreeDLayers)[]).map((layer) => (
            <button
              key={layer}
              type="button"
              onClick={() => onToggleLayer(layer)}
              aria-pressed={layers[layer]}
              className={cn(
                'rounded-full border px-2.5 py-1 text-2xs transition-colors',
                layers[layer]
                  ? 'border-brand/50 bg-brand/15 text-white'
                  : 'border-hairline bg-white/4 text-white/50',
              )}
            >
              {LAYER_LABELS[layer]}
            </button>
          ))}
        </div>
      </div>

      {/* ----------------------------------------------------- legend ---- */}
      <div className="min-h-0 flex-1">
        <p className="mb-2 text-2xs tracking-wide text-white/70 uppercase">
          Legend ({names.length})
        </p>

        {names.length === 0 ? (
          <p className="text-2xs text-white/65">No symbols on this page.</p>
        ) : (
          <ul className="flex max-h-72 flex-col gap-1 overflow-y-auto pr-1">
            {names.map((name) => {
              const key = categoryKey(name)
              const color = colors.get(key)
              const isHidden = hidden.has(key)

              return (
                <li key={key}>
                  <button
                    type="button"
                    onClick={() => onToggleCategory(key)}
                    aria-pressed={!isHidden}
                    className={cn(
                      'flex w-full items-center gap-2 rounded-panel px-1.5 py-1 text-left text-2xs transition-colors',
                      'hover:bg-white/10',
                      isHidden ? 'text-white/40' : 'text-white/90',
                    )}
                  >
                    <span
                      aria-hidden
                      className="size-3 shrink-0 rounded-sm border"
                      style={{
                        backgroundColor: isHidden ? 'transparent' : color?.fill,
                        borderColor: color?.border,
                      }}
                    />
                    <span className="min-w-0 flex-1 truncate" title={name}>
                      {symbolLabel(name)}
                    </span>
                    <span className="shrink-0 tabular-nums text-white/60">
                      {counts.get(key) ?? 0}
                    </span>
                    {isHidden ? (
                      <EyeOff size={12} aria-hidden className="shrink-0 text-white/40" />
                    ) : (
                      <Eye size={12} aria-hidden className="shrink-0 text-white/40" />
                    )}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </div>
  )
}
