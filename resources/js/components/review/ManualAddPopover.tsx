import { useMemo, useState, type CSSProperties } from 'react'
import { Button, TextInput } from '@/components/common'
import { useClickOutside } from '@/hooks'

export interface ManualAddPopoverProps {
  /** Absolute position within the overlay's positioned container. */
  anchorStyle: CSSProperties
  distinctNames: readonly string[]
  onCancel: () => void
  onSave: (name: string) => void
}

/**
 * Floating panel anchored to a freshly-dropped box: names the symbol the AI
 * missed, either by picking an existing name (folds into that card's count)
 * or typing a new one (starts a brand-new card).
 */
export function ManualAddPopover({
  anchorStyle,
  distinctNames,
  onCancel,
  onSave,
}: ManualAddPopoverProps) {
  const [query, setQuery] = useState('')
  const ref = useClickOutside<HTMLDivElement>(onCancel)

  const suggestions = useMemo(() => {
    const term = query.trim().toLowerCase()
    if (term === '') return []

    return distinctNames
      .filter((name) => name.toLowerCase().includes(term) && name.toLowerCase() !== term)
      .slice(0, 6)
  }, [query, distinctNames])

  const save = () => {
    const name = query.trim()
    if (name.length < 2) return
    onSave(name)
  }

  return (
    <div
      ref={ref}
      // Solid rather than the app's usual translucent glass panel — this
      // floats over the drawing image itself, where the translucent style
      // is nearly invisible against a light-colored page.
      className="absolute z-20 w-64 rounded-panel border border-hairline-strong bg-navy-900 p-3 shadow-panel"
      style={anchorStyle}
      onClick={(event) => event.stopPropagation()}
    >
      <form
        className="flex flex-col gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          save()
        }}
      >
        <TextInput
          id="manual-symbol-name"
          label="Symbol name"
          placeholder="e.g. Duplex outlet"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          autoFocus
        />

        {suggestions.length > 0 && (
          <ul className="max-h-32 overflow-y-auto rounded-panel border border-hairline bg-white/5">
            {suggestions.map((name) => (
              <li key={name}>
                <button
                  type="button"
                  className="w-full truncate px-3 py-1.5 text-left text-2xs text-white/90 hover:bg-white/10"
                  onClick={() => setQuery(name)}
                >
                  {name}
                </button>
              </li>
            ))}
          </ul>
        )}

        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={query.trim().length < 2}>
            Add symbol
          </Button>
          <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  )
}
